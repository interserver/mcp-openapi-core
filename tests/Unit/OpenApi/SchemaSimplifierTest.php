<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\OpenApi;

use InterServer\Mcp\Core\OpenApi\SchemaSimplifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\OpenApi\SchemaSimplifier
 */
final class SchemaSimplifierTest extends TestCase
{
    public function testScalarKeywordsAreKept(): void
    {
        $out = (new SchemaSimplifier())->simplify([
            'type' => 'integer',
            'description' => 'How many',
            'minimum' => 1,
            'maximum' => 100,
            'default' => 10,
            'format' => 'int32',
        ]);

        self::assertSame([
            'type' => 'integer',
            'description' => 'How many',
            'format' => 'int32',
            'minimum' => 1,
            'maximum' => 100,
            'default' => 10,
        ], $out);
    }

    public function testStringConstraintsAreKept(): void
    {
        $out = (new SchemaSimplifier())->simplify([
            'type' => 'string', 'minLength' => 3, 'maxLength' => 8, 'pattern' => '^[a-z]+$',
        ]);

        self::assertSame(['type' => 'string', 'minLength' => 3, 'maxLength' => 8, 'pattern' => '^[a-z]+$'], $out);
    }

    public function testEnumIsKept(): void
    {
        $out = (new SchemaSimplifier())->simplify(['type' => 'string', 'enum' => ['on', 'off']]);

        self::assertSame(['on', 'off'], $out['enum']);
    }

    /**
     * A realistic value measurably improves tool-call accuracy: the model can
     * pattern off it instead of guessing from the type.
     */
    public function testExamplesArePreserved(): void
    {
        $out = (new SchemaSimplifier())->simplify([
            'type' => 'string', 'example' => 'vps-12345', 'examples' => ['vps-1', 'vps-2'],
        ]);

        self::assertSame('vps-12345', $out['example']);
        self::assertSame(['vps-1', 'vps-2'], $out['examples']);
    }

    public function testNonArrayExamplesIsDropped(): void
    {
        $out = (new SchemaSimplifier())->simplify(['type' => 'string', 'examples' => 'not-a-list']);

        self::assertArrayNotHasKey('examples', $out);
    }

    public function testNullableIsNormalisedToABoolean(): void
    {
        self::assertTrue((new SchemaSimplifier())->simplify(['type' => 'string', 'nullable' => true])['nullable']);
        self::assertArrayNotHasKey('nullable', (new SchemaSimplifier())->simplify(['type' => 'string', 'nullable' => false]));
    }

    public function testGeneratorOnlyKeywordsAreDropped(): void
    {
        $out = (new SchemaSimplifier())->simplify([
            'type' => 'string',
            'readOnly' => true,
            'writeOnly' => false,
            'xml' => ['name' => 'thing'],
            'discriminator' => ['propertyName' => 'kind'],
            'x-vendor-thing' => 'ignored',
        ]);

        self::assertSame(['type' => 'string'], $out);
    }

    public function testNestedObjectPropertiesAreSimplifiedRecursively(): void
    {
        $out = (new SchemaSimplifier())->simplify([
            'type' => 'object',
            'properties' => [
                'inner' => ['type' => 'object', 'readOnly' => true, 'properties' => ['deep' => ['type' => 'integer', 'xml' => []]]],
            ],
            'required' => ['inner'],
        ]);

        self::assertSame(['type' => 'integer'], $out['properties']['inner']['properties']['deep']);
        self::assertSame(['inner'], $out['required']);
    }

    public function testArrayItemsAreSimplified(): void
    {
        $out = (new SchemaSimplifier())->simplify([
            'type' => 'array', 'items' => ['type' => 'string', 'xml' => []],
        ]);

        self::assertSame(['type' => 'array', 'items' => ['type' => 'string']], $out);
    }

    public function testNonArrayPropertyValueDegradesToAString(): void
    {
        $out = (new SchemaSimplifier())->simplify([
            'type' => 'object', 'properties' => ['broken' => 'not-a-schema'],
        ]);

        self::assertSame(['type' => 'string'], $out['properties']['broken']);
    }

    /**
     * An empty result would let the model send anything at all; a string round-trips
     * every scalar the API accepts.
     */
    public function testSchemaThatReducesToNothingBecomesAString(): void
    {
        self::assertSame(['type' => 'string'], (new SchemaSimplifier())->simplify([]));
        self::assertSame(['type' => 'string'], (new SchemaSimplifier())->simplify(['readOnly' => true]));
    }

    // ------------------------------------------------------------------ $ref

    public function testLocalReferenceIsResolved(): void
    {
        $simplifier = new SchemaSimplifier([
            'components' => ['schemas' => ['Thing' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]]]],
        ]);

        $out = $simplifier->simplify(['$ref' => '#/components/schemas/Thing']);

        self::assertSame(['id' => ['type' => 'integer']], $out['properties']);
    }

    public function testChainedReferencesAreFollowed(): void
    {
        $simplifier = new SchemaSimplifier(['components' => ['schemas' => [
            'A' => ['$ref' => '#/components/schemas/B'],
            'B' => ['type' => 'string', 'description' => 'the real one'],
        ]]]);

        self::assertSame('the real one', $simplifier->simplify(['$ref' => '#/components/schemas/A'])['description']);
    }

    public function testJsonPointerEscapesAreDecoded(): void
    {
        $simplifier = new SchemaSimplifier(['components' => ['schemas' => [
            'a/b' => ['type' => 'integer'],
            'c~d' => ['type' => 'boolean'],
        ]]]);

        self::assertSame('integer', $simplifier->simplify(['$ref' => '#/components/schemas/a~1b'])['type']);
        self::assertSame('boolean', $simplifier->simplify(['$ref' => '#/components/schemas/c~0d'])['type']);
    }

    /**
     * One bad pointer should cost one parameter's shape, not the whole catalogue.
     */
    public function testUnresolvableReferenceReturnsTheFragmentUnchanged(): void
    {
        $simplifier = new SchemaSimplifier(['components' => ['schemas' => []]]);

        self::assertSame(
            ['$ref' => '#/components/schemas/Missing'],
            $simplifier->resolveRef(['$ref' => '#/components/schemas/Missing']),
        );
    }

    public function testExternalReferencesAreNotFetched(): void
    {
        $ref = ['$ref' => 'https://example.test/schema.json#/Thing'];

        self::assertSame($ref, (new SchemaSimplifier())->resolveRef($ref));
    }

    public function testNonStringRefIsIgnored(): void
    {
        $ref = ['$ref' => ['not', 'a', 'string']];

        self::assertSame($ref, (new SchemaSimplifier())->resolveRef($ref));
    }

    /**
     * OpenAPI permits recursive schemas. An MCP input schema that recursed forever
     * would hang the parse and help no model.
     */
    public function testSelfReferentialSchemaTerminates(): void
    {
        $simplifier = new SchemaSimplifier(['components' => ['schemas' => [
            'Node' => ['type' => 'object', 'properties' => ['child' => ['$ref' => '#/components/schemas/Node']]],
        ]]]);

        $out = $simplifier->simplify(['$ref' => '#/components/schemas/Node']);

        self::assertIsArray($out);
        self::assertArrayHasKey('properties', $out);
    }

    public function testMutuallyRecursiveSchemasTerminate(): void
    {
        $simplifier = new SchemaSimplifier(['components' => ['schemas' => [
            'A' => ['type' => 'object', 'properties' => ['b' => ['$ref' => '#/components/schemas/B']]],
            'B' => ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/components/schemas/A']]],
        ]]]);

        self::assertIsArray($simplifier->simplify(['$ref' => '#/components/schemas/A']));
    }

    public function testWithDocumentReturnsAnIndependentInstance(): void
    {
        $original = new SchemaSimplifier(['components' => ['schemas' => ['T' => ['type' => 'integer']]]]);
        $replaced = $original->withDocument(['components' => ['schemas' => ['T' => ['type' => 'boolean']]]]);

        self::assertSame('integer', $original->simplify(['$ref' => '#/components/schemas/T'])['type']);
        self::assertSame('boolean', $replaced->simplify(['$ref' => '#/components/schemas/T'])['type']);
    }
}
