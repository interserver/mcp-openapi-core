<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\OpenApi;

use InterServer\Mcp\Core\OpenApi\DestructiveClassifier;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\OpenApi\DestructiveClassifier
 */
final class DestructiveClassifierTest extends TestCase
{
    public function testDeleteIsAlwaysDestructive(): void
    {
        self::assertTrue((new DestructiveClassifier())->isDestructive('DELETE', '/anything/harmless'));
    }

    public function testPlainGetIsNotDestructive(): void
    {
        self::assertFalse((new DestructiveClassifier())->isDestructive('GET', '/vps/{id}'));
    }

    public function testPlainPostIsNotDestructive(): void
    {
        self::assertFalse((new DestructiveClassifier())->isDestructive('POST', '/tickets'));
    }

    /**
     * @dataProvider destructiveTermProvider
     */
    public function testWriteMethodsOnDestructiveTermsAreFlagged(string $term): void
    {
        $classifier = new DestructiveClassifier();

        foreach (['POST', 'PUT', 'PATCH'] as $method) {
            self::assertTrue(
                $classifier->isDestructive($method, "/vps/{id}/{$term}"),
                "{$method} on a path containing '{$term}' should be destructive"
            );
        }
    }

    public static function destructiveTermProvider(): \Generator
    {
        foreach (DestructiveClassifier::DEFAULT_PATH_TERMS as $term) {
            yield $term => [$term];
        }
    }

    /**
     * These are real endpoints that mutate on a GET. A model told "GET is safe"
     * will call them speculatively.
     *
     * @dataProvider unsafeGetTermProvider
     */
    public function testKnownSideEffectingGetsAreFlagged(string $term): void
    {
        self::assertTrue((new DestructiveClassifier())->isDestructive('GET', "/mail/{id}/{$term}"));
    }

    public static function unsafeGetTermProvider(): \Generator
    {
        foreach (DestructiveClassifier::DEFAULT_UNSAFE_GET_TERMS as $term) {
            yield $term => [$term];
        }
    }

    public function testGetOnADestructiveTermThatIsSafeOnReadIsNotFlagged(): void
    {
        // "cancel" is destructive to POST but a GET on it is a status read.
        self::assertFalse((new DestructiveClassifier())->isDestructive('GET', '/billing/{id}/cancel'));
        self::assertTrue((new DestructiveClassifier())->isDestructive('POST', '/billing/{id}/cancel'));
    }

    public function testMatchingIsCaseInsensitiveOnThePath(): void
    {
        self::assertTrue((new DestructiveClassifier())->isDestructive('POST', '/Vps/{id}/REINSTALL'));
    }

    public function testMethodIsMatchedCaseInsensitively(): void
    {
        self::assertTrue((new DestructiveClassifier())->isDestructive('delete', '/x'));
        self::assertTrue((new DestructiveClassifier())->isDestructive('post', '/x/cancel'));
    }

    // --------------------------------------------------------- injected rules

    public function testPathPrefixRulesApplyToEveryMethod(): void
    {
        $classifier = new DestructiveClassifier(pathPrefixes: ['/admin/orders/']);

        self::assertTrue($classifier->isDestructive('GET', '/admin/orders/vps'));
        self::assertTrue($classifier->isDestructive('POST', '/admin/orders/vps'));
    }

    public function testPathPrefixMatchingIsCaseInsensitive(): void
    {
        $classifier = new DestructiveClassifier(pathPrefixes: ['/Admin/Orders/']);

        self::assertTrue($classifier->isDestructive('GET', '/admin/orders/vps'));
    }

    public function testOperationIdPatternsApply(): void
    {
        $classifier = new DestructiveClassifier(
            operationIdPatterns: ['/^admin(Cancel|Delete|Refund|ForceDelete)/i']
        );

        self::assertTrue($classifier->isDestructive('POST', '/admin/x', 'adminForceDeleteThing'));
        self::assertTrue($classifier->isDestructive('POST', '/admin/x', 'AdminRefundInvoice'));
        self::assertFalse($classifier->isDestructive('POST', '/admin/x', 'adminListThings'));
    }

    public function testAnEmptyOperationIdSkipsThePatternRules(): void
    {
        $classifier = new DestructiveClassifier(operationIdPatterns: ['/^/']);

        self::assertFalse($classifier->isDestructive('GET', '/harmless', ''));
    }

    /**
     * The defect this whole class exists to prevent: in the origin repos both the
     * client and admin builds carried `/admin/orders/` and the
     * `^admin(Cancel|Delete|…)` regex, so the client shipped logic that could never
     * match a single one of its own paths.
     */
    public function testDefaultsCarryNoSurfaceSpecificRules(): void
    {
        $classifier = new DestructiveClassifier();

        self::assertFalse($classifier->isDestructive('GET', '/admin/orders/vps', 'adminOrderVps'));
        self::assertFalse($classifier->isDestructive('POST', '/admin/x', 'adminForceDeleteThing'));
    }

    public function testTermListCanBeReplacedEntirely(): void
    {
        $classifier = new DestructiveClassifier(pathTerms: ['nuke'], unsafeGetTerms: []);

        self::assertTrue($classifier->isDestructive('POST', '/x/nuke'));
        self::assertFalse($classifier->isDestructive('POST', '/x/cancel'));
    }

    public function testFromArrayBuildsTheSameClassifier(): void
    {
        $config = [
            'pathPrefixes' => ['/admin/orders/'],
            'pathTerms' => ['nuke'],
            'unsafeGetTerms' => ['nuke'],
            'operationIdPatterns' => ['/^adminWipe/'],
        ];

        $classifier = DestructiveClassifier::fromArray($config);

        self::assertTrue($classifier->isDestructive('GET', '/admin/orders/x'));
        self::assertTrue($classifier->isDestructive('GET', '/x/nuke'));
        self::assertTrue($classifier->isDestructive('POST', '/x', 'adminWipeAll'));
        self::assertFalse($classifier->isDestructive('POST', '/x/cancel'));
    }

    public function testFromArrayFallsBackToDefaults(): void
    {
        $classifier = DestructiveClassifier::fromArray([]);

        self::assertTrue($classifier->isDestructive('POST', '/x/cancel'));
        self::assertFalse($classifier->isDestructive('GET', '/admin/orders/x'));
    }
}
