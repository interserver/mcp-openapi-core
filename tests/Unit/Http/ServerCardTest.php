<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\Http;

use InterServer\Mcp\Core\Http\ServerCard;
use InterServer\Mcp\Core\Profile\Profile;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\Http\ServerCard
 */
final class ServerCardTest extends TestCase
{
    private const ENDPOINT = 'https://mcp.interserver.net/client';

    private static function profile(bool $requiresAuth = true): Profile
    {
        return new Profile(
            name: 'client',
            specSource: '/dev/null',
            upstreamBaseUrl: 'https://my.test/apiv2',
            serverName: 'InterServer API',
            serverVersion: '1.0.0',
            requiresAuth: $requiresAuth,
            resourceIdentifiers: [self::ENDPOINT],
            servesServerCard: true,
            documentationUrl: 'https://my.interserver.net/api-docs/',
        );
    }

    /**
     * A `server/discover` result as the live server actually returns it — including
     * the five capabilities the old static card did not mention.
     *
     * @return array<string, mixed>
     */
    private static function discovery(): array
    {
        return [
            'supportedVersions' => ['2026-07-28'],
            'capabilities' => [
                'logging' => [],
                'completions' => [],
                'prompts' => [],
                'resources' => ['subscribe' => true],
                'tools' => [],
            ],
            'resultType' => 'complete',
            'ttlMs' => 3600000,
            'cacheScope' => 'private',
            '_meta' => [
                'io.modelcontextprotocol/serverInfo' => ['name' => 'InterServer API', 'version' => '1.0.0'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function card(?array $discovery = null, bool $requiresAuth = true): array
    {
        return ServerCard::build(
            self::profile($requiresAuth),
            self::ENDPOINT,
            $discovery ?? self::discovery(),
            'https://my.interserver.net/api-docs/',
            'https://mcp.interserver.net/.well-known/oauth-protected-resource/client',
        );
    }

    /**
     * Compare through JSON, because that is the only representation that matters
     * here: the card is published as a document, and an empty capability has to
     * survive as `{}` rather than degrading to `[]` on the way out.
     *
     * @return array<string, mixed>
     */
    private static function encodedCapabilities(?array $discovery = null): array
    {
        return json_decode((string) json_encode(self::card($discovery)['capabilities']), true);
    }

    public function testTheCapabilityBlockIsCopiedFromTheDiscoveryResult(): void
    {
        // The whole point of this class. The previous implementation declared
        // `tools` alone as a literal while the live server reported five
        // capabilities, so a client reading the card and a client calling the server
        // got different answers about the same server.
        self::assertSame(self::discovery()['capabilities'], self::encodedCapabilities());
    }

    public function testACapabilityWithNoSubOptionsSerialisesAsAnObject(): void
    {
        // `"logging": []` is not the same document as `"logging": {}` — the schema
        // requires an object, and a validating client rejects the array form. The
        // live server gets this right because the SDK serialises typed objects; only
        // this class's decode/re-encode round trip can lose it.
        $json = (string) json_encode(self::card()['capabilities']);

        self::assertStringContainsString('"logging":{}', $json);
        self::assertStringNotContainsString('"logging":[]', $json);
    }

    public function testANestedCapabilityOptionIsPreserved(): void
    {
        // The object conversion must not flatten a capability that does carry
        // options — resources.subscribe is the one this server actually reports.
        self::assertTrue(self::encodedCapabilities()['resources']['subscribe']);
    }

    public function testAListValuedFieldIsNotTurnedIntoAnObject(): void
    {
        // supportedVersions is genuinely a JSON array; the empty-array-to-object
        // rule must not reach it.
        self::assertSame(['2026-07-28'], self::card()['supportedVersions']);
    }

    public function testACapabilityAddedByTheServerAppearsWithoutTouchingThisClass(): void
    {
        // Proves the copy is structural rather than a fixed list that happens to
        // match today. If someone later re-introduces a hand-written capability
        // block, this is the test that fails.
        $discovery = self::discovery();
        $discovery['capabilities']['experimental'] = ['somethingNew' => true];

        self::assertArrayHasKey('experimental', self::encodedCapabilities($discovery));
    }

    public function testTheSchemaIsTheOneTheExtensionSpecifies(): void
    {
        // The old card pointed at a .../2025-03-26/server-card.json path that is not
        // where the schema lives.
        self::assertSame(
            'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json',
            self::card()['$schema'],
        );
    }

    public function testTheRemoteIsTheStreamableHttpEndpoint(): void
    {
        self::assertSame(
            [['type' => 'streamable-http', 'url' => self::ENDPOINT]],
            self::card()['remotes'],
        );
    }

    public function testServerNameAndVersionComeFromTheServerNotTheProfile(): void
    {
        // Same reasoning as the capabilities: the server is the authority on what it
        // calls itself, and the profile is only the fallback.
        $discovery = self::discovery();
        $discovery['_meta']['io.modelcontextprotocol/serverInfo'] = ['name' => 'Renamed', 'version' => '9.9.9'];
        $card = self::card($discovery);

        self::assertSame('Renamed', $card['name']);
        self::assertSame('9.9.9', $card['version']);
    }

    public function testTheProfileSuppliesNameAndVersionWhenTheServerDoesNot(): void
    {
        $discovery = self::discovery();
        unset($discovery['_meta']);

        self::assertSame('InterServer API', self::card($discovery)['name']);
    }

    public function testAnAuthenticatedSurfacePointsAtItsProtectedResourceMetadata(): void
    {
        // Without this a client has to provoke a 401 just to learn that an
        // authorization server exists.
        self::assertSame(
            'https://mcp.interserver.net/.well-known/oauth-protected-resource/client',
            self::card()['authentication']['protectedResourceMetadata'],
        );
    }

    public function testAnUnauthenticatedSurfaceDeclaresNoAuthentication(): void
    {
        // The public surface takes no credential; an `authentication` block there
        // would tell a client to go and get one it does not need.
        self::assertArrayNotHasKey('authentication', self::card(null, false));
    }

    public function testTheSupportedVersionsAreCarriedThrough(): void
    {
        self::assertSame(['2026-07-28'], self::card()['supportedVersions']);
    }

    public function testTheEtagChangesWhenTheCapabilitiesChange(): void
    {
        // Conditional GET is only safe if the tag actually tracks the content — a
        // stable ETag over changing content serves a stale card for an hour.
        $changed = self::discovery();
        $changed['capabilities']['tools'] = ['listChanged' => true];

        self::assertNotSame(ServerCard::etag(self::card()), ServerCard::etag(self::card($changed)));
    }

    public function testTheEtagIsStableForUnchangedContent(): void
    {
        self::assertSame(ServerCard::etag(self::card()), ServerCard::etag(self::card()));
    }

    public function testTheEtagIsQuotedAsHttpRequires(): void
    {
        $etag = ServerCard::etag(self::card());

        self::assertSame('"', $etag[0]);
        self::assertSame('"', $etag[\strlen($etag) - 1]);
    }
}
