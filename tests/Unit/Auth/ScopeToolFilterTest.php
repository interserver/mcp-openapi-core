<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\Auth;

use InterServer\Mcp\Core\Auth\ScopeMap;
use InterServer\Mcp\Core\Auth\ScopeToolFilter;
use InterServer\Mcp\Core\OpenApi\ToolDefinition;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\Auth\ScopeToolFilter
 */
final class ScopeToolFilterTest extends TestCase
{
    private static function tool(string $name, string $path, string $method = 'GET'): ToolDefinition
    {
        return new ToolDefinition(
            name: $name, description: '', httpMethod: $method, path: $path,
            inputSchema: ['type' => 'object'], pathParams: [], queryParams: [], hasBody: false,
        );
    }

    private static function adminMap(): ScopeMap
    {
        return ScopeMap::forAdmin(['vps' => 'vps', 'billing' => 'billing', 'accounts' => 'account']);
    }

    private static function clientMap(): ScopeMap
    {
        return ScopeMap::forClient(['vps' => 'vps', 'billing' => 'billing']);
    }

    /** @param list<ToolDefinition> $tools @return list<string> */
    private static function names(array $tools): array
    {
        return array_map(static fn (ToolDefinition $t): string => $t->name, $tools);
    }

    // ---------------------------------------------------------------- hasScope

    public function testAnEmptyRequirementIsAlwaysSatisfied(): void
    {
        self::assertTrue(ScopeToolFilter::hasScope('', null));
        self::assertTrue(ScopeToolFilter::hasScope('', ''));
        self::assertTrue(ScopeToolFilter::hasScope('vps', null));
    }

    public function testAnExactScopeMatches(): void
    {
        self::assertTrue(ScopeToolFilter::hasScope('vps billing', 'vps'));
    }

    public function testAMissingScopeDoesNotMatch(): void
    {
        self::assertFalse(ScopeToolFilter::hasScope('billing', 'vps'));
    }

    public function testTheSuperScopeGrantsEverything(): void
    {
        self::assertTrue(ScopeToolFilter::hasScope('admin', 'vps'));
        self::assertTrue(ScopeToolFilter::hasScope('admin', 'anything:read'));
    }

    public function testAWriteScopeSubsumesItsReadVariant(): void
    {
        self::assertTrue(ScopeToolFilter::hasScope('vps', 'vps:read'));
    }

    /**
     * The direction that must never hold: a read grant is not a write grant.
     */
    public function testAReadScopeDoesNotGrantWrite(): void
    {
        self::assertFalse(ScopeToolFilter::hasScope('vps:read', 'vps'));
    }

    public function testScopesFromOtherModulesDoNotLeakAcross(): void
    {
        self::assertFalse(ScopeToolFilter::hasScope('billing billing:read', 'vps:read'));
    }

    public function testScopeStringsAreSplitOnAnyWhitespace(): void
    {
        self::assertTrue(ScopeToolFilter::hasScope("vps\tbilling\n domains", 'domains'));
    }

    public function testExtraWhitespaceIsIgnored(): void
    {
        self::assertTrue(ScopeToolFilter::hasScope('   vps    billing   ', 'vps'));
    }

    public function testAListOfScopesIsAccepted(): void
    {
        self::assertTrue(ScopeToolFilter::hasScope(['vps', 'billing'], 'vps'));
        self::assertFalse(ScopeToolFilter::hasScope(['billing'], 'vps'));
    }

    public function testAnEmptyGrantSatisfiesNothing(): void
    {
        self::assertFalse(ScopeToolFilter::hasScope('', 'vps'));
        self::assertFalse(ScopeToolFilter::hasScope([], 'vps'));
    }

    public function testTheSuperScopeNameIsConfigurable(): void
    {
        self::assertTrue(ScopeToolFilter::hasScope('root', 'vps', 'root'));
        self::assertFalse(ScopeToolFilter::hasScope('admin', 'vps', 'root'));
    }

    /**
     * A scope named like a substring of another must not match it: `vps` is not
     * granted by `vpsadmin`.
     */
    public function testScopeMatchingIsNotSubstringBased(): void
    {
        self::assertFalse(ScopeToolFilter::hasScope('vpsadmin', 'vps'));
        self::assertFalse(ScopeToolFilter::hasScope('administrator', 'vps'));
    }

    // ------------------------------------------------------------------ filter

    public function testFilteringKeepsOnlyGrantedTools(): void
    {
        $tools = [
            self::tool('listVps', '/admin/vps'),
            self::tool('deleteVps', '/admin/vps/{id}', 'DELETE'),
            self::tool('listInvoices', '/admin/billing'),
        ];

        $filtered = ScopeToolFilter::filter($tools, 'vps:read', self::adminMap());

        self::assertSame(['listVps'], self::names($filtered));
    }

    public function testAWriteScopeKeepsBothReadAndWriteTools(): void
    {
        $tools = [
            self::tool('listVps', '/admin/vps'),
            self::tool('deleteVps', '/admin/vps/{id}', 'DELETE'),
        ];

        self::assertSame(['listVps', 'deleteVps'], self::names(ScopeToolFilter::filter($tools, 'vps', self::adminMap())));
    }

    public function testTheSuperScopeKeepsEverything(): void
    {
        $tools = [
            self::tool('listVps', '/admin/vps'),
            self::tool('listInvoices', '/admin/billing'),
            self::tool('getConfig', '/admin/config'),
        ];

        self::assertCount(3, ScopeToolFilter::filter($tools, 'admin', self::adminMap()));
    }

    /**
     * The leak the admin surface's fail-closed fallback exists to stop: an
     * unmapped admin section must not be reachable on a module scope.
     */
    public function testUnmappedAdminToolsAreHiddenFromAModuleScopedToken(): void
    {
        $tools = [self::tool('getConfig', '/admin/config'), self::tool('listVps', '/admin/vps')];

        self::assertSame(['listVps'], self::names(ScopeToolFilter::filter($tools, 'vps', self::adminMap())));
    }

    /**
     * The client surface's counterpart: unmapped sections are public endpoints and
     * stay visible to any authenticated token.
     */
    public function testUnmappedClientToolsSurviveFiltering(): void
    {
        $tools = [self::tool('ping', '/ping'), self::tool('login', '/login', 'POST'), self::tool('listVps', '/vps')];

        self::assertSame(['ping', 'login'], self::names(ScopeToolFilter::filter($tools, '', self::clientMap())));
    }

    public function testFilteringAnEmptyCatalogueIsEmpty(): void
    {
        self::assertSame([], ScopeToolFilter::filter([], 'admin', self::adminMap()));
    }

    public function testFilteringReindexesTheResult(): void
    {
        $tools = [
            self::tool('listInvoices', '/admin/billing'),
            self::tool('listVps', '/admin/vps'),
        ];

        $filtered = ScopeToolFilter::filter($tools, 'vps', self::adminMap());

        self::assertSame([0], array_keys($filtered));
    }

    // ----------------------------------------------------- requiredScopeUnion

    /**
     * Claude caches the `scope` from an insufficient-scope 403 per user per server
     * for ~15 minutes and does not reliably carry earlier step-up scopes forward,
     * so a challenge must name the whole set, not just the scope this call missed.
     */
    public function testTheUnionNamesEveryScopeTheCatalogueNeeds(): void
    {
        $tools = [
            self::tool('listVps', '/admin/vps'),
            self::tool('deleteVps', '/admin/vps/{id}', 'DELETE'),
            self::tool('listInvoices', '/admin/billing'),
        ];

        self::assertSame(
            ['billing:read', 'vps', 'vps:read'],
            ScopeToolFilter::requiredScopeUnion($tools, self::adminMap()),
        );
    }

    public function testTheUnionIsSortedAndDeduplicated(): void
    {
        $tools = [
            self::tool('a', '/admin/vps'),
            self::tool('b', '/admin/vps'),
            self::tool('c', '/admin/accounts'),
        ];

        self::assertSame(['account:read', 'vps:read'], ScopeToolFilter::requiredScopeUnion($tools, self::adminMap()));
    }

    public function testTheUnionOmitsToolsThatNeedNoScope(): void
    {
        $tools = [self::tool('ping', '/ping'), self::tool('listVps', '/vps')];

        self::assertSame(['vps:read'], ScopeToolFilter::requiredScopeUnion($tools, self::clientMap()));
    }

    public function testTheUnionOfAnEmptyCatalogueIsEmpty(): void
    {
        self::assertSame([], ScopeToolFilter::requiredScopeUnion([], self::adminMap()));
    }
}
