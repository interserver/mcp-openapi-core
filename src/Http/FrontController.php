<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Http;

use InterServer\Mcp\Core\Auth\AuthContext;
use InterServer\Mcp\Core\Auth\IntrospectionTokenValidator;
use InterServer\Mcp\Core\Auth\ScopeToolFilter;
use InterServer\Mcp\Core\OpenApi\ToolCache;
use InterServer\Mcp\Core\Profile\Profile;
use InterServer\Mcp\Core\Profile\ProfileResolver;
use InterServer\Mcp\Core\Server\CapabilityProbe;
use InterServer\Mcp\Core\Server\ServerFactory;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The whole request path for an MCP application, in one place.
 *
 * Both applications are this class plus a `config/` directory. Anything that
 * differs between them is a {@see Profile} field, so the two `public_html/index.php`
 * files are near-identical and a reader moving between them is never surprised —
 * which is the entire point of extracting this package rather than copying it.
 *
 * Middleware order is not decorative:
 *
 *  1. **CORS** first, so a preflight is answered without touching anything else.
 *     The registry is never read on a preflight, so no spec is parsed either.
 *  2. **DNS rebinding protection** — validates the `Host`/`Origin` before the body
 *     is trusted. Its allowed-host list is separate from the CORS origins and has
 *     no safe default: the SDK ships `localhost`, so an empty list on a real host
 *     refuses everything with a 403 that reads like a firewall fault.
 *  3. **{@see LazyAuthMiddleware}** — refuses at the transport with a real HTTP
 *     status. Everything after this point can assume a resolved caller.
 *  4. **`OAuthRequestMetaMiddleware`** — without it the auth attributes never
 *     reach a handler, which surfaces as a server that authenticates correctly and
 *     then behaves as though nobody is logged in.
 * `ProtocolVersionMiddleware` is deliberately *not* in that list. It belongs to
 * the handshake era, and this list runs before the era is classified, so putting
 * it here answers -32022 to every modern request. The transport applies it to the
 * handshake leg on its own.
 */
final class FrontController
{
    public function __construct(
        private readonly ProfileResolver $resolver,
        private readonly ServerFactory $factory,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
        private readonly string $authorizationServer,
        /**
         * Builds the token validator for a profile. A closure because the validator
         * is profile-bound (its audience check is), and the profile is only known
         * once the request has been routed.
         *
         * @var (\Closure(Profile): ?IntrospectionTokenValidator)|null
         */
        private readonly ?\Closure $validatorFactory = null,
        private readonly ?SessionStoreInterface $sessionStore = null,
        private readonly ?LoggerInterface $logger = null,
        /**
         * Origins allowed to call this server from a browser. `['*']` when empty.
         *
         * @var list<string>
         */
        private readonly array $allowedOrigins = [],
        /**
         * Host names this server answers to, for DNS-rebinding protection.
         *
         * Distinct from the CORS origins, and not optional: the SDK's default is
         * `localhost` only, so leaving it empty on a real deployment refuses every
         * request with a 403 that looks like a firewall problem.
         *
         * @var list<string>
         */
        private readonly array $allowedHosts = ['localhost', '127.0.0.1', '[::1]'],
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $request = self::collapseDuplicateHostHeader($request);

        $profile = $this->resolver->resolve($request);

        $metadata = new ProtectedResourceMetadata(
            $this->resourceUrl($request, $profile),
            $this->authorizationServer,
            $this->advertisedScopes($profile),
        );

        // A metadata request is answered here rather than by a separate .php file.
        // The origin deployment needed three stub files purely because the vhost
        // proxied only URLs ending in `.php`; routing everything to one entry point
        // removes the need for them entirely.
        if ($this->isMetadataRequest($request)) {
            return $this->json($metadata->toArray());
        }

        $validator = null !== $this->validatorFactory
            ? ($this->validatorFactory)($profile)
            : null;

        // The server card, at the path the extension reserves. Answered before the
        // transport because it is a plain GET of a static-shaped document, not a
        // JSON-RPC call — and unauthenticated, because a card a client must already
        // hold a token to read cannot serve its purpose of telling that client how
        // to get one.
        if ($this->isServerCardRequest($request, $profile)) {
            return $this->serverCard($request, $profile);
        }

        $transport = new StreamableHttpTransport(
            $request,
            $this->responseFactory,
            $this->streamFactory,
            $this->logger,
            [
                new CorsMiddleware($this->allowedOrigins ?: ['*']),
                new DnsRebindingProtectionMiddleware(
                    $this->allowedHosts,
                    $this->responseFactory,
                    $this->streamFactory,
                ),
                new LazyAuthMiddleware(
                    $profile,
                    $metadata,
                    $this->responseFactory,
                    $validator,
                    $this->advertisedScopes($profile),
                ),
                new OAuthRequestMetaMiddleware(),
                // No ProtocolVersionMiddleware here. This list runs at the edge,
                // before the transport classifies the request's era, and that
                // middleware only recognises handshake revisions — so including it
                // rejects every 2026-07-28 request with -32022 before the modern
                // dispatcher is ever consulted. The transport applies it itself, to
                // handshake-era traffic only, via handshakeMiddleware().
            ],
        );

        $auth = $request->getAttribute(AuthContext::class);
        if (!$auth instanceof AuthContext) {
            $auth = $this->resolveAuth($request, $validator);
        }

        return $this->factory->build($profile, $auth, $this->sessionStore)->run($transport);
    }

    private function resolveAuth(ServerRequestInterface $request, ?IntrospectionTokenValidator $validator): AuthContext
    {
        $token = CredentialExtractor::bearerToken($request);

        if (null !== $token && null !== $validator) {
            return $validator->contextFor($token);
        }

        // The API key and session paths carry no scopes of their own: the REST API
        // applies its own authorization to the forwarded credential, so the tool
        // catalogue is left unfiltered and the API refuses what it should.
        $apiKey = CredentialExtractor::apiKey($request);
        $sessionId = CredentialExtractor::sessionId($request);

        if (null !== $apiKey || null !== $sessionId) {
            return new AuthContext(authenticated: true, apiKey: $apiKey, sessionId: $sessionId);
        }

        return AuthContext::anonymous();
    }

    /**
     * The RFC 8707 resource identifier for this request.
     *
     * Built from the URL the caller actually used, because Claude compares it
     * literally against the PRM's `resource`, including the path.
     */
    private function resourceUrl(ServerRequestInterface $request, Profile $profile): string
    {
        if ([] !== $profile->resourceIdentifiers) {
            return $profile->resourceIdentifiers[0];
        }

        $uri = $request->getUri();

        return $uri->getScheme().'://'.$uri->getAuthority().($this->resolver->pathFor($profile->name) ?? '/');
    }

    /**
     * @return list<string>
     */
    private function advertisedScopes(Profile $profile): array
    {
        if (null === $profile->scopeMap) {
            return null !== $profile->requiredScope ? [$profile->requiredScope] : [];
        }

        $scopes = [];
        foreach ($profile->scopeMap->sections() as $module) {
            $scopes[$module] = true;
            $scopes[$module.':read'] = true;
        }
        if (null !== $profile->requiredScope) {
            $scopes[$profile->requiredScope] = true;
        }

        $names = array_keys($scopes);
        sort($names);

        return $names;
    }

    private function isMetadataRequest(ServerRequestInterface $request): bool
    {
        return str_starts_with($request->getUri()->getPath(), '/.well-known/oauth-protected-resource');
    }

    /**
     * Is this a GET of `<streamable-http-url>/server-card` for a profile that serves one?
     */
    private function isServerCardRequest(ServerRequestInterface $request, Profile $profile): bool
    {
        if (!$profile->servesServerCard || 'GET' !== strtoupper($request->getMethod())) {
            return false;
        }

        return str_ends_with(rtrim($request->getUri()->getPath(), '/'), '/server-card');
    }

    /**
     * Render the card from a real `server/discover` against the real server.
     */
    private function serverCard(ServerRequestInterface $request, Profile $profile): ResponseInterface
    {
        $server = $this->factory->build($profile, AuthContext::anonymous(), $this->sessionStore);
        $discovery = CapabilityProbe::discover($server);

        if ([] === $discovery) {
            // Better a 503 than a card asserting the server has no capabilities.
            // An empty probe means we could not establish what this server does,
            // and publishing that as fact is how the card and the live result
            // disagreed in the first place.
            $this->logger?->error('Could not probe server capabilities for the server card', ['profile' => $profile->name]);

            return $this->responseFactory->createResponse(503)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream('{"error":"server card unavailable"}'));
        }

        $uri = $request->getUri();
        $endpoint = $uri->getScheme().'://'.$uri->getAuthority()
            .substr(rtrim($uri->getPath(), '/'), 0, -\strlen('/server-card'));

        $card = ServerCard::build(
            $profile,
            $endpoint,
            $discovery,
            $profile->documentationUrl,
            $uri->getScheme().'://'.$uri->getAuthority()
                .'/.well-known/oauth-protected-resource'.($this->resolver->pathFor($profile->name) ?? ''),
        );

        $etag = ServerCard::etag($card);

        // Conditional GET. The extension asks for public caching with a one-hour
        // max-age, so clients do re-fetch, and a 304 is cheaper than re-probing.
        if (trim($request->getHeaderLine('If-None-Match')) === $etag) {
            return $this->responseFactory->createResponse(304)
                ->withHeader('ETag', $etag)
                ->withHeader('Cache-Control', 'public, max-age=3600');
        }

        return $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', ServerCard::MEDIA_TYPE)
            // The card is public metadata by design — it exists to be read by
            // clients that have no credential yet.
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('ETag', $etag)
            ->withBody($this->streamFactory->createStream(
                (string) json_encode($card, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)
            ));
    }

    /**
     * Collapse a `Host` header that arrives repeated with the same value.
     *
     * `Nyholm\Psr7Server\ServerRequestCreator::fromGlobals()` emits `Host` twice
     * — once from `$_SERVER['HTTP_HOST']` and once from the URI it builds out of
     * that same value. `getHeaderLine()` then joins them, so the DNS-rebinding
     * middleware compares "example.com, example.com" against its allowlist and
     * rejects every request. It fails CLOSED, which is why it presents as a
     * blanket 403 on a correctly configured host rather than as anything that
     * points at the header.
     *
     * Only identical values are collapsed. Genuinely conflicting `Host` headers
     * are request smuggling and are left exactly as they are, so the middleware
     * still refuses them.
     */
    private static function collapseDuplicateHostHeader(ServerRequestInterface $request): ServerRequestInterface
    {
        $values = $request->getHeader('Host');
        if (\count($values) < 2) {
            return $request;
        }

        $unique = array_values(array_unique($values));
        if (1 !== \count($unique)) {
            return $request;
        }

        return $request->withHeader('Host', $unique[0]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Access-Control-Allow-Origin', '*')
            // Caching a stale scope list breaks MCP client authorize requests, and
            // discovery documents are cached globally by URL for ~5 minutes if you
            // let them be.
            ->withHeader('Cache-Control', 'no-store, max-age=0');

        $response->getBody()->write((string) json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT));

        return $response;
    }
}
