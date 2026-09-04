<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\Profile;

use InterServer\Mcp\Core\Exception\UnknownProfileException;
use InterServer\Mcp\Core\Profile\Profile;
use InterServer\Mcp\Core\Profile\ProfileRegistry;
use InterServer\Mcp\Core\Profile\ProfileResolver;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\Profile\ProfileResolver
 * @covers \InterServer\Mcp\Core\Profile\ProfileRegistry
 * @covers \InterServer\Mcp\Core\Profile\Profile
 */
final class ProfileResolverTest extends TestCase
{
    private static function profile(string $name): Profile
    {
        return new Profile(
            name: $name, specSource: "/specs/{$name}.yaml",
            upstreamBaseUrl: 'https://my.test/apiv2', serverName: ucfirst($name),
        );
    }

    private static function registry(string ...$names): ProfileRegistry
    {
        return new ProfileRegistry(array_map(self::profile(...), $names));
    }

    /** The client+public app's real endpoint map. */
    private static function clientApp(): ProfileResolver
    {
        return new ProfileResolver(
            self::registry('client', 'public'),
            ['/client' => 'client', '/public' => 'public'],
            'client',
        );
    }

    // -------------------------------------------------------------- resolving

    public function testAMappedPathResolvesToItsProfile(): void
    {
        self::assertSame('client', self::clientApp()->resolveName('/client'));
        self::assertSame('public', self::clientApp()->resolveName('/public'));
    }

    public function testASubPathResolvesToTheSameProfile(): void
    {
        self::assertSame('public', self::clientApp()->resolveName('/public/server-card'));
    }

    /**
     * The bare host is an alias for the client profile, for users who paste it.
     */
    public function testTheRootPathFallsBackToTheDefault(): void
    {
        self::assertSame('client', self::clientApp()->resolveName('/'));
        self::assertSame('client', self::clientApp()->resolveName(''));
    }

    public function testAnUnmappedPathFallsBackToTheDefault(): void
    {
        self::assertSame('client', self::clientApp()->resolveName('/somewhere/else'));
    }

    public function testTrailingSlashesDoNotChangeTheResult(): void
    {
        self::assertSame('public', self::clientApp()->resolveName('/public/'));
    }

    public function testAQueryStringIsIgnored(): void
    {
        self::assertSame('public', self::clientApp()->resolveName('/public?renderer=x'));
    }

    /**
     * A prefix that merely shares characters is a different path: `/publicity`
     * is not under `/public`.
     */
    public function testPathMatchingIsSegmentWiseNotSubstringBased(): void
    {
        self::assertSame('client', self::clientApp()->resolveName('/publicity'));
    }

    /**
     * Longest match first, so the answer does not depend on the order the config
     * happened to list its rows in.
     */
    public function testTheLongestMatchingPrefixWins(): void
    {
        $resolver = new ProfileResolver(
            self::registry('outer', 'inner'),
            ['/mcp' => 'outer', '/mcp/inner' => 'inner'],
            'outer',
        );

        self::assertSame('inner', $resolver->resolveName('/mcp/inner'));
        self::assertSame('outer', $resolver->resolveName('/mcp/other'));
    }

    public function testOrderInTheConfigDoesNotMatter(): void
    {
        $reversed = new ProfileResolver(
            self::registry('outer', 'inner'),
            ['/mcp/inner' => 'inner', '/mcp' => 'outer'],
            'outer',
        );

        self::assertSame('inner', $reversed->resolveName('/mcp/inner'));
    }

    public function testResolveReturnsTheProfileForARequest(): void
    {
        $profile = self::clientApp()->resolve(new ServerRequest('POST', 'https://mcp.test/public'));

        self::assertSame('public', $profile->name);
    }

    /**
     * A single-profile app does almost nothing here, but it uses the same class so
     * a reader moving between the two apps is never surprised.
     */
    public function testASingleProfileAppAlwaysResolvesToItsOneProfile(): void
    {
        $adminApp = new ProfileResolver(self::registry('admin'), ['/' => 'admin'], 'admin');

        self::assertSame('admin', $adminApp->resolveName('/'));
        self::assertSame('admin', $adminApp->resolveName('/anything/at/all'));
    }

    // ------------------------------------------------------------- pathFor

    public function testPathForReturnsTheProfilesUrlPath(): void
    {
        self::assertSame('/public', self::clientApp()->pathFor('public'));
    }

    public function testPathForAnUnmappedProfileIsNull(): void
    {
        self::assertNull(self::clientApp()->pathFor('admin'));
    }

    // ------------------------------------------------------------- registry

    public function testAnUnknownProfileNamesTheKnownOnes(): void
    {
        $this->expectException(UnknownProfileException::class);
        $this->expectExceptionMessageMatches('/Known: client, public/');

        self::registry('client', 'public')->get('admin');
    }

    public function testHasReportsMembership(): void
    {
        $registry = self::registry('client');

        self::assertTrue($registry->has('client'));
        self::assertFalse($registry->has('admin'));
    }

    public function testFromArrayBuildsProfiles(): void
    {
        $registry = ProfileRegistry::fromArray([
            'public' => [
                'specSource' => 'https://my.test/spec/openapi.yaml',
                'upstreamBaseUrl' => 'https://my.test/apiv2',
                'serverName' => 'InterServer Public API',
                'requiresAuth' => false,
                'toolAllowlist' => ['getMPServers', 'getDomainSearch', 'getDomainLookup'],
            ],
        ]);

        $profile = $registry->get('public');

        self::assertSame('public', $profile->name);
        self::assertFalse($profile->requiresAuth);
        self::assertSame(['getMPServers', 'getDomainSearch', 'getDomainLookup'], $profile->toolAllowlist);
    }

    public function testFromArrayDefaultsToAnAuthenticatedProfileWithNoAllowlist(): void
    {
        $profile = ProfileRegistry::fromArray([
            'client' => ['specSource' => '/x.yaml', 'upstreamBaseUrl' => 'https://my.test/apiv2'],
        ])->get('client');

        self::assertTrue($profile->requiresAuth);
        self::assertNull($profile->toolAllowlist);
        self::assertSame('client', $profile->serverName);
    }

    // -------------------------------------------------------------- audience

    public function testAudienceComparisonIsExact(): void
    {
        $profile = new Profile(
            name: 'client', specSource: '/x', upstreamBaseUrl: 'https://my.test/apiv2', serverName: 'c',
            resourceIdentifiers: ['https://mcp.interserver.net/client'],
        );

        self::assertTrue($profile->acceptsAudience('https://mcp.interserver.net/client'));
        self::assertFalse($profile->acceptsAudience('https://mcp.interserver.net/client/'));
        self::assertFalse($profile->acceptsAudience('https://mcp.interserver.net'));
        self::assertFalse($profile->acceptsAudience(null));
        self::assertFalse($profile->acceptsAudience(''));
    }

    public function testAProfileWithNoIdentifiersAcceptsAnything(): void
    {
        $profile = self::profile('local');

        self::assertTrue($profile->acceptsAudience('anything'));
        self::assertTrue($profile->acceptsAudience(null));
    }
}
