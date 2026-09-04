<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\Auth;

use InterServer\Mcp\Core\Auth\ScopeMap;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\Auth\ScopeMap
 */
final class ScopeMapTest extends TestCase
{
    private const ADMIN_SECTIONS = [
        'accounts' => 'account', 'billing' => 'billing', 'vps' => 'vps',
        'storages' => 'webhosting', 'qs' => 'quickservers',
    ];

    private const CLIENT_SECTIONS = [
        'account' => 'account', 'billing' => 'billing', 'vps' => 'vps',
        'websites' => 'webhosting', 'qs' => 'quickservers',
    ];

    private function admin(): ScopeMap
    {
        return ScopeMap::forAdmin(self::ADMIN_SECTIONS);
    }

    private function client(): ScopeMap
    {
        return ScopeMap::forClient(self::CLIENT_SECTIONS);
    }

    // ------------------------------------------------------------------ admin

    public function testAdminGetRequiresTheReadVariant(): void
    {
        self::assertSame('vps:read', $this->admin()->requiredScope('/admin/vps/{id}', 'GET'));
    }

    /**
     * @dataProvider writeMethodProvider
     */
    public function testAdminWriteMethodsRequireTheBareModuleScope(string $method): void
    {
        self::assertSame('vps', $this->admin()->requiredScope('/admin/vps/{id}', $method));
    }

    public static function writeMethodProvider(): \Generator
    {
        yield 'POST' => ['POST'];
        yield 'PUT' => ['PUT'];
        yield 'PATCH' => ['PATCH'];
        yield 'DELETE' => ['DELETE'];
    }

    public function testAdminSectionsCanAliasOntoOneModule(): void
    {
        self::assertSame('webhosting:read', $this->admin()->requiredScope('/admin/storages/{id}', 'GET'));
        self::assertSame('quickservers', $this->admin()->requiredScope('/admin/qs/{id}', 'POST'));
    }

    /**
     * Fail closed: a newly added admin endpoint is gated by the blanket `admin`
     * scope until somebody deliberately maps its section.
     */
    public function testUnmappedAdminSectionsFallBackToTheBlanketScope(): void
    {
        self::assertSame('admin', $this->admin()->requiredScope('/admin/config/settings', 'GET'));
        self::assertSame('admin', $this->admin()->requiredScope('/admin/reports/revenue', 'GET'));
    }

    /**
     * The superscope already covers everything, so a `:read` variant of it would
     * be a scope nothing ever grants.
     */
    public function testTheSuperScopeHasNoReadVariant(): void
    {
        self::assertSame('admin', $this->admin()->requiredScope('/admin/config', 'GET'));
        self::assertSame('admin', $this->admin()->requiredScope('/admin/config', 'DELETE'));
    }

    public function testAdminPathsAreMatchedCaseInsensitively(): void
    {
        self::assertSame('vps:read', $this->admin()->requiredScope('/Admin/VPS/{id}', 'GET'));
    }

    public function testMethodIsMatchedCaseInsensitively(): void
    {
        self::assertSame('vps:read', $this->admin()->requiredScope('/admin/vps', 'get'));
    }

    public function testASectionMatchesWithNoTrailingSegment(): void
    {
        self::assertSame('vps:read', $this->admin()->requiredScope('/admin/vps', 'GET'));
    }

    /**
     * A non-admin path handed to the admin map has no section, so it falls back —
     * which for the admin surface means the blanket scope, not "public".
     */
    public function testANonAdminPathOnTheAdminMapStillRequiresTheBlanketScope(): void
    {
        self::assertSame('admin', $this->admin()->requiredScope('/vps/{id}', 'GET'));
    }

    // ----------------------------------------------------------------- client

    public function testClientGetRequiresTheReadVariant(): void
    {
        self::assertSame('vps:read', $this->client()->requiredScope('/vps/{id}', 'GET'));
    }

    public function testClientWriteRequiresTheBareModuleScope(): void
    {
        self::assertSame('vps', $this->client()->requiredScope('/vps/{id}/start', 'POST'));
    }

    public function testTheApiv2PrefixIsOptional(): void
    {
        self::assertSame('vps:read', $this->client()->requiredScope('/apiv2/vps/{id}', 'GET'));
        self::assertSame('vps:read', $this->client()->requiredScope('vps/{id}', 'GET'));
    }

    /**
     * `/login`, `/info`, `/ping` and friends are reachable by any authenticated
     * token — the client surface fails *open* on an unmapped section, unlike admin.
     */
    public function testUnmappedClientSectionsRequireNoScope(): void
    {
        self::assertNull($this->client()->requiredScope('/login', 'POST'));
        self::assertNull($this->client()->requiredScope('/ping', 'GET'));
        self::assertNull($this->client()->requiredScope('/info', 'GET'));
    }

    public function testClientSectionsCanAliasOntoOneModule(): void
    {
        self::assertSame('webhosting:read', $this->client()->requiredScope('/websites/{id}', 'GET'));
    }

    // ---------------------------------------------------- the two, contrasted

    /**
     * The single behavioural difference between the surfaces, stated directly:
     * the same unmapped path is blanket-gated on admin and ungated on client.
     */
    public function testTheTwoSurfacesDifferOnlyInTheirUnmappedFallback(): void
    {
        self::assertSame('admin', $this->admin()->requiredScope('/admin/unmapped', 'GET'));
        self::assertNull($this->client()->requiredScope('/unmapped', 'GET'));
    }

    // ------------------------------------------------------------ accessors

    public function testSectionsAreExposed(): void
    {
        self::assertSame(self::ADMIN_SECTIONS, $this->admin()->sections());
    }

    public function testTheSuperScopeIsConfigurable(): void
    {
        $map = ScopeMap::forAdmin(['vps' => 'vps'], 'root');

        self::assertSame('root', $map->superScope());
        self::assertSame('root', $map->requiredScope('/admin/unmapped', 'GET'));
    }

    public function testACustomMapCanBeBuiltDirectly(): void
    {
        $map = new ScopeMap(['thing' => 'thing'], '#^/svc/([a-z_]+)#', 'fallback', 'super');

        self::assertSame('thing:read', $map->requiredScope('/svc/thing/1', 'GET'));
        self::assertSame('fallback', $map->requiredScope('/svc/other', 'POST'));
    }

    /**
     * A fallback scope that is not the superscope is an ordinary module scope and
     * gets the read variant like any other. Only the superscope is exempt, because
     * only the superscope already implies read.
     */
    public function testANonSuperScopeFallbackStillGetsAReadVariant(): void
    {
        $map = new ScopeMap(['thing' => 'thing'], '#^/svc/([a-z_]+)#', 'fallback', 'super');

        self::assertSame('fallback:read', $map->requiredScope('/svc/other', 'GET'));
    }
}
