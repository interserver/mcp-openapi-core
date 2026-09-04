<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Http;

use InterServer\Mcp\Core\Auth\AuthContext;
use InterServer\Mcp\Core\Auth\IntrospectionTokenValidator;
use InterServer\Mcp\Core\Auth\ScopeToolFilter;
use InterServer\Mcp\Core\OpenApi\ToolCache;
use InterServer\Mcp\Core\Profile\Profile;
use InterServer\Mcp\Core\Profile\ProfileResolver;
use InterServer\Mcp\Core\Server\ServerFactory;
use Mcp\Server\Session\SessionStoreInterface;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\Http\Middleware\OAuthRequestMetaMiddleware;
use Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
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
 *  5. **`ProtocolVersionMiddleware`** — the handshake era's version check.
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
                new ProtocolVersionMiddleware(),
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
