<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\Http;

use InterServer\Mcp\Core\Http\ProtectedResourceMetadata;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\Http\ProtectedResourceMetadata
 */
final class ProtectedResourceMetadataTest extends TestCase
{
    private function client(): ProtectedResourceMetadata
    {
        return new ProtectedResourceMetadata(
            'https://mcp.interserver.net/client',
            'https://my.interserver.net',
            ['vps', 'vps:read', 'billing'],
        );
    }

    public function testTheDocumentNamesTheResourceExactly(): void
    {
        self::assertSame('https://mcp.interserver.net/client', $this->client()->toArray()['resource']);
    }

    /**
     * Claude uses the first entry and does not fall back, so a second one would be
     * decoration that reads like a failover.
     */
    public function testExactlyOneAuthorizationServerIsAdvertised(): void
    {
        self::assertSame(['https://my.interserver.net'], $this->client()->toArray()['authorization_servers']);
    }

    public function testBearerHeaderIsTheAdvertisedMethod(): void
    {
        self::assertSame(['header'], $this->client()->toArray()['bearer_methods_supported']);
    }

    public function testScopesAreAdvertisedWhenKnown(): void
    {
        self::assertSame(['vps', 'vps:read', 'billing'], $this->client()->toArray()['scopes_supported']);
    }

    public function testScopesAreOmittedWhenEmpty(): void
    {
        $metadata = new ProtectedResourceMetadata('https://mcp.test/', 'https://as.test');

        self::assertArrayNotHasKey('scopes_supported', $metadata->toArray());
    }

    // ---------------------------------------------------------------- the path

    /**
     * RFC 9728 §3.1 inserts the resource path *after* the well-known segment.
     * Getting this backwards is the classic mistake and produces a 404 that looks
     * like a routing problem.
     */
    public function testTheMetadataPathInsertsTheResourcePathAfterTheWellKnownSegment(): void
    {
        self::assertSame(
            '/.well-known/oauth-protected-resource/client',
            ProtectedResourceMetadata::pathFor('/client'),
        );
    }

    public function testTheRootResourceHasNoTrailingPath(): void
    {
        self::assertSame(
            '/.well-known/oauth-protected-resource',
            ProtectedResourceMetadata::pathFor('/'),
        );
    }

    public function testALeadingSlashIsOptional(): void
    {
        self::assertSame(
            ProtectedResourceMetadata::pathFor('/client'),
            ProtectedResourceMetadata::pathFor('client'),
        );
    }

    /**
     * The document lives on the *resource's* host. With two servers on two hosts
     * and the authorization server on a third, neither can borrow the other's.
     */
    public function testTheMetadataUrlIsOnTheResourcesOwnHost(): void
    {
        self::assertSame(
            'https://mcp.interserver.net/.well-known/oauth-protected-resource/client',
            $this->client()->metadataUrl(),
        );
    }

    public function testTheAdminResourceGetsItsOwnMetadataUrl(): void
    {
        $admin = new ProtectedResourceMetadata('https://adminmcp.interserver.net/', 'https://my.interserver.net/admin');

        self::assertSame(
            'https://adminmcp.interserver.net/.well-known/oauth-protected-resource',
            $admin->metadataUrl(),
        );
    }

    public function testANonStandardPortIsPreserved(): void
    {
        $staging = new ProtectedResourceMetadata('https://mcpstage.test:8443/client', 'https://as.test');

        self::assertSame(
            'https://mcpstage.test:8443/.well-known/oauth-protected-resource/client',
            $staging->metadataUrl(),
        );
    }

    // ------------------------------------------------------------- challenge

    public function testTheChallengePointsAtTheMetadataDocument(): void
    {
        $challenge = $this->client()->challenge('interserver-client');

        self::assertStringStartsWith('Bearer ', $challenge);
        self::assertStringContainsString('realm="interserver-client"', $challenge);
        self::assertStringContainsString(
            'resource_metadata="https://mcp.interserver.net/.well-known/oauth-protected-resource/client"',
            $challenge,
        );
    }

    public function testTheChallengeCarriesTheErrorCode(): void
    {
        self::assertStringContainsString(
            'error="invalid_token"',
            $this->client()->challenge('r', 'invalid_token'),
        );
    }

    /**
     * Claude caches the challenged scope per user per server for ~15 minutes and
     * does not reliably carry earlier step-up scopes forward, so a challenge names
     * the whole union rather than the one scope this call happened to miss.
     */
    public function testAStepUpChallengeNamesTheWholeScopeUnion(): void
    {
        $challenge = $this->client()->challenge('r', 'insufficient_scope', ['billing', 'vps', 'vps:read']);

        self::assertStringContainsString('scope="billing vps vps:read"', $challenge);
    }

    public function testNoScopeParameterWhenThereAreNoScopes(): void
    {
        self::assertStringNotContainsString('scope=', $this->client()->challenge('r', 'invalid_token'));
    }
}
