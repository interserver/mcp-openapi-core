<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Http;

use InterServer\Mcp\Core\Profile\Profile;

/**
 * RFC 9728 protected-resource metadata, and the `WWW-Authenticate` challenge
 * that points at it.
 *
 * Two details here decide whether a connector works in Claude at all:
 *
 *  - **`resource` must equal the URL the user typed, exactly, path included.**
 *    Claude compares literally. A trailing slash is a different resource.
 *  - **The metadata document lives on the resource's own host**, at
 *    `/.well-known/oauth-protected-resource<path>`. With the servers moving to
 *    `mcp.interserver.net` and `adminmcp.interserver.net`, neither can borrow the
 *    other's and neither lives on the authorization server's host.
 *
 * `authorization_servers` carries one entry because Claude uses the first and
 * does not fall back.
 */
final class ProtectedResourceMetadata
{
    /**
     * @param list<string> $scopesSupported
     */
    public function __construct(
        private readonly string $resource,
        private readonly string $authorizationServer,
        private readonly array $scopesSupported = [],
        private readonly ?string $documentationUrl = null,
    ) {
    }

    /**
     * @param list<string> $scopes
     */
    public static function forProfile(Profile $profile, string $resourceUrl, string $authorizationServer, array $scopes = []): self
    {
        return new self($resourceUrl, $authorizationServer, $scopes);
    }

    /**
     * The URL path this resource's metadata document is served from.
     *
     * RFC 9728 §3.1 inserts the resource's path *after* the well-known segment —
     * not before it, and not as a query. A resource at `https://host/client` is
     * described at `https://host/.well-known/oauth-protected-resource/client`.
     */
    public static function pathFor(string $resourcePath): string
    {
        $resourcePath = '/'.ltrim($resourcePath, '/');

        return '/.well-known/oauth-protected-resource'.rtrim($resourcePath, '/');
    }

    public function metadataUrl(): string
    {
        $parts = parse_url($this->resource);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '')
            .(isset($parts['port']) ? ':'.$parts['port'] : '');

        return $origin.self::pathFor($parts['path'] ?? '/');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $document = [
            'resource' => $this->resource,
            // Claude uses the first entry and does not fall back, so a list here
            // is a list of one.
            'authorization_servers' => [$this->authorizationServer],
            'bearer_methods_supported' => ['header'],
        ];

        if ([] !== $this->scopesSupported) {
            $document['scopes_supported'] = $this->scopesSupported;
        }
        if (null !== $this->documentationUrl) {
            $document['resource_documentation'] = $this->documentationUrl;
        }

        return $document;
    }

    /**
     * The `WWW-Authenticate` value for a 401.
     *
     * This has to be an HTTP header on an HTTP status. A `200` carrying
     * `{"result":{"isError":true}}` is handed to the model as text — no Connect
     * card, no auth prompt — which is the single most common way a connector
     * silently does nothing.
     *
     * @param list<string> $scopes
     */
    public function challenge(string $realm, ?string $error = null, array $scopes = []): string
    {
        $parts = [\sprintf('realm="%s"', $realm)];

        if (null !== $error) {
            $parts[] = \sprintf('error="%s"', $error);
        }

        $parts[] = \sprintf('resource_metadata="%s"', $this->metadataUrl());

        if ([] !== $scopes) {
            // Always the union, never just the scope this call missed: Claude
            // caches the challenged scope per user per server for ~15 minutes and
            // does not reliably carry earlier step-up scopes forward.
            $parts[] = \sprintf('scope="%s"', implode(' ', $scopes));
        }

        return 'Bearer '.implode(', ', $parts);
    }
}
