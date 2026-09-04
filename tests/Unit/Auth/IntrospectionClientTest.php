<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\Auth;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InterServer\Mcp\Core\Auth\IntrospectionClient;
use InterServer\Mcp\Core\Tests\Fixtures\ArrayCache;
use InterServer\Mcp\Core\Tests\Fixtures\CollectingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @covers \InterServer\Mcp\Core\Auth\IntrospectionClient
 */
final class IntrospectionClientTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->sent = [];
    }

    private function client(Response|\Throwable ...$responses): GuzzleClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(fn (callable $next) => function (RequestInterface $req, array $opts) use ($next) {
            $this->sent[] = $req;

            return $next($req, $opts);
        });

        return new GuzzleClient(['handler' => $stack]);
    }

    private function subject(
        GuzzleClient $http,
        ?ArrayCache $cache = null,
        ?CollectingLogger $logger = null,
    ): IntrospectionClient {
        return new IntrospectionClient(
            endpoint: 'https://my.test/apiv2/oauth/introspect',
            clientId: 'mcp-client-app',
            clientSecret: 's3cret',
            cache: $cache,
            http: $http,
            logger: $logger,
        );
    }

    private static function activeBody(array $extra = []): string
    {
        // $extra first: PHP's `+` keeps the left operand on key collision, so
        // defaults must be the right-hand side for an override to take effect.
        return (string) json_encode($extra + ['active' => true, 'scope' => 'vps billing', 'sub' => '12345']);
    }

    // ------------------------------------------------------------------ happy

    public function testAnActiveTokenIsReturned(): void
    {
        $result = $this->subject($this->client(new Response(200, [], self::activeBody())))->introspect('tok');

        self::assertTrue($result['active']);
        self::assertSame('vps billing', $result['scope']);
        self::assertSame('12345', $result['sub']);
    }

    public function testTheRequestIsRfc7662Shaped(): void
    {
        $this->subject($this->client(new Response(200, [], self::activeBody())))->introspect('tok');

        $request = $this->sent[0];

        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://my.test/apiv2/oauth/introspect', (string) $request->getUri());
        self::assertSame('application/x-www-form-urlencoded', $request->getHeaderLine('Content-Type'));
        self::assertSame('token=tok&token_type_hint=access_token', (string) $request->getBody());
    }

    /**
     * Each app authenticates to the endpoint with its own confidential client.
     * That is what makes the `ext.ima` gate enforceable and gives per-app revocation.
     */
    public function testTheClientAuthenticatesWithItsOwnCredential(): void
    {
        $this->subject($this->client(new Response(200, [], self::activeBody())))->introspect('tok');

        self::assertSame(
            'Basic '.base64_encode('mcp-client-app:s3cret'),
            $this->sent[0]->getHeaderLine('Authorization'),
        );
    }

    // ----------------------------------------------------------- fail closed

    /**
     * Every failure mode must be indistinguishable. Telling an attacker which
     * guesses were once real is the whole risk of running an introspection oracle.
     *
     * @dataProvider failureProvider
     */
    public function testEveryFailureLooksIdentical(Response|\Throwable $response): void
    {
        $result = $this->subject($this->client($response))->introspect('tok');

        self::assertSame(['active' => false], $result);
    }

    public static function failureProvider(): \Generator
    {
        yield 'inactive token' => [new Response(200, [], '{"active":false}')];
        yield 'unauthorized introspection client' => [new Response(401, [], '{"error":"invalid_client"}')];
        yield 'endpoint 500' => [new Response(500, [], 'boom')];
        yield 'unparseable body' => [new Response(200, [], 'not json')];
        yield 'json that is not an object' => [new Response(200, [], '"nope"')];
        yield 'body missing active' => [new Response(200, [], '{"scope":"vps"}')];
        yield 'transport failure' => [new ConnectException('refused', new Request('POST', 'https://my.test'))];
    }

    public function testAnEmptyTokenIsRefusedWithoutACall(): void
    {
        $result = $this->subject($this->client())->introspect('   ');

        self::assertSame(['active' => false], $result);
        self::assertSame([], $this->sent);
    }

    // ---------------------------------------------------------------- caching

    public function testAnActiveResultIsCached(): void
    {
        $cache = new ArrayCache();
        $client = $this->subject($this->client(new Response(200, [], self::activeBody())), $cache);

        $client->introspect('tok');
        $client->introspect('tok');

        self::assertCount(1, $this->sent, 'the second call must be served from cache');
    }

    public function testTheCacheIsKeyedOnTheTokenHashNotTheToken(): void
    {
        $cache = new ArrayCache();
        $this->subject($this->client(new Response(200, [], self::activeBody())), $cache)->introspect('super-secret');

        $keys = $cache->keys();

        self::assertCount(1, $keys);
        self::assertSame('mcp:introspect:'.hash('sha256', 'super-secret'), $keys[0]);
        self::assertStringNotContainsString('super-secret', $keys[0]);
    }

    public function testDifferentTokensDoNotShareACacheEntry(): void
    {
        $cache = new ArrayCache();
        $client = $this->subject(
            $this->client(new Response(200, [], self::activeBody()), new Response(200, [], self::activeBody(['sub' => '999']))),
            $cache,
        );

        self::assertSame('12345', $client->introspect('a')['sub']);
        self::assertSame('999', $client->introspect('b')['sub']);
    }

    /**
     * Without this, a token-guessing loop reaches the authorization server's
     * database once per guess.
     */
    public function testNegativeResultsAreCachedBriefly(): void
    {
        $cache = new ArrayCache();
        $client = $this->subject($this->client(new Response(200, [], '{"active":false}')), $cache);

        $client->introspect('bad');
        $client->introspect('bad');

        self::assertCount(1, $this->sent);
        self::assertSame(IntrospectionClient::NEGATIVE_TTL, $cache->ttlFor('mcp:introspect:'.hash('sha256', 'bad')));
    }

    public function testTheCacheTtlNeverOutlivesTheToken(): void
    {
        $cache = new ArrayCache();
        $expiresIn = 20;

        $this->subject($this->client(new Response(200, [], self::activeBody(['exp' => time() + $expiresIn]))), $cache)
            ->introspect('tok');

        $ttl = $cache->ttlFor('mcp:introspect:'.hash('sha256', 'tok'));

        self::assertLessThanOrEqual($expiresIn, $ttl);
        self::assertGreaterThan(0, $ttl);
    }

    public function testADistantExpiryIsCappedAtTheDefaultTtl(): void
    {
        $cache = new ArrayCache();

        $this->subject($this->client(new Response(200, [], self::activeBody(['exp' => time() + 86400]))), $cache)
            ->introspect('tok');

        self::assertSame(
            IntrospectionClient::DEFAULT_TTL,
            $cache->ttlFor('mcp:introspect:'.hash('sha256', 'tok')),
        );
    }

    public function testAnAlreadyExpiredTokenIsNotCached(): void
    {
        $cache = new ArrayCache();

        $this->subject($this->client(new Response(200, [], self::activeBody(['exp' => time() - 5]))), $cache)
            ->introspect('tok');

        self::assertSame(0, $cache->ttlFor('mcp:introspect:'.hash('sha256', 'tok')));
    }

    public function testItWorksWithNoCacheConfigured(): void
    {
        $client = $this->subject($this->client(
            new Response(200, [], self::activeBody()),
            new Response(200, [], self::activeBody()),
        ));

        self::assertTrue($client->introspect('tok')['active']);
        self::assertTrue($client->introspect('tok')['active']);
        self::assertCount(2, $this->sent);
    }

    // ---------------------------------------------------------------- logging

    /**
     * A logged bearer token is a bearer token in the log aggregator, the backup,
     * and anywhere the logs are shipped. Eight hex characters correlate two lines
     * and replay nothing.
     */
    public function testTheTokenIsNeverLogged(): void
    {
        $logger = new CollectingLogger();

        $this->subject($this->client(new Response(500, [], 'boom')), null, $logger)->introspect('super-secret-token');

        $rendered = $logger->render();

        self::assertNotSame('', $rendered, 'an introspection failure should be logged');
        self::assertStringNotContainsString('super-secret-token', $rendered);
        self::assertStringContainsString(substr(hash('sha256', 'super-secret-token'), 0, 8), $rendered);
    }

    public function testTransportFailuresAreLogged(): void
    {
        $logger = new CollectingLogger();

        $this->subject(
            $this->client(new ConnectException('refused', new Request('POST', 'https://my.test'))),
            null,
            $logger,
        )->introspect('tok');

        self::assertStringContainsString('Token introspection failed', $logger->render());
    }

    public function testASuccessfulIntrospectionLogsNothing(): void
    {
        $logger = new CollectingLogger();

        $this->subject($this->client(new Response(200, [], self::activeBody())), null, $logger)->introspect('tok');

        self::assertSame('', $logger->render());
    }
}
