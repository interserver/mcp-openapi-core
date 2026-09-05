<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Http;

use InterServer\Mcp\Core\Profile\Profile;

/**
 * The MCP server card (`experimental-ext-server-card`).
 *
 * Two things about the previous implementation were wrong, and both are fixed here
 * rather than carried across.
 *
 * **Placement.** It was served from `/.well-known/mcp/server-card`. The extension
 * reserves `<streamable-http-url>/server-card` — so `https://mcp.interserver.net/client/server-card`
 * — and explicitly lists the `/.well-known/` form as "considered and not
 * recommended". Routing is handled by {@see FrontController}; this class only builds
 * the document.
 *
 * **Content.** The old card declared its capabilities as a hand-written literal, and
 * it was wrong: it advertised `tools` alone while the live server reported `logging`,
 * `completions`, `prompts`, `resources` and `tools`. Here the capability block comes
 * from {@see \InterServer\Mcp\Core\Server\CapabilityProbe}, which dispatches a real
 * `server/discover` through the real server — so the card is a rendering of what the
 * server actually answers, not a second opinion about it.
 *
 * The `$schema` URL is likewise fixed at the value the extension specifies; the old
 * card pointed at a `2025-03-26` path that is not where the schema lives.
 */
final class ServerCard
{
    public const MEDIA_TYPE = 'application/mcp-server-card+json';

    public const SCHEMA = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json';

    /**
     * @param array<string, mixed> $discovery the `result` of a real `server/discover`
     *
     * @return array<string, mixed>
     */
    public static function build(
        Profile $profile,
        string $endpointUrl,
        array $discovery,
        ?string $documentationUrl = null,
        ?string $protectedResourceUrl = null,
    ): array {
        $serverInfo = $discovery['_meta']['io.modelcontextprotocol/serverInfo'] ?? [];

        $card = [
            '$schema' => self::SCHEMA,
            'name' => \is_array($serverInfo) && isset($serverInfo['name'])
                ? (string) $serverInfo['name']
                : $profile->serverName,
            'version' => \is_array($serverInfo) && isset($serverInfo['version'])
                ? (string) $serverInfo['version']
                : $profile->serverVersion,
            // Straight from the server. Never edit this into a literal: the whole
            // reason this class takes a discovery result rather than a capability
            // array is that a literal is a second source of truth, and the two
            // previous implementations proved it drifts.
            'capabilities' => $discovery['capabilities'] ?? new \stdClass(),
            'remotes' => [
                [
                    'type' => 'streamable-http',
                    'url' => $endpointUrl,
                ],
            ],
        ];

        if (isset($discovery['supportedVersions']) && \is_array($discovery['supportedVersions'])) {
            $card['supportedVersions'] = array_values($discovery['supportedVersions']);
        }

        if (null !== $documentationUrl) {
            $card['documentationUrl'] = $documentationUrl;
        }

        // A card for an authenticated surface says so, and says where the metadata
        // that describes the authentication lives. Without it a client has to
        // provoke a 401 to discover there is an authorization server at all.
        if ($profile->requiresAuth && null !== $protectedResourceUrl) {
            $card['authentication'] = [
                'type' => 'oauth2',
                'protectedResourceMetadata' => $protectedResourceUrl,
            ];
        }

        return $card;
    }

    /**
     * A strong ETag over the rendered document.
     *
     * The card changes only when the spec, the server version or the capability set
     * changes, so conditional requests are worth supporting: the extension asks for
     * `Cache-Control: public, max-age=3600` and clients do re-fetch.
     *
     * @param array<string, mixed> $card
     */
    public static function etag(array $card): string
    {
        return '"'.substr(hash('sha256', (string) json_encode($card)), 0, 32).'"';
    }
}
