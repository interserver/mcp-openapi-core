<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\OpenApi;

/**
 * Reduces an OpenAPI/JSON-Schema fragment to the subset an MCP client can act on.
 *
 * MCP tool input schemas are read by a language model, not by a validator: the
 * fields worth keeping are the ones that change what the model sends. Everything
 * structural that only a code generator would care about (`discriminator`,
 * `xml`, `readOnly`, vendor extensions) is dropped.
 *
 * `$ref` resolution lives here too, because it has to happen at every nesting
 * level and against the whole document.
 */
final class SchemaSimplifier
{
    /**
     * Scalar keywords copied through verbatim when present.
     *
     * `example`/`examples` are included deliberately: a realistic value measurably
     * improves tool-call accuracy versus a bare type, because the model can pattern
     * off it instead of guessing.
     */
    private const PASSTHROUGH_KEYS = [
        'type', 'description', 'enum', 'format',
        'minimum', 'maximum', 'minLength', 'maxLength',
        'pattern', 'default', 'example',
    ];

    /**
     * Guards against a `$ref` cycle in the source document. OpenAPI permits
     * recursive schemas; an MCP input schema that recurses forever does not
     * help a model, and would hang the parse.
     */
    private const MAX_DEPTH = 20;

    /** @var array<string, mixed> */
    private array $document;

    /**
     * @param array<string, mixed> $document the whole spec, needed to resolve `#/...` pointers
     */
    public function __construct(array $document = [])
    {
        $this->document = $document;
    }

    /**
     * @param array<string, mixed> $document
     */
    public function withDocument(array $document): self
    {
        return new self($document);
    }

    /**
     * Resolve a `$ref` pointer against the document.
     *
     * Only local (`#/...`) pointers are supported — external documents would be
     * a fetch, and the specs this runs against are self-contained. An unresolvable
     * pointer returns the original fragment rather than throwing: one bad `$ref`
     * should cost one tool's parameter shape, not the whole tool catalogue.
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    public function resolveRef(array $item, int $depth = 0): array
    {
        if (!isset($item['$ref']) || !\is_string($item['$ref']) || $depth >= self::MAX_DEPTH) {
            return $item;
        }

        $ref = $item['$ref'];
        if (!str_starts_with($ref, '#/')) {
            return $item;
        }

        $resolved = $this->document;
        foreach (explode('/', ltrim($ref, '#/')) as $part) {
            // RFC 6901 JSON Pointer escaping: ~1 is "/", ~0 is "~". Order matters —
            // unescaping ~0 first would turn "~01" into "~1" and then into "/".
            $part = str_replace('~1', '/', str_replace('~0', '~', $part));
            if (!\is_array($resolved) || !\array_key_exists($part, $resolved)) {
                return $item;
            }
            $resolved = $resolved[$part];
        }

        return \is_array($resolved) ? $this->resolveRef($resolved, $depth + 1) : $item;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return array<string, mixed>
     */
    public function simplify(array $schema, int $depth = 0): array
    {
        $schema = $this->resolveRef($schema, $depth);

        if ($depth >= self::MAX_DEPTH) {
            return ['type' => 'string'];
        }

        $result = [];
        foreach (self::PASSTHROUGH_KEYS as $key) {
            if (isset($schema[$key])) {
                $result[$key] = $schema[$key];
            }
        }

        if (!empty($schema['nullable'])) {
            $result['nullable'] = true;
        }
        if (isset($schema['examples']) && \is_array($schema['examples'])) {
            $result['examples'] = $schema['examples'];
        }

        $type = $schema['type'] ?? '';

        if ('object' === $type && isset($schema['properties']) && \is_array($schema['properties'])) {
            $result['properties'] = [];
            foreach ($schema['properties'] as $key => $propSchema) {
                $result['properties'][$key] = \is_array($propSchema)
                    ? $this->simplify($propSchema, $depth + 1)
                    : ['type' => 'string'];
            }
            if (isset($schema['required']) && \is_array($schema['required'])) {
                $result['required'] = array_values($schema['required']);
            }
        }

        if ('array' === $type && isset($schema['items']) && \is_array($schema['items'])) {
            $result['items'] = $this->simplify($schema['items'], $depth + 1);
        }

        // A schema that reduced to nothing still has to be a valid JSON Schema.
        // "string" is the safest guess: every scalar the API accepts survives a
        // round-trip through it, whereas an empty object would let the model
        // send anything at all.
        return $result ?: ['type' => 'string'];
    }
}
