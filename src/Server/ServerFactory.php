<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Server;

use GuzzleHttp\ClientInterface;
use InterServer\Mcp\Core\Auth\AuthContext;
use InterServer\Mcp\Core\OpenApi\ToolCache;
use InterServer\Mcp\Core\Profile\Profile;
use Mcp\Schema\Enum\CacheScope;
use Mcp\Server;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Wire\CachePolicy;
use Psr\Log\LoggerInterface;

/**
 * Builds a configured MCP {@see Server} for one profile and one caller.
 *
 * The server is per-request because the tool catalogue is per-authorization:
 * MCP 2026-07-28 forbids the tool set varying per *connection* but explicitly
 * allows it to vary by presented authorization, which is what the scope filter
 * does. Everything expensive sits behind {@see ToolCache}, so building one costs
 * the ~14 ms of registration rather than the ~593 ms of a YAML parse.
 */
final class ServerFactory
{
    /**
     * The SDK paginates lists at 50 by default. The admin spec is ~445 tools and
     * Claude's client does not reliably walk the `cursor` chain, so a paginated
     * `tools/list` silently truncates the catalogue to the first page. Returning
     * everything in one page is the lesser evil until clients page reliably.
     */
    public const PAGINATION_LIMIT = 1000;

    /**
     * How long a client may treat a tool catalogue as fresh.
     *
     * The catalogue changes only when the OpenAPI spec is redeployed, so an hour
     * is generous but not wrong. The scope is unconditionally `private`: a
     * filtered list is derived from the caller's own token, and a shared cache
     * serving one caller's catalogue to another would leak which tools — and so
     * which entitlements — that first caller has.
     */
    public const TOOL_LIST_TTL_MS = 3_600_000;

    /**
     * `server/discover` answers with the supported revisions and the capability
     * set — both fixed by the deployed build, neither caller-specific. It is still
     * scoped `private`: making it `public` would let a shared proxy serve one
     * caller's answer to another, and that is a disclosure decision for the
     * operator, not a default.
     */
    public const DISCOVER_TTL_MS = 3_600_000;

    public function __construct(
        private readonly ToolCache $cache,
        private readonly ?LoggerInterface $logger = null,
        /**
         * HTTP client for the upstream REST API. Supplied by the application so
         * connection pooling, proxy settings and instrumentation are configured
         * once; left null, each UpstreamClient builds its own.
         */
        private readonly ?ClientInterface $upstreamHttp = null,
    ) {
    }

    public function build(
        Profile $profile,
        AuthContext $auth,
        ?SessionStoreInterface $sessionStore = null,
    ): Server {
        $builder = Server::builder()
            ->setServerInfo($profile->serverName, $profile->serverVersion)
            ->setPaginationLimit(self::PAGINATION_LIMIT)
            // The modern era requires ttlMs and cacheScope on every cacheable
            // result. The SDK's default is `0, private` — conformant, and a flat
            // refusal to cache anything, which costs a client a full re-list on
            // every connection.
            ->setCachePolicy(
                CachePolicy::none()
                    ->withMethod('tools/list', self::TOOL_LIST_TTL_MS, CacheScope::Private)
                    ->withMethod('server/discover', self::DISCOVER_TTL_MS, CacheScope::Private)
            )
            ->addLoader($this->loader($profile, $auth));

        if (null !== $sessionStore) {
            // Mandatory under PHP-FPM for the handshake era. With the SDK's default
            // InMemorySessionStore every request is a fresh process, so the session
            // created by `initialize` is gone by the next call and every subsequent
            // request fails with "Session not found or has expired" — the official
            // conformance suite fails every scenario. The modern era needs no
            // session at all, but both servers serve both eras.
            $builder->setSession($sessionStore);
        }

        if (null !== $this->logger) {
            $builder->setLogger($this->logger);
        }

        return $builder->build();
    }

    public function loader(Profile $profile, AuthContext $auth): OpenApiToolLoader
    {
        return new OpenApiToolLoader(
            $profile,
            $this->cache,
            new UpstreamClient($profile->upstreamBaseUrl, $this->upstreamHttp),
            $auth,
        );
    }
}
