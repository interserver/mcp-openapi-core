<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\OpenApi;

use Symfony\Component\Yaml\Yaml;

/**
 * Turns an OpenAPI 3.x document into {@see ToolDefinition}s.
 *
 * Pure translation: no I/O, no cache, no HTTP. Fetching is {@see SpecFetcher}'s
 * job and caching is {@see ToolCache}'s, so this class can be exercised against
 * an inline array in a unit test without touching a filesystem.
 */
final class OpenApiParser
{
    /**
     * Description budget handed to the model.
     *
     * Claude's clients put the whole tool description in the selection prompt, so
     * this is a real token cost multiplied by the tool count. `scripts/api/
     * check_openapi_descriptions.php` in the API repo enforces an 880-char budget
     * on the source spec precisely so nothing ever reaches this truncation — if
     * descriptions start getting cut here, that check has drifted.
     */
    public const DESCRIPTION_LIMIT = 900;

    /**
     * Below this, a sentence-boundary cut would throw away too much of the
     * budget, so a hard cut with an ellipsis reads better.
     */
    private const SENTENCE_CUT_FLOOR = 700;

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public function __construct(
        private readonly DestructiveClassifier $classifier = new DestructiveClassifier(),
        private readonly bool $includeOutputSchemas = true,
    ) {
    }

    /**
     * @return list<ToolDefinition>
     */
    public function parseYaml(string $yaml): array
    {
        $spec = Yaml::parse($yaml);

        return $this->parseDocument(\is_array($spec) ? $spec : []);
    }

    /**
     * @return list<ToolDefinition>
     */
    public function parseJson(string $json): array
    {
        $spec = json_decode($json, true);

        return $this->parseDocument(\is_array($spec) ? $spec : []);
    }

    /**
     * Accepts either YAML or JSON — both specs this runs against are published in
     * both forms, and a caller holding raw bytes should not have to know which.
     *
     * @return list<ToolDefinition>
     */
    public function parseContent(string $content): array
    {
        $trimmed = ltrim($content);

        return str_starts_with($trimmed, '{') ? $this->parseJson($content) : $this->parseYaml($content);
    }

    /**
     * @param array<string, mixed> $spec
     *
     * @return list<ToolDefinition>
     */
    public function parseDocument(array $spec): array
    {
        $simplifier = new SchemaSimplifier($spec);
        $tools = [];

        foreach (($spec['paths'] ?? []) as $path => $pathItem) {
            if (!\is_array($pathItem)) {
                continue;
            }
            $sharedParams = $pathItem['parameters'] ?? [];

            foreach (self::METHODS as $method) {
                if (!isset($pathItem[$method]) || !\is_array($pathItem[$method])) {
                    continue;
                }
                $tools[] = $this->buildTool((string) $path, $method, $pathItem[$method], $sharedParams, $simplifier);
            }
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $operation
     * @param array<int, mixed>    $sharedParams
     */
    private function buildTool(
        string $path,
        string $httpMethod,
        array $operation,
        array $sharedParams,
        SchemaSimplifier $simplifier,
    ): ToolDefinition {
        $operationId = (string) ($operation['operationId'] ?? $this->generateOperationId($path, $httpMethod));
        $method = strtoupper($httpMethod);
        $tag = !empty($operation['tags'][0]) ? (string) $operation['tags'][0] : '';
        $isDestructive = $this->classifier->isDestructive($method, $path, $operationId);

        $description = $this->buildDescription($operation, $path, $httpMethod, $tag, $isDestructive);

        [$properties, $required, $pathParams, $queryParams] =
            $this->buildParameters(array_merge($sharedParams, $operation['parameters'] ?? []), $simplifier);

        $hasBody = false;
        $bodySchema = $this->extractRequestBodySchema($operation, $simplifier);
        if (null !== $bodySchema) {
            $hasBody = true;
            foreach (($bodySchema['properties'] ?? []) as $propName => $propDef) {
                $properties[(string) $propName] = \is_array($propDef)
                    ? $simplifier->simplify($propDef)
                    : ['type' => 'string'];
            }
            foreach (($bodySchema['required'] ?? []) as $name) {
                $required[] = (string) $name;
            }
        }

        $inputSchema = ['type' => 'object'];
        if ([] !== $properties) {
            $inputSchema['properties'] = $properties;
        }
        if ([] !== $required) {
            $inputSchema['required'] = array_values(array_unique($required));
        }

        return new ToolDefinition(
            name: $operationId,
            description: $description,
            httpMethod: $method,
            path: $path,
            inputSchema: $inputSchema,
            pathParams: $pathParams,
            queryParams: $queryParams,
            hasBody: $hasBody,
            annotations: $this->buildAnnotations($operation, $operationId, $method, $isDestructive),
            outputSchema: $this->includeOutputSchemas
                ? $this->extractOutputSchema($operation, $simplifier)
                : null,
            tag: $tag,
        );
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function buildDescription(
        array $operation,
        string $path,
        string $httpMethod,
        string $tag,
        bool $isDestructive,
    ): string {
        $summary = (string) ($operation['summary'] ?? '');
        $detail = (string) ($operation['description'] ?? '');

        $description = $summary;
        if ('' !== $detail && $detail !== $summary) {
            $description .= '' !== $description ? ' — ' : '';
            $description .= $detail;
        }
        if ('' === $description) {
            $description = strtoupper($httpMethod).' '.$path;
        }

        // Inline markers so a client can route and gate on a tool without parsing
        // the prose. Both are also expressed structurally in the annotations; this
        // is for models that only ever see the description.
        $prefix = '' !== $tag ? '['.$tag.']' : '';
        if ($isDestructive) {
            $prefix .= ('' !== $prefix ? ' ' : '').'[DESTRUCTIVE]';
        }
        if ('' !== $prefix) {
            $description = $prefix.' '.$description;
        }

        return $this->truncate($description);
    }

    private function truncate(string $description): string
    {
        if (mb_strlen($description) <= self::DESCRIPTION_LIMIT) {
            return $description;
        }

        $hard = mb_substr($description, 0, self::DESCRIPTION_LIMIT);
        $cut = max(
            (int) mb_strrpos($hard, '. '),
            (int) mb_strrpos($hard, '? '),
            (int) mb_strrpos($hard, '! '),
            (int) mb_strrpos($hard, "\n\n"),
        );

        return $cut > self::SENTENCE_CUT_FLOOR
            ? mb_substr($description, 0, $cut + 1)
            : mb_substr($description, 0, self::DESCRIPTION_LIMIT - 3).'...';
    }

    /**
     * @param array<int, mixed> $allParams
     *
     * @return array{0: array<string, mixed>, 1: list<string>, 2: list<string>, 3: list<string>}
     */
    private function buildParameters(array $allParams, SchemaSimplifier $simplifier): array
    {
        $properties = [];
        $required = [];
        $pathParams = [];
        $queryParams = [];

        foreach ($allParams as $param) {
            if (!\is_array($param)) {
                continue;
            }
            $param = $simplifier->resolveRef($param);
            $name = (string) ($param['name'] ?? '');
            if ('' === $name) {
                continue;
            }

            $in = $param['in'] ?? 'query';
            // Header and cookie parameters are supplied by the proxy from the
            // caller's credential, never by the model — advertising them would
            // invite it to forge auth material.
            if ('path' !== $in && 'query' !== $in) {
                continue;
            }

            $schema = $param['schema'] ?? ['type' => 'string'];
            $propDef = $simplifier->simplify(\is_array($schema) ? $schema : ['type' => 'string']);
            if (!empty($param['description'])) {
                $propDef['description'] = $param['description'];
            }

            if ('path' === $in) {
                $pathParams[] = $name;
                // A path parameter is structurally required: without it there is
                // no URL to call, whatever the spec says.
                $required[] = $name;
            } else {
                $queryParams[] = $name;
                if (!empty($param['required'])) {
                    $required[] = $name;
                }
            }

            $properties[$name] = $propDef;
        }

        return [$properties, $required, $pathParams, $queryParams];
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>|null
     */
    private function extractRequestBodySchema(array $operation, SchemaSimplifier $simplifier): ?array
    {
        if (!isset($operation['requestBody']) || !\is_array($operation['requestBody'])) {
            return null;
        }

        $content = $simplifier->resolveRef($operation['requestBody'])['content'] ?? [];

        foreach (['application/json', 'multipart/form-data', 'application/x-www-form-urlencoded'] as $mediaType) {
            if (isset($content[$mediaType]['schema']) && \is_array($content[$mediaType]['schema'])) {
                return $simplifier->resolveRef($content[$mediaType]['schema']);
            }
        }

        return null;
    }

    /**
     * Derive an `outputSchema` from the operation's 2xx JSON response.
     *
     * Wrapped shape, not the raw one. Before MCP 2026-07-28 `structuredContent`
     * had to be a JSON object, so a top-level array was unemittable and the proxy
     * wraps those as `{"items": [...]}`. SEP-2106 lifts that restriction, but both
     * servers serve both eras, so the wrapper stays and the schema has to describe
     * what is actually sent — not what the REST API returns.
     *
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>|null
     */
    private function extractOutputSchema(array $operation, SchemaSimplifier $simplifier): ?array
    {
        $responses = $operation['responses'] ?? [];
        if (!\is_array($responses)) {
            return null;
        }

        foreach (['200', '201', '202', 200, 201, 202, 'default'] as $status) {
            if (!isset($responses[$status]) || !\is_array($responses[$status])) {
                continue;
            }
            $response = $simplifier->resolveRef($responses[$status]);
            $schema = $response['content']['application/json']['schema'] ?? null;
            if (!\is_array($schema)) {
                continue;
            }

            $simplified = $simplifier->simplify($schema);
            $type = $simplified['type'] ?? null;

            if ('array' === $type) {
                return [
                    'type' => 'object',
                    'properties' => ['items' => $simplified],
                    'required' => ['items'],
                ];
            }

            // Anything that is not a described object is not worth advertising:
            // a bare `{"type":"string"}` output schema tells the model nothing and
            // makes the SDK validate `structuredContent` against it for no gain.
            if ('object' === $type && isset($simplified['properties'])) {
                return $simplified;
            }

            return null;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return array<string, mixed>
     */
    private function buildAnnotations(array $operation, string $operationId, string $method, bool $isDestructive): array
    {
        // A GET the classifier flagged is a GET with side effects. It is neither
        // read-only nor idempotent, whatever HTTP semantics would suggest.
        $isMutatingGet = 'GET' === $method && $isDestructive;

        $title = trim((string) ($operation['summary'] ?? ''));
        $readOnly = 'GET' === $method && !$isMutatingGet;

        return [
            'title' => '' !== $title ? $title : $operationId,
            'readOnlyHint' => $readOnly,
            // Not `$isDestructive`. MCP defines `destructiveHint` as meaningful
            // only when `readOnlyHint` is false, and its default there is **true**
            // — so emitting `false` is an active claim that a write is purely
            // additive, which is not something a path heuristic can establish for
            // an API that places real orders and changes payment methods.
            //
            // Claude's connector review also rejects outright any tool that
            // declares neither `readOnlyHint: true` nor `destructiveHint: true`;
            // measured against the two live specs, 226 of 756 operations are
            // ordinary non-read-only writes that the heuristic does not flag, and
            // every one of them would have failed that review.
            //
            // The classifier still earns its keep: it demotes side-effecting GETs
            // out of read-only, drives `idempotentHint`, and decides which tools
            // carry the `[DESTRUCTIVE]` marker in the description — a marker worth
            // reserving for genuinely irreversible operations rather than every write.
            'destructiveHint' => !$readOnly,
            'idempotentHint' => \in_array($method, ['GET', 'PUT', 'DELETE'], true) && !$isMutatingGet,
            // Every tool here is an HTTP call into a hosting backend, so the
            // open-world hint is unconditionally true.
            'openWorldHint' => true,
        ];
    }

    private function generateOperationId(string $path, string $method): string
    {
        $name = $method;
        foreach (array_filter(explode('/', $path)) as $part) {
            if (str_starts_with($part, '{')) {
                continue;
            }
            $name .= '_'.preg_replace('/[^a-zA-Z0-9]/', '_', $part);
        }

        return $name;
    }
}
