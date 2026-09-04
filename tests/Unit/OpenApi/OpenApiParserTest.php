<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\OpenApi;

use InterServer\Mcp\Core\OpenApi\DestructiveClassifier;
use InterServer\Mcp\Core\OpenApi\OpenApiParser;
use InterServer\Mcp\Core\OpenApi\ToolDefinition;
use InterServer\Mcp\Core\Tests\Fixtures\SpecBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\OpenApi\OpenApiParser
 */
final class OpenApiParserTest extends TestCase
{
    private OpenApiParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OpenApiParser();
    }

    /**
     * @param list<ToolDefinition> $tools
     */
    private static function byName(array $tools, string $name): ToolDefinition
    {
        foreach ($tools as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }
        self::fail(\sprintf('No tool named "%s"; got: %s', $name, implode(', ', array_map(static fn ($t) => $t->name, $tools))));
    }

    // ---------------------------------------------------------------- basics

    public function testEmptyDocumentProducesNoTools(): void
    {
        self::assertSame([], $this->parser->parseDocument([]));
        self::assertSame([], $this->parser->parseDocument(['paths' => []]));
    }

    public function testOneOperationBecomesOneTool(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/things/{id}', 'get', SpecBuilder::typicalGet())->toArray()
        );

        self::assertCount(1, $tools);
        self::assertSame('getThing', $tools[0]->name);
        self::assertSame('GET', $tools[0]->httpMethod);
        self::assertSame('/things/{id}', $tools[0]->path);
    }

    public function testEveryHttpMethodOnOnePathBecomesItsOwnTool(): void
    {
        $builder = SpecBuilder::make();
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            $builder->operation('/things', $method, ['operationId' => $method.'Thing', 'summary' => 'x']);
        }

        $tools = $this->parser->parseDocument($builder->toArray());

        self::assertCount(5, $tools);
        self::assertEqualsCanonicalizing(
            ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
            array_map(static fn ($t) => $t->httpMethod, $tools),
        );
    }

    public function testUnsupportedHttpMethodsAreIgnored(): void
    {
        $spec = SpecBuilder::make()
            ->operation('/things', 'get', ['operationId' => 'getThings'])
            ->operation('/things', 'head', ['operationId' => 'headThings'])
            ->operation('/things', 'options', ['operationId' => 'optionsThings'])
            ->operation('/things', 'trace', ['operationId' => 'traceThings'])
            ->toArray();

        $tools = $this->parser->parseDocument($spec);

        self::assertCount(1, $tools);
        self::assertSame('getThings', $tools[0]->name);
    }

    public function testPathItemThatIsNotAnArrayIsSkipped(): void
    {
        self::assertSame([], $this->parser->parseDocument(['paths' => ['/broken' => 'not-an-object']]));
    }

    // ------------------------------------------------------------ operationId

    public function testMissingOperationIdIsGeneratedFromThePath(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/admin/vps/{id}/start', 'post', ['summary' => 'x'])->toArray()
        );

        self::assertSame('post_admin_vps_start', $tools[0]->name);
    }

    public function testGeneratedOperationIdOmitsPathParametersAndSanitisesSeparators(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/scrub-ips/{ip}/null.routes', 'get', [])->toArray()
        );

        self::assertSame('get_scrub_ips_null_routes', $tools[0]->name);
    }

    // ----------------------------------------------------------- description

    public function testSummaryAndDescriptionAreJoined(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'get', [
            'operationId' => 'getX',
            'summary' => 'Short summary',
            'description' => 'Longer detail.',
        ])->toArray());

        self::assertSame('Short summary — Longer detail.', $tools[0]->description);
    }

    public function testIdenticalSummaryAndDescriptionAreNotDuplicated(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'get', [
            'operationId' => 'getX',
            'summary' => 'Same text',
            'description' => 'Same text',
        ])->toArray());

        self::assertSame('Same text', $tools[0]->description);
    }

    public function testOperationWithNoProseFallsBackToMethodAndPath(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/bare', 'post', ['operationId' => 'postBare'])->toArray()
        );

        self::assertSame('POST /bare', $tools[0]->description);
    }

    public function testTagIsPrefixedOntoTheDescription(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'get', [
            'operationId' => 'getX', 'summary' => 'Summary', 'tags' => ['Servers', 'Ignored'],
        ])->toArray());

        self::assertSame('[Servers] Summary', $tools[0]->description);
        self::assertSame('Servers', $tools[0]->tag);
    }

    public function testDestructiveMarkerIsPrefixedOntoTheDescription(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x/{id}', 'delete', [
            'operationId' => 'deleteX', 'summary' => 'Remove it', 'tags' => ['Vps'],
        ])->toArray());

        self::assertSame('[Vps] [DESTRUCTIVE] Remove it', $tools[0]->description);
    }

    public function testDestructiveMarkerAppearsWithoutATag(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x/{id}', 'delete', [
            'operationId' => 'deleteX', 'summary' => 'Remove it',
        ])->toArray());

        self::assertSame('[DESTRUCTIVE] Remove it', $tools[0]->description);
    }

    public function testDescriptionUnderTheLimitIsUntouched(): void
    {
        $text = str_repeat('a', 800);
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/x', 'get', ['operationId' => 'getX', 'summary' => $text])->toArray()
        );

        self::assertSame($text, $tools[0]->description);
    }

    public function testOverlongDescriptionIsCutAtASentenceBoundary(): void
    {
        // A sentence ending comfortably past the 700-char floor and before 900.
        $summary = str_repeat('word ', 160).'End of sentence. '.str_repeat('tail ', 100);

        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/x', 'get', ['operationId' => 'getX', 'summary' => $summary])->toArray()
        );

        self::assertStringEndsWith('End of sentence.', $tools[0]->description);
        self::assertLessThanOrEqual(OpenApiParser::DESCRIPTION_LIMIT, mb_strlen($tools[0]->description));
    }

    public function testOverlongDescriptionWithNoLateSentenceBoundaryIsHardCut(): void
    {
        // One sentence boundary very early, then nothing — a sentence cut would
        // throw away most of the budget, so a hard cut is preferred.
        $summary = 'Tiny. '.str_repeat('x', 2000);

        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/x', 'get', ['operationId' => 'getX', 'summary' => $summary])->toArray()
        );

        self::assertStringEndsWith('...', $tools[0]->description);
        self::assertSame(OpenApiParser::DESCRIPTION_LIMIT, mb_strlen($tools[0]->description));
    }

    public function testTruncationCountsCharactersNotBytes(): void
    {
        $summary = str_repeat('é', 2000);

        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/x', 'get', ['operationId' => 'getX', 'summary' => $summary])->toArray()
        );

        self::assertSame(OpenApiParser::DESCRIPTION_LIMIT, mb_strlen($tools[0]->description));
    }

    // ------------------------------------------------------------ parameters

    public function testPathParametersAreAlwaysRequired(): void
    {
        // The spec marks it optional; there is still no URL without it.
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x/{id}', 'get', [
            'operationId' => 'getX',
            'parameters' => [['name' => 'id', 'in' => 'path', 'schema' => ['type' => 'string']]],
        ])->toArray());

        self::assertSame(['id'], $tools[0]->pathParams);
        self::assertSame(['id'], $tools[0]->inputSchema['required']);
    }

    public function testOptionalQueryParametersAreNotRequired(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'get', [
            'operationId' => 'getX',
            'parameters' => [
                ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
            ],
        ])->toArray());

        self::assertSame(['page', 'q'], $tools[0]->queryParams);
        self::assertSame(['q'], $tools[0]->inputSchema['required']);
    }

    public function testHeaderAndCookieParametersAreNeverAdvertised(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'get', [
            'operationId' => 'getX',
            'parameters' => [
                ['name' => 'X-API-KEY', 'in' => 'header', 'schema' => ['type' => 'string']],
                ['name' => 'sessionid', 'in' => 'cookie', 'schema' => ['type' => 'string']],
                ['name' => 'real', 'in' => 'query', 'schema' => ['type' => 'string']],
            ],
        ])->toArray());

        self::assertSame(['real'], array_keys($tools[0]->inputSchema['properties']));
    }

    public function testPathLevelParametersAreMergedIntoEveryOperation(): void
    {
        $spec = SpecBuilder::make()
            ->pathParameters('/x/{id}', [['name' => 'id', 'in' => 'path', 'schema' => ['type' => 'string']]])
            ->operation('/x/{id}', 'get', ['operationId' => 'getX'])
            ->operation('/x/{id}', 'delete', ['operationId' => 'deleteX'])
            ->toArray();

        $tools = $this->parser->parseDocument($spec);

        self::assertSame(['id'], self::byName($tools, 'getX')->pathParams);
        self::assertSame(['id'], self::byName($tools, 'deleteX')->pathParams);
    }

    public function testParameterDescriptionOverridesTheSchemaDescription(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'get', [
            'operationId' => 'getX',
            'parameters' => [[
                'name' => 'q', 'in' => 'query',
                'description' => 'From the parameter',
                'schema' => ['type' => 'string', 'description' => 'From the schema'],
            ]],
        ])->toArray());

        self::assertSame('From the parameter', $tools[0]->inputSchema['properties']['q']['description']);
    }

    public function testUnnamedParametersAreSkipped(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'get', [
            'operationId' => 'getX',
            'parameters' => [['in' => 'query', 'schema' => ['type' => 'string']]],
        ])->toArray());

        self::assertArrayNotHasKey('properties', $tools[0]->inputSchema);
    }

    public function testParameterReferencesAreResolved(): void
    {
        $spec = SpecBuilder::make()
            ->components(['parameters' => ['ServiceId' => [
                'name' => 'id', 'in' => 'path', 'schema' => ['type' => 'integer'],
            ]]])
            ->operation('/x/{id}', 'get', [
                'operationId' => 'getX',
                'parameters' => [['$ref' => '#/components/parameters/ServiceId']],
            ])
            ->toArray();

        $tools = $this->parser->parseDocument($spec);

        self::assertSame(['id'], $tools[0]->pathParams);
        self::assertSame('integer', $tools[0]->inputSchema['properties']['id']['type']);
    }

    // ---------------------------------------------------------- request body

    public function testJsonRequestBodyPropertiesBecomeToolProperties(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'post', [
            'operationId' => 'createX',
            'requestBody' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string'], 'size' => ['type' => 'integer']],
                'required' => ['name'],
            ]]]],
        ])->toArray());

        self::assertTrue($tools[0]->hasBody);
        self::assertSame(['name', 'size'], array_keys($tools[0]->inputSchema['properties']));
        self::assertSame(['name'], $tools[0]->inputSchema['required']);
    }

    public function testJsonBodyIsPreferredOverMultipart(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'post', [
            'operationId' => 'createX',
            'requestBody' => ['content' => [
                'multipart/form-data' => ['schema' => ['type' => 'object', 'properties' => ['file' => ['type' => 'string']]]],
                'application/json' => ['schema' => ['type' => 'object', 'properties' => ['json' => ['type' => 'string']]]],
            ]],
        ])->toArray());

        self::assertSame(['json'], array_keys($tools[0]->inputSchema['properties']));
    }

    public function testFormUrlEncodedBodyIsAccepted(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'post', [
            'operationId' => 'createX',
            'requestBody' => ['content' => ['application/x-www-form-urlencoded' => ['schema' => [
                'type' => 'object', 'properties' => ['field' => ['type' => 'string']],
            ]]]],
        ])->toArray());

        self::assertTrue($tools[0]->hasBody);
        self::assertSame(['field'], array_keys($tools[0]->inputSchema['properties']));
    }

    public function testRequestBodyReferenceIsResolved(): void
    {
        $spec = SpecBuilder::make()
            ->components(['requestBodies' => ['CreateThing' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object', 'properties' => ['label' => ['type' => 'string']],
            ]]]]]])
            ->operation('/x', 'post', [
                'operationId' => 'createX',
                'requestBody' => ['$ref' => '#/components/requestBodies/CreateThing'],
            ])
            ->toArray();

        $tools = $this->parser->parseDocument($spec);

        self::assertTrue($tools[0]->hasBody);
        self::assertSame(['label'], array_keys($tools[0]->inputSchema['properties']));
    }

    public function testUnsupportedBodyMediaTypeLeavesTheToolBodyless(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'post', [
            'operationId' => 'createX',
            'requestBody' => ['content' => ['application/octet-stream' => ['schema' => ['type' => 'string']]]],
        ])->toArray());

        self::assertFalse($tools[0]->hasBody);
    }

    public function testBodyPropertyCollidingWithAQueryParameterDoesNotDuplicateRequired(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'post', [
            'operationId' => 'createX',
            'parameters' => [['name' => 'id', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']]],
            'requestBody' => ['content' => ['application/json' => ['schema' => [
                'type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id'],
            ]]]],
        ])->toArray());

        self::assertSame(['id'], $tools[0]->inputSchema['required']);
    }

    // ---------------------------------------------------------- input schema

    public function testInputSchemaIsAlwaysAnObject(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/x', 'get', ['operationId' => 'getX'])->toArray()
        );

        self::assertSame(['type' => 'object'], $tools[0]->inputSchema);
    }

    public function testParameterlessOperationDeclaresNoPropertiesOrRequired(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/ping', 'get', ['operationId' => 'ping'])->toArray()
        );

        self::assertArrayNotHasKey('properties', $tools[0]->inputSchema);
        self::assertArrayNotHasKey('required', $tools[0]->inputSchema);
    }

    // ----------------------------------------------------------- annotations

    public function testPlainGetIsReadOnlyAndIdempotent(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/things', 'get', ['operationId' => 'getThings', 'summary' => 'List'])->toArray()
        );

        self::assertTrue($tools[0]->isReadOnly());
        self::assertFalse($tools[0]->isDestructive());
        self::assertTrue($tools[0]->annotations['idempotentHint']);
    }

    public function testPostIsNeitherReadOnlyNorIdempotent(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/things', 'post', ['operationId' => 'createThing'])->toArray()
        );

        self::assertFalse($tools[0]->isReadOnly());
        self::assertFalse($tools[0]->annotations['idempotentHint']);
    }

    /**
     * MCP treats `destructiveHint` as meaningful only when `readOnlyHint` is
     * false, and its default there is *true*. Emitting `false` would be an active
     * claim that a write is purely additive — not something a path heuristic can
     * establish for an API that places orders and changes payment methods. Claude's
     * connector review rejects outright any tool declaring neither hint, and 226 of
     * the 756 live operations are exactly this shape.
     */
    public function testAnUnflaggedWriteIsStillHintedDestructive(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/things', 'post', ['operationId' => 'createThing'])->toArray()
        );

        self::assertTrue($tools[0]->isDestructive());
    }

    /**
     * Exactly one of the two hints is true for every tool — never neither (which
     * fails review) and never both (which is incoherent).
     *
     * @dataProvider everyMethodProvider
     */
    public function testExactlyOneOfTheTwoSafetyHintsIsAlwaysTrue(string $method, string $path): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation($path, $method, ['operationId' => 'op'])->toArray()
        );

        self::assertNotSame(
            $tools[0]->isReadOnly(),
            $tools[0]->isDestructive(),
            'a tool must declare exactly one of readOnlyHint / destructiveHint as true'
        );
    }

    public static function everyMethodProvider(): \Generator
    {
        yield 'plain GET' => ['get', '/things'];
        yield 'side-effecting GET' => ['get', '/mail/{id}/reset_password'];
        yield 'POST' => ['post', '/things'];
        yield 'destructive POST' => ['post', '/things/{id}/cancel'];
        yield 'PUT' => ['put', '/things/{id}'];
        yield 'PATCH' => ['patch', '/things/{id}'];
        yield 'DELETE' => ['delete', '/things/{id}'];
    }

    /**
     * The `[DESTRUCTIVE]` marker stays reserved for what the classifier actually
     * flagged, so it keeps meaning something. The *hint* is conservative; the
     * *marker* is specific.
     */
    public function testTheDescriptionMarkerIsNarrowerThanTheHint(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/things', 'post', ['operationId' => 'createThing', 'summary' => 'Create'])->toArray()
        );

        self::assertTrue($tools[0]->isDestructive());
        self::assertStringNotContainsString('[DESTRUCTIVE]', $tools[0]->description);
    }

    public function testDeleteIsDestructiveButStillIdempotent(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/things/{id}', 'delete', ['operationId' => 'deleteThing'])->toArray()
        );

        self::assertTrue($tools[0]->isDestructive());
        self::assertTrue($tools[0]->annotations['idempotentHint']);
        self::assertFalse($tools[0]->isReadOnly());
    }

    /**
     * A GET the classifier flags is a GET with side effects. HTTP semantics would
     * call it safe and idempotent; it is neither, and a model that believes the
     * annotation will call it speculatively.
     */
    public function testSideEffectingGetIsNeitherReadOnlyNorIdempotent(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/mail/{id}/reset_password', 'get', ['operationId' => 'resetMailPassword'])->toArray()
        );

        self::assertTrue($tools[0]->isDestructive());
        self::assertFalse($tools[0]->isReadOnly());
        self::assertFalse($tools[0]->annotations['idempotentHint']);
    }

    public function testOpenWorldHintIsAlwaysTrue(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/x', 'get', ['operationId' => 'getX'])->toArray()
        );

        self::assertTrue($tools[0]->annotations['openWorldHint']);
    }

    public function testTitleComesFromTheSummary(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/x', 'get', ['operationId' => 'getX', 'summary' => '  Get the X  '])->toArray()
        );

        self::assertSame('Get the X', $tools[0]->title());
    }

    public function testTitleFallsBackToTheOperationIdWhenThereIsNoSummary(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/x', 'get', ['operationId' => 'getX'])->toArray()
        );

        self::assertSame('getX', $tools[0]->title());
    }

    // --------------------------------------------------------- output schema

    public function testObjectResponseBecomesAnOutputSchema(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/things/{id}', 'get', SpecBuilder::typicalGet())->toArray()
        );

        self::assertSame('object', $tools[0]->outputSchema['type']);
        self::assertSame(['id', 'name'], array_keys($tools[0]->outputSchema['properties']));
    }

    /**
     * ~89 operations across the two real specs return a top-level array, which the
     * proxy wraps as `{"items": [...]}` so it is emittable as `structuredContent`
     * in the pre-2026-07-28 era. The declared schema has to describe what is
     * actually sent, not what the REST API returns.
     */
    public function testArrayResponseIsDeclaredInItsWrappedForm(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/things', 'get', [
            'operationId' => 'listThings',
            'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => [
                'type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            ]]]]],
        ])->toArray());

        self::assertSame([
            'type' => 'object',
            'properties' => ['items' => [
                'type' => 'array',
                'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            ]],
            'required' => ['items'],
        ], $tools[0]->outputSchema);
    }

    public function testResponseWithoutAJsonSchemaProducesNoOutputSchema(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'get', [
            'operationId' => 'getX',
            'responses' => ['200' => ['description' => 'ok']],
        ])->toArray());

        self::assertNull($tools[0]->outputSchema);
    }

    /**
     * A bare `{"type":"string"}` output schema tells a model nothing and makes the
     * SDK validate structuredContent against it for no gain.
     */
    public function testScalarResponseProducesNoOutputSchema(): void
    {
        $tools = $this->parser->parseDocument(SpecBuilder::make()->operation('/x', 'get', [
            'operationId' => 'getX',
            'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => ['type' => 'string']]]]],
        ])->toArray());

        self::assertNull($tools[0]->outputSchema);
    }

    public function testResponseSchemaReferenceIsResolved(): void
    {
        $spec = SpecBuilder::make()
            ->components(['schemas' => ['Thing' => [
                'type' => 'object', 'properties' => ['id' => ['type' => 'integer']],
            ]]])
            ->operation('/x', 'get', [
                'operationId' => 'getX',
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => [
                    '$ref' => '#/components/schemas/Thing',
                ]]]]],
            ])
            ->toArray();

        $tools = $this->parser->parseDocument($spec);

        self::assertSame(['id'], array_keys($tools[0]->outputSchema['properties']));
    }

    public function testOutputSchemasCanBeDisabled(): void
    {
        $parser = new OpenApiParser(includeOutputSchemas: false);

        $tools = $parser->parseDocument(
            SpecBuilder::make()->operation('/things/{id}', 'get', SpecBuilder::typicalGet())->toArray()
        );

        self::assertNull($tools[0]->outputSchema);
    }

    // ------------------------------------------------------- injected rules

    /**
     * The heart of the D7 decision: admin destructive rules are configuration, so
     * the client build never carries logic that can only ever match admin paths.
     */
    public function testInjectedClassifierRulesApply(): void
    {
        $parser = new OpenApiParser(new DestructiveClassifier(
            pathPrefixes: ['/admin/orders/'],
            operationIdPatterns: ['/^adminForceDelete/i'],
        ));

        $tools = $parser->parseDocument(
            SpecBuilder::make()
                ->operation('/admin/orders/vps', 'get', ['operationId' => 'adminOrderVps'])
                ->operation('/admin/x', 'post', ['operationId' => 'adminForceDeleteX'])
                ->toArray()
        );

        self::assertTrue(self::byName($tools, 'adminOrderVps')->isDestructive());
        self::assertTrue(self::byName($tools, 'adminForceDeleteX')->isDestructive());
    }

    public function testDefaultClassifierDoesNotCarryAdminRules(): void
    {
        $tools = $this->parser->parseDocument(
            SpecBuilder::make()->operation('/admin/orders/vps', 'get', ['operationId' => 'adminOrderVps'])->toArray()
        );

        self::assertFalse(
            $tools[0]->isDestructive(),
            'the default classifier must not carry admin heuristics — that is the bug D7 exists to prevent'
        );
    }

    // ------------------------------------------------------------- formats

    public function testYamlAndJsonProduceIdenticalResults(): void
    {
        $builder = SpecBuilder::make()->operation('/things/{id}', 'get', SpecBuilder::typicalGet());

        self::assertEquals(
            $this->parser->parseYaml($builder->toYaml()),
            $this->parser->parseJson($builder->toJson()),
        );
    }

    public function testParseContentDetectsJson(): void
    {
        $builder = SpecBuilder::make()->operation('/things/{id}', 'get', SpecBuilder::typicalGet());

        self::assertEquals(
            $this->parser->parseJson($builder->toJson()),
            $this->parser->parseContent("  \n".$builder->toJson()),
        );
    }

    public function testParseContentDetectsYaml(): void
    {
        $builder = SpecBuilder::make()->operation('/things/{id}', 'get', SpecBuilder::typicalGet());

        self::assertEquals(
            $this->parser->parseYaml($builder->toYaml()),
            $this->parser->parseContent($builder->toYaml()),
        );
    }

    public function testUnparseableContentYieldsNoTools(): void
    {
        self::assertSame([], $this->parser->parseJson('not json'));
    }
}
