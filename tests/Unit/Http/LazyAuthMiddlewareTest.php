<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\Http;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use InterServer\Mcp\Core\Auth\AuthContext;
use InterServer\Mcp\Core\Auth\IntrospectionClient;
use InterServer\Mcp\Core\Auth\IntrospectionTokenValidator;
use InterServer\Mcp\Core\Http\LazyAuthMiddleware;
use InterServer\Mcp\Core\Http\ProtectedResourceMetadata;
use InterServer\Mcp\Core\Profile\Profile;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @covers \InterServer\Mcp\Core\Http\LazyAuthMiddleware
 * @covers \InterServer\Mcp\Core\Http\CredentialExtractor
 */
final class LazyAuthMiddlewareTest extends TestCase
{
    private const RESOURCE = 'https://mcp.interserver.net/client';
    private const ADMIN_RESOURCE = 'https://adminmcp.interserver.net/';

    private Psr17Factory $factory;

    /** Set by the inner handler when it is reached. */
    private ?AuthContext $reached = null;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory();
        $this->reached = null;
    }

    private function handler(): RequestHandlerInterface
    {
        return new class($this) implements RequestHandlerInterface {
            public function __construct(private readonly LazyAuthMiddlewareTest $test)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->recordReached($request->getAttribute(AuthContext::class));

                return new \Nyholm\Psr7\Response(200, [], '{"ok":true}');
            }
        };
    }

    public function recordReached(?AuthContext $auth): void
    {
        $this->reached = $auth ?? AuthContext::anonymous();
    }

    private function request(string $method, ?string $token = null): ServerRequestInterface
    {
        $request = new ServerRequest('POST', self::RESOURCE, [], (string) json_encode([
            'jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => [],
        ]));

        return null !== $token ? $request->withHeader('Authorization', 'Bearer '.$token) : $request;
    }

    /**
     * @param array<string, mixed> $introspection
     */
    private function middleware(Profile $profile, ?array $introspection = null): LazyAuthMiddleware
    {
        $validator = null;

        if (null !== $introspection) {
            $validator = new IntrospectionTokenValidator(
                new IntrospectionClient(
                    endpoint: 'https://my.test/introspect',
                    clientId: 'app',
                    clientSecret: 'secret',
                    http: new GuzzleClient(['handler' => HandlerStack::create(new MockHandler(
                        array_fill(0, 8, new GuzzleResponse(200, [], (string) json_encode($introspection)))
                    ))]),
                ),
                $profile,
            );
        }

        return new LazyAuthMiddleware(
            $profile,
            new ProtectedResourceMetadata(self::RESOURCE, 'https://my.interserver.net', ['vps', 'billing']),
            $this->factory,
            $validator,
            ['billing', 'vps'],
        );
    }

    private static function clientProfile(array $overrides = []): Profile
    {
        return Profile::fromArray('client', array_merge([
            'specSource' => '/dev/null',
            'upstreamBaseUrl' => 'https://my.test/apiv2',
            'serverName' => 'client',
            'authRealm' => 'interserver-client',
            'resourceIdentifiers' => [self::RESOURCE],
        ], $overrides));
    }

    private static function adminProfile(): Profile
    {
        return Profile::fromArray('admin', [
            'specSource' => '/dev/null',
            'upstreamBaseUrl' => 'https://my.test/apiv2',
            'serverName' => 'admin',
            'authRealm' => 'interserver-admin',
            'requiredScope' => 'admin',
            'requiresAdminTier' => true,
            'listRequiresAuth' => true,
            'resourceIdentifiers' => [self::ADMIN_RESOURCE],
        ]);
    }

    // ------------------------------------------------------- the 401 that works

    /**
     * The single most common way a connector silently does nothing: a `200`
     * carrying `{"result":{"isError":true}}` is handed to the model as text, so
     * Claude shows no Connect card and never prompts for auth. The refusal must be
     * an HTTP status emitted before the MCP layer sees the body.
     */
    public function testAnUnauthenticatedCallIsRefusedWithARealHttpStatus(): void
    {
        $response = $this->middleware(self::clientProfile())
            ->process($this->request('tools/call'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertNull($this->reached, 'the MCP layer must not see an unauthenticated tools/call');
    }

    public function testTheRefusalCarriesAResourceMetadataPointer(): void
    {
        $response = $this->middleware(self::clientProfile())
            ->process($this->request('tools/call'), $this->handler());

        $challenge = $response->getHeaderLine('WWW-Authenticate');

        self::assertStringStartsWith('Bearer ', $challenge);
        self::assertStringContainsString('realm="interserver-client"', $challenge);
        self::assertStringContainsString(
            'resource_metadata="https://mcp.interserver.net/.well-known/oauth-protected-resource/client"',
            $challenge,
        );
    }

    public function testTheRefusalIsNotCached(): void
    {
        $response = $this->middleware(self::clientProfile())
            ->process($this->request('tools/call'), $this->handler());

        self::assertSame('no-store, max-age=0', $response->getHeaderLine('Cache-Control'));
    }

    // ---------------------------------------------------------------- lazy

    /**
     * @dataProvider openMethodProvider
     */
    public function testDiscoveryMethodsFallThroughUnauthenticated(string $method): void
    {
        $response = $this->middleware(self::clientProfile())->process($this->request($method), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->reached);
        self::assertFalse($this->reached->authenticated);
    }

    public static function openMethodProvider(): \Generator
    {
        yield 'initialize' => ['initialize'];
        yield 'server/discover' => ['server/discover'];
        yield 'ping' => ['ping'];
        yield 'tools/list' => ['tools/list'];
    }

    /**
     * Exposing ~445 admin tool names to an unauthenticated caller is the leak the
     * current server's `ima` check exists to prevent. The IP allowlist does not
     * cover it: two thirds of the addresses it admits are Claude's own egress, so
     * anything arriving through Claude is inside the allowlist by construction.
     */
    public function testTheAdminSurfaceGatesToolsList(): void
    {
        $response = $this->middleware(self::adminProfile())
            ->process($this->request('tools/list'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertNull($this->reached);
    }

    public function testTheAdminSurfaceStillAllowsInitialize(): void
    {
        $response = $this->middleware(self::adminProfile())
            ->process($this->request('initialize'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAProfileNeedingNoAuthLetsEverythingThrough(): void
    {
        $public = self::clientProfile(['requiresAuth' => false]);

        $response = $this->middleware($public)->process($this->request('tools/call'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->reached);
    }

    public function testAnUnparseableBodyIsNotTreatedAsAnOpenMethod(): void
    {
        $request = new ServerRequest('POST', self::RESOURCE, [], 'not json');

        $response = $this->middleware(self::clientProfile())->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }

    // ----------------------------------------------------------- with a token

    public function testAValidTokenReachesTheHandlerWithItsContext(): void
    {
        $response = $this->middleware(self::clientProfile(), [
            'active' => true, 'scope' => 'vps billing', 'sub' => '42', 'aud' => self::RESOURCE,
        ])->process($this->request('tools/call', 'good-token'), $this->handler());

        self::assertSame(200, $response->getStatusCode());
        self::assertNotNull($this->reached);
        self::assertTrue($this->reached->authenticated);
        self::assertSame(['vps', 'billing'], $this->reached->scopes);
        self::assertSame('good-token', $this->reached->bearerToken);
    }

    public function testAnInvalidTokenIsRefused(): void
    {
        $response = $this->middleware(self::clientProfile(), ['active' => false])
            ->process($this->request('tools/call', 'bad-token'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('error="invalid_token"', $response->getHeaderLine('WWW-Authenticate'));
    }

    /**
     * A token minted for the admin server, replayed at the client server. Rejected
     * on audience, and refused as an *invalid token* rather than as a wrong-server
     * error — telling the caller which server it belongs to is itself a disclosure.
     */
    public function testAnAdminAudienceTokenIsRefusedByTheClientServer(): void
    {
        $response = $this->middleware(self::clientProfile(), [
            'active' => true, 'scope' => 'admin', 'aud' => self::ADMIN_RESOURCE, 'ext' => ['ima' => 'admin'],
        ])->process($this->request('tools/call', 'admin-token'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertNull($this->reached);
    }

    /**
     * Step-up, not refusal: a valid token missing a scope gets a 403 naming what
     * it needs, so the client can re-authorize instead of giving up.
     */
    public function testAnInsufficientScopeIsA403WithTheScopeUnion(): void
    {
        $response = $this->middleware(self::adminProfile(), [
            'active' => true, 'scope' => 'vps', 'aud' => self::ADMIN_RESOURCE, 'ext' => ['ima' => 'admin'],
        ])->process($this->request('tools/call', 'weak-token'), $this->handler());

        $challenge = $response->getHeaderLine('WWW-Authenticate');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('error="insufficient_scope"', $challenge);
        self::assertStringContainsString('scope="admin"', $challenge);
    }

    public function testAClientTierAccountIsRefusedByTheAdminServer(): void
    {
        $response = $this->middleware(self::adminProfile(), [
            'active' => true, 'scope' => 'admin', 'aud' => self::ADMIN_RESOURCE, 'ext' => ['ima' => 'client'],
        ])->process($this->request('tools/call', 'client-tier-token'), $this->handler());

        self::assertSame(403, $response->getStatusCode());
        self::assertNull($this->reached);
    }

    public function testAMisconfiguredServerRefusesRatherThanAdmits(): void
    {
        // A token is presented but no validator is wired. Failing open here would
        // accept every token on the internet.
        $response = $this->middleware(self::clientProfile(), null)
            ->process($this->request('tools/call', 'any-token'), $this->handler());

        self::assertSame(401, $response->getStatusCode());
        self::assertNull($this->reached);
    }

    // ------------------------------------------------- credential extraction

    public function testTheBearerSchemeIsMatchedCaseInsensitively(): void
    {
        $request = $this->request('tools/call')->withHeader('Authorization', 'bearer good-token');

        $response = $this->middleware(self::clientProfile(), [
            'active' => true, 'scope' => 'vps', 'aud' => self::RESOURCE,
        ])->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Under PHP-FPM the Authorization header does not reach PHP without
     * `CGIPassAuth On`; some stacks then surface it only as the CGI-mangled
     * REDIRECT_ variant. This is a fallback, not a substitute for the vhost.
     */
    public function testTheCgiMangledAuthorizationVariableIsAccepted(): void
    {
        $request = new ServerRequest(
            'POST',
            self::RESOURCE,
            [],
            (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => []]),
            '1.1',
            ['REDIRECT_HTTP_AUTHORIZATION' => 'Bearer good-token'],
        );

        $response = $this->middleware(self::clientProfile(), [
            'active' => true, 'scope' => 'vps', 'aud' => self::RESOURCE,
        ])->process($request, $this->handler());

        self::assertSame(200, $response->getStatusCode());
    }

    public function testAMalformedAuthorizationHeaderIsTreatedAsAbsent(): void
    {
        $request = $this->request('tools/call')->withHeader('Authorization', 'Basic dXNlcjpwYXNz');

        $response = $this->middleware(self::clientProfile())->process($request, $this->handler());

        self::assertSame(401, $response->getStatusCode());
    }
}
