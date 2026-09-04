<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Contract;

use InterServer\Mcp\Core\OpenApi\DestructiveClassifier;
use InterServer\Mcp\Core\OpenApi\OpenApiParser;
use InterServer\Mcp\Core\OpenApi\ToolDefinition;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use PHPUnit\Framework\TestCase;

/**
 * Runs the whole tool catalogue of both real specs through the rules that get a
 * connector rejected — or a model confused — rather than through a fixture.
 *
 * Claude's connector review rejects outright:
 *   - a tool accepting both safe (GET/HEAD/OPTIONS) and unsafe methods;
 *   - any catch-all `api_request`-style tool with a `method` parameter;
 *   - a tool name over 64 characters;
 *   - a tool lacking a `title` annotation and one of `readOnlyHint: true` /
 *     `destructiveHint: true`.
 *
 * Only the client server faces that review, but these tests live in core and run
 * against both surfaces: a 70-character admin tool name is a bug either way, and
 * the admin surface is where destructive classification actually matters.
 *
 * The suite skips when the specs are not on disk, so a checkout without the API
 * repo still has a green build. CI points MCP_CLIENT_SPEC / MCP_ADMIN_SPEC at them.
 *
 * @coversNothing
 */
final class ToolContractTest extends TestCase
{
    /** Claude's connector review rejects anything longer. */
    private const MAX_TOOL_NAME_LENGTH = 64;

    /**
     * Where the specs live when this runs next to a mystage checkout. Overridden
     * by the environment in CI.
     */
    private const DEFAULT_CLIENT_SPEC = '/home/sites/mystage/public_html/spec/openapi.yaml';
    private const DEFAULT_ADMIN_SPEC = '/home/sites/mystage/public_html/admin/openapi-admin.yaml';

    /** @var array<string, list<ToolDefinition>> */
    private static array $catalogues = [];

    /**
     * The admin surface's own destructive rules, as they will be configured in
     * `my-admin-mcp-server/config/destructive-rules.php`. They are passed in, not
     * compiled in — the client build must not carry them.
     */
    private static function adminClassifier(): DestructiveClassifier
    {
        return DestructiveClassifier::fromArray([
            'pathPrefixes' => ['/admin/orders/'],
            'operationIdPatterns' => [
                '/^admin('
                .'Cancel|Delete|Refund|Reassign|Suspend|Wipe|Purge|Remove|'
                .'ResetPassword|ResetMailPassword|ReinstallOs|MarkFraud|Destroy|'
                .'Restore|Migrate|MassEmail|ApcPower|Apc(Setup|Powerstrip)|'
                .'IpmiPower|ChangeIp|ChangePassword|ChangeRootPassword|'
                .'CleanLoginLogs|Order|ManageSwitchPort|AddNullRoute|'
                .'ServerIpmiPower|BuyHdSpace|BuyIp|ForceDelete'
                .')/i',
            ],
        ]);
    }

    private static function specPath(string $surface): ?string
    {
        $path = 'admin' === $surface
            ? (getenv('MCP_ADMIN_SPEC') ?: self::DEFAULT_ADMIN_SPEC)
            : (getenv('MCP_CLIENT_SPEC') ?: self::DEFAULT_CLIENT_SPEC);

        return is_readable($path) ? $path : null;
    }

    /**
     * @return list<ToolDefinition>
     */
    private function catalogue(string $surface): array
    {
        if (isset(self::$catalogues[$surface])) {
            return self::$catalogues[$surface];
        }

        $path = self::specPath($surface);
        if (null === $path) {
            $this->markTestSkipped(\sprintf(
                'the %s OpenAPI spec is not available; set MCP_%s_SPEC to run the contract suite',
                $surface,
                strtoupper($surface),
            ));
        }

        $parser = 'admin' === $surface
            ? new OpenApiParser(self::adminClassifier())
            : new OpenApiParser();

        return self::$catalogues[$surface] = $parser->parseContent((string) file_get_contents($path));
    }

    public static function surfaceProvider(): \Generator
    {
        yield 'client' => ['client'];
        yield 'admin' => ['admin'];
    }

    // -------------------------------------------------------------- the specs

    /**
     * @dataProvider surfaceProvider
     */
    public function testTheSpecProducesTools(string $surface): void
    {
        self::assertNotEmpty($this->catalogue($surface));
    }

    // --------------------------------------------------------------- naming

    /**
     * @dataProvider surfaceProvider
     */
    public function testEveryToolNameIsWithinTheReviewLimit(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            if (\strlen($tool->name) > self::MAX_TOOL_NAME_LENGTH) {
                $offenders[] = \sprintf('%s (%d chars)', $tool->name, \strlen($tool->name));
            }
        }

        self::assertSame([], $offenders, "tool names over 64 characters:\n  ".implode("\n  ", $offenders));
    }

    /**
     * @dataProvider surfaceProvider
     */
    public function testEveryToolNameIsUnique(string $surface): void
    {
        $names = array_map(static fn (ToolDefinition $t): string => $t->name, $this->catalogue($surface));
        $duplicates = array_keys(array_filter(array_count_values($names), static fn (int $n): bool => $n > 1));

        self::assertSame([], $duplicates, 'duplicate tool names silently shadow one another in the registry');
    }

    /**
     * @dataProvider surfaceProvider
     */
    public function testEveryToolNameIsAValidIdentifier(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            if (1 !== preg_match('/^[A-Za-z0-9_-]+$/', $tool->name)) {
                $offenders[] = $tool->name;
            }
        }

        self::assertSame([], $offenders, 'tool names must be plain identifiers');
    }

    // ----------------------------------------------------------- annotations

    /**
     * @dataProvider surfaceProvider
     */
    public function testEveryToolHasATitle(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            if ('' === trim($tool->title())) {
                $offenders[] = $tool->name;
            }
        }

        self::assertSame([], $offenders, 'tools with no title annotation are rejected by connector review');
    }

    /**
     * The rule that 226 of the 756 live operations failed before the annotation
     * logic was corrected: neither hint true is an outright rejection.
     *
     * @dataProvider surfaceProvider
     */
    public function testEveryToolDeclaresExactlyOneSafetyHint(string $surface): void
    {
        $neither = [];
        $both = [];
        foreach ($this->catalogue($surface) as $tool) {
            if (!$tool->isReadOnly() && !$tool->isDestructive()) {
                $neither[] = \sprintf('%s (%s %s)', $tool->name, $tool->httpMethod, $tool->path);
            }
            if ($tool->isReadOnly() && $tool->isDestructive()) {
                $both[] = $tool->name;
            }
        }

        self::assertSame([], $neither, "tools declaring neither readOnlyHint nor destructiveHint:\n  ".implode("\n  ", \array_slice($neither, 0, 20)));
        self::assertSame([], $both, "tools declaring both readOnlyHint and destructiveHint:\n  ".implode("\n  ", $both));
    }

    /**
     * @dataProvider surfaceProvider
     */
    public function testNoWriteMethodIsMarkedReadOnly(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            if ($tool->isReadOnly() && 'GET' !== $tool->httpMethod) {
                $offenders[] = \sprintf('%s (%s)', $tool->name, $tool->httpMethod);
            }
        }

        self::assertSame([], $offenders, 'only a GET can be read-only');
    }

    /**
     * @dataProvider surfaceProvider
     */
    public function testEveryDeleteIsDestructive(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            if ('DELETE' === $tool->httpMethod && !$tool->isDestructive()) {
                $offenders[] = $tool->name;
            }
        }

        self::assertSame([], $offenders);
    }

    // ------------------------------------------------------- one method each

    /**
     * A tool that accepts both safe and unsafe HTTP methods is rejected outright.
     * The generator produces one tool per operation, so this holds structurally —
     * asserting it keeps it true if the generator ever learns to merge operations.
     *
     * @dataProvider surfaceProvider
     */
    public function testEveryToolBindsExactlyOneHttpMethod(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            if (!\in_array($tool->httpMethod, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $offenders[] = \sprintf('%s (%s)', $tool->name, $tool->httpMethod);
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * No catch-all tool: nothing may let the model choose the HTTP verb or the
     * request target. That is the `api_request(method, url)` shape connector
     * review names explicitly.
     *
     * Matching on parameter *name* alone is not good enough, and the live specs
     * say why: `initiatePayment` takes a `method` that enumerates payment gateways
     * (`cc`, `paypal`, `btcpay`, …) and `postVpsInsertCd` takes a `url` naming an
     * ISO image to mount. Both are ordinary domain parameters on concrete routes.
     * So the test asks the two questions that actually matter — does this parameter
     * enumerate HTTP verbs, and does this tool have a route of its own?
     *
     * @dataProvider surfaceProvider
     */
    public function testNoToolIsACatchAllProxy(string $surface): void
    {
        $this->assertNoCatchAllTools($surface);
    }

    private function assertNoCatchAllTools(string $surface): void
    {
        $verbNames = ['method', 'httpmethod', 'http_method', 'verb'];
        $targetNames = ['url', 'uri', 'endpoint', 'path'];
        $httpVerbs = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

        $offenders = [];

        foreach ($this->catalogue($surface) as $tool) {
            // A route is "concrete" when something outside a placeholder names the
            // resource. A catch-all has nothing there to name.
            $concreteRoute = '' !== trim(preg_replace('/\{[^}]*\}/', '', $tool->path) ?? '', '/');

            foreach (($tool->inputSchema['properties'] ?? []) as $name => $schema) {
                $lower = strtolower((string) $name);

                if (\in_array($lower, $verbNames, true)) {
                    $enum = array_map(
                        static fn ($v): string => strtolower((string) $v),
                        \is_array($schema['enum'] ?? null) ? $schema['enum'] : [],
                    );

                    // An enum of HTTP verbs is a verb selector whatever the route.
                    // No enum at all on a verb-shaped name is equally unprovable.
                    if ([] === $enum || [] !== array_intersect($enum, $httpVerbs)) {
                        $offenders[] = \sprintf('%s.%s (verb selector)', $tool->name, $name);
                    }
                }

                if (\in_array($lower, $targetNames, true) && !$concreteRoute) {
                    $offenders[] = \sprintf('%s.%s (target selector on a non-specific route)', $tool->name, $name);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "parameters that would make a tool a catch-all proxy:\n  ".implode("\n  ", $offenders)
        );
    }

    // ------------------------------------------------------------- schemas

    /**
     * The SDK's `Tool` constructor throws on an input schema that is not
     * `type: object`, and on an out-of-range `x-mcp-header` annotation. Building
     * every tool here means a spec change can never produce a catalogue that only
     * fails at registration time, in production, on the first `tools/list`.
     *
     * @dataProvider surfaceProvider
     */
    public function testEveryToolIsAcceptedBySdkToolConstruction(string $surface): void
    {
        foreach ($this->catalogue($surface) as $tool) {
            try {
                new Tool(
                    name: $tool->name,
                    title: $tool->title(),
                    inputSchema: $tool->inputSchema,
                    description: $tool->description,
                    annotations: new ToolAnnotations(
                        title: $tool->title(),
                        readOnlyHint: $tool->isReadOnly(),
                        destructiveHint: $tool->isDestructive(),
                        idempotentHint: (bool) ($tool->annotations['idempotentHint'] ?? false),
                        openWorldHint: true,
                    ),
                    outputSchema: $tool->outputSchema,
                );
            } catch (\Throwable $e) {
                self::fail(\sprintf('tool "%s" is not constructible: %s', $tool->name, $e->getMessage()));
            }
        }

        $this->expectNotToPerformAssertions();
    }

    /**
     * @dataProvider surfaceProvider
     */
    public function testEveryPathParameterIsDeclaredRequired(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            $required = $tool->inputSchema['required'] ?? [];
            foreach ($tool->pathParams as $param) {
                if (!\in_array($param, $required, true)) {
                    $offenders[] = \sprintf('%s.%s', $tool->name, $param);
                }
            }
        }

        self::assertSame([], $offenders, 'a path parameter is structurally required — there is no URL without it');
    }

    /**
     * Every placeholder in the URL template must have a matching parameter, or the
     * proxy sends a literal `{id}` upstream and the API rejects a call the model
     * had no way to make correctly.
     *
     * @dataProvider surfaceProvider
     */
    public function testEveryPathPlaceholderHasAParameter(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            preg_match_all('/\{([^}]+)\}/', $tool->path, $matches);
            foreach ($matches[1] as $placeholder) {
                if (!\in_array($placeholder, $tool->pathParams, true)) {
                    $offenders[] = \sprintf('%s: {%s} in %s', $tool->name, $placeholder, $tool->path);
                }
            }
        }

        self::assertSame([], $offenders, "unbound path placeholders:\n  ".implode("\n  ", $offenders));
    }

    /**
     * @dataProvider surfaceProvider
     */
    public function testEveryOutputSchemaIsAnObject(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            if (null !== $tool->outputSchema && 'object' !== ($tool->outputSchema['type'] ?? null)) {
                $offenders[] = $tool->name;
            }
        }

        self::assertSame([], $offenders, 'structuredContent must be an object in the handshake era');
    }

    // ------------------------------------------------------------ descriptions

    /**
     * @dataProvider surfaceProvider
     */
    public function testNoDescriptionExceedsTheBudget(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            if (mb_strlen($tool->description) > OpenApiParser::DESCRIPTION_LIMIT) {
                $offenders[] = \sprintf('%s (%d chars)', $tool->name, mb_strlen($tool->description));
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * @dataProvider surfaceProvider
     */
    public function testEveryToolHasADescription(string $surface): void
    {
        $offenders = [];
        foreach ($this->catalogue($surface) as $tool) {
            if ('' === trim($tool->description)) {
                $offenders[] = $tool->name;
            }
        }

        self::assertSame([], $offenders);
    }

    // ----------------------------------------------- surface-specific rules

    /**
     * The bug this package exists to prevent, asserted against the real catalogue:
     * the admin heuristics must not classify anything in the client build.
     * `/admin/orders/` and `^admin(Cancel|…)` were compiled into both origin repos,
     * so the client shipped rules that could never match one of its own paths.
     */
    public function testTheClientCatalogueCarriesNoAdminPaths(): void
    {
        $offenders = [];
        foreach ($this->catalogue('client') as $tool) {
            if (str_starts_with(strtolower($tool->path), '/admin/')) {
                $offenders[] = \sprintf('%s (%s)', $tool->name, $tool->path);
            }
        }

        self::assertSame([], $offenders, 'the client spec must expose no /admin/ paths');
    }

    /**
     * The admin rules are configuration, and configuration that matches nothing is
     * configuration nobody will notice has gone stale.
     */
    public function testTheAdminDestructiveRulesActuallyMatchAdminOperations(): void
    {
        $catalogue = $this->catalogue('admin');

        $flaggedByAdminRules = array_filter(
            $catalogue,
            static fn (ToolDefinition $t): bool => str_contains($t->description, '[DESTRUCTIVE]'),
        );

        self::assertNotEmpty(
            $flaggedByAdminRules,
            'the admin destructive rules matched no operation — they have gone stale against the spec'
        );
    }

    /**
     * Same rules against the client spec: they should match far less, because they
     * are admin rules. If they match a lot, they are not admin-specific and belong
     * in the shared defaults instead.
     */
    public function testTheAdminRulesAreGenuinelyAdminSpecific(): void
    {
        $withAdminRules = (new OpenApiParser(self::adminClassifier()))
            ->parseContent((string) file_get_contents((string) self::specPath('client')));

        $adminOnly = 0;
        foreach ($withAdminRules as $index => $tool) {
            if ($tool->isDestructive() !== $this->catalogue('client')[$index]->isDestructive()) {
                ++$adminOnly;
            }
        }

        self::assertSame(
            0,
            $adminOnly,
            'the admin destructive rules changed the client classification — they are not admin-specific'
        );
    }
}
