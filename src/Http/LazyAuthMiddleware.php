<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Http;

use InterServer\Mcp\Core\Auth\AuthContext;
use InterServer\Mcp\Core\Auth\IntrospectionTokenValidator;
use InterServer\Mcp\Core\Profile\Profile;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Refuses unauthenticated calls at the transport, before the MCP layer sees them.
 *
 * The refusal has to be an HTTP status with a `WWW-Authenticate` header. A `200`
 * carrying `{"result":{"isError":true}}` is handed to the model as text — Claude
 * shows no Connect card and never prompts for auth, and the connector appears to
 * do nothing at all. That is the single most common silent failure in this shape
 * of server, and it is a middleware-ordering bug, not a protocol one.
 *
 * "Lazy" is the other half: a few methods fall through unauthenticated so the
 * connector is inspectable before login. Which ones is per profile —
 * `listRequiresAuth` gates `tools/list` on the admin surface, where an
 * unauthenticated listing would hand ~445 admin tool names to any caller who can
 * reach the host. The IP allowlist does not cover that: two thirds of the
 * addresses it admits are Claude's own egress, so anything arriving through
 * Claude is inside the allowlist by construction.
 */
final class LazyAuthMiddleware implements MiddlewareInterface
{
    /**
     * Reachable without a credential so a client can discover the server, unless
     * the profile says otherwise.
     */
    private const ALWAYS_OPEN_METHODS = ['initialize', 'notifications/initialized', 'server/discover', 'ping'];

    private const OPEN_UNLESS_LIST_GATED = ['tools/list', 'prompts/list', 'resources/list', 'resources/templates/list'];

    public function __construct(
        private readonly Profile $profile,
        private readonly ProtectedResourceMetadata $metadata,
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly ?IntrospectionTokenValidator $validator = null,
        /**
         * Scopes named in an insufficient-scope challenge. The union for the whole
         * surface, not just the one this call missed.
         *
         * @var list<string>
         */
        private readonly array $challengeScopes = [],
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->profile->requiresAuth) {
            return $handler->handle($this->withAuth($request, AuthContext::anonymous()));
        }

        $token = CredentialExtractor::bearerToken($request);

        if (null === $token) {
            return $this->open($request)
                ? $handler->handle($this->withAuth($request, AuthContext::anonymous()))
                : $this->challenge(401, 'invalid_token', 'Authentication required.');
        }

        if (null === $this->validator) {
            return $this->challenge(401, 'invalid_token', 'Token validation is not configured.');
        }

        $result = $this->validator->validate($token);

        if (!$result->isAllowed()) {
            return $this->challenge(
                $result->getStatusCode(),
                $result->getError() ?? 'invalid_token',
                $result->getErrorDescription() ?? '',
                $result->getScopes() ?? $this->challengeScopes,
            );
        }

        return $handler->handle($this->withAuth($request, $this->validator->contextFor($token)));
    }

    /**
     * May this request proceed without a credential?
     */
    private function open(ServerRequestInterface $request): bool
    {
        $method = $this->methodOf($request);

        if (null === $method) {
            return false;
        }

        if (\in_array($method, self::ALWAYS_OPEN_METHODS, true)) {
            return true;
        }

        return !$this->profile->listRequiresAuth && \in_array($method, self::OPEN_UNLESS_LIST_GATED, true);
    }

    /**
     * The JSON-RPC method this request carries.
     *
     * Read from the body, because that is where it authoritatively is. The modern
     * era mirrors it into an `Mcp-Method` header, but a header is a claim and the
     * body is the request.
     */
    private function methodOf(ServerRequestInterface $request): ?string
    {
        $body = (string) $request->getBody();
        $request->getBody()->rewind();

        $decoded = json_decode($body, true);

        return \is_array($decoded) && isset($decoded['method']) && \is_string($decoded['method'])
            ? $decoded['method']
            : null;
    }

    private function withAuth(ServerRequestInterface $request, AuthContext $auth): ServerRequestInterface
    {
        return $request->withAttribute(AuthContext::class, $auth);
    }

    /**
     * @param list<string> $scopes
     */
    private function challenge(int $status, string $error, string $description, array $scopes = []): ResponseInterface
    {
        $body = (string) json_encode([
            'error' => $error,
            'error_description' => $description,
        ], \JSON_UNESCAPED_SLASHES);

        $response = $this->responseFactory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store, max-age=0')
            ->withHeader(
                'WWW-Authenticate',
                $this->metadata->challenge($this->profile->authRealm, $error, $scopes),
            );

        $response->getBody()->write($body);

        return $response;
    }
}
