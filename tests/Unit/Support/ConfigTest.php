<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\Support;

use InterServer\Mcp\Core\Support\Config;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\Support\Config
 */
final class ConfigTest extends TestCase
{
    public function testAValueIsReturned(): void
    {
        self::assertSame('x', (new Config(['A' => 'x']))->get('A'));
    }

    public function testAMissingValueFallsBackToTheDefault(): void
    {
        self::assertSame('fallback', (new Config())->get('A', 'fallback'));
        self::assertNull((new Config())->get('A'));
    }

    /**
     * MCPB and several container runtimes substitute `""` for a blank optional
     * setting, and `??` does not catch that. The origin repos fixed this in the
     * admin build and never backported it to the client — one of the concrete
     * drifts this shared package exists to prevent.
     */
    public function testAnEmptyValueIsTreatedAsAbsent(): void
    {
        self::assertSame('fallback', (new Config(['A' => '']))->get('A', 'fallback'));
        self::assertSame('fallback', (new Config(['A' => '   ']))->get('A', 'fallback'));
    }

    public function testARequiredValueIsReturned(): void
    {
        self::assertSame('x', (new Config(['A' => 'x']))->require('A'));
    }

    /**
     * A missing required setting must stop the deployment, not degrade it: a
     * server that starts with no introspection endpoint looks healthy and rejects
     * every token.
     */
    public function testAMissingRequiredValueThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Required configuration "A"/');

        (new Config())->require('A');
    }

    public function testAnEmptyRequiredValueAlsoThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        (new Config(['A' => '']))->require('A');
    }

    /**
     * @dataProvider truthyProvider
     */
    public function testBooleansAcceptTheUsualSpellings(string $value, bool $expected): void
    {
        self::assertSame($expected, (new Config(['A' => $value]))->bool('A'));
    }

    public static function truthyProvider(): \Generator
    {
        yield '1' => ['1', true];
        yield 'true' => ['true', true];
        yield 'TRUE' => ['TRUE', true];
        yield 'yes' => ['yes', true];
        yield 'on' => ['on', true];
        yield '0' => ['0', false];
        yield 'false' => ['false', false];
        yield 'nonsense' => ['banana', false];
    }

    public function testAnAbsentBooleanUsesItsDefault(): void
    {
        self::assertTrue((new Config())->bool('A', true));
        self::assertFalse((new Config())->bool('A', false));
    }

    public function testIntegersAreParsed(): void
    {
        self::assertSame(42, (new Config(['A' => '42']))->int('A', 7));
    }

    public function testANonNumericIntegerFallsBackRatherThanBecomingZero(): void
    {
        self::assertSame(7, (new Config(['A' => 'banana']))->int('A', 7));
        self::assertSame(7, (new Config(['A' => '']))->int('A', 7));
    }
}
