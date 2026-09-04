<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\Server;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InterServer\Mcp\Core\Auth\AuthContext;
use InterServer\Mcp\Core\OpenApi\ToolDefinition;
use InterServer\Mcp\Core\Server\UpstreamClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * @covers \InterServer\Mcp\Core\Server\UpstreamClient
 */
final class UpstreamClientTest extends TestCase
{
    /** @var list<RequestInterface> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->sent = [];
    }

    private function subject(Response|\Throwable ...$responses): UpstreamClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(fn (callable $next) => function (RequestInterface $req, array $opts) use ($next) {
            $this->sent[] = $req;

            return $next($req, $opts);
        });

        return new UpstreamClient('https://my.test/apiv2', new GuzzleClient(['handler' => $stack]));
    }

    private static function tool(
        string $method = 'GET',
        string $path = '/vps/{id}',
        array $pathParams = ['id'],
        array $queryParams = [],
        bool $hasBody = false,
    ): ToolDefinition {
        return new ToolDefinition(
            name: 'tool', description: '', httpMethod: $method, path: $path,
            inputSchema: ['type' => 'object'],
            pathParams: $pathParams, queryParams: $queryParams, hasBody: $hasBody,
        );
    }

    private function lastUri(): string
    {
        return (string) $this->sent[array_key_last($this->sent)]->getUri();
    }

    // -------------------------------------------------------------------- URL

    /**
     * Built by concatenation, never Guzzle's `base_uri`. Guzzle merges per RFC
     * 3986, under which a path starting with "/" replaces the base path entirely —
     * so `/admin/tickets` against a base of `https://host/apiv2` would land on the
     * admin *web UI*, which redirects to a login page. The symptom was a 56 KB
     * HTML blob with nothing to say the API was never reached.
     */
    public function testTheApiPathPrefixIsPreserved(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(
            self::tool('GET', '/admin/tickets', []), [], AuthContext::anonymous()
        );

        self::assertSame('https://my.test/apiv2/admin/tickets', $this->lastUri());
    }

    public function testPathParametersAreSubstituted(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(self::tool(), ['id' => 4242], AuthContext::anonymous());

        self::assertSame('https://my.test/apiv2/vps/4242', $this->lastUri());
    }

    public function testPathParametersAreUrlEncoded(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(
            self::tool('GET', '/domains/{name}', ['name']),
            ['name' => 'a b/c?d'],
            AuthContext::anonymous(),
        );

        self::assertStringContainsString('/domains/a%20b%2Fc%3Fd', $this->lastUri());
    }

    /**
     * Substituting a guess would send the call to a different resource than the
     * model asked for. Leaving the placeholder makes the API reject it instead.
     */
    public function testAnAbsentPathParameterLeavesThePlaceholderInPlace(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(self::tool(), [], AuthContext::anonymous());

        self::assertStringContainsString('%7Bid%7D', $this->lastUri());
    }

    public function testQueryParametersAreSent(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(
            self::tool('GET', '/vps', [], ['page', 'limit']),
            ['page' => 2, 'limit' => 50],
            AuthContext::anonymous(),
        );

        self::assertStringContainsString('page=2', $this->lastUri());
        self::assertStringContainsString('limit=50', $this->lastUri());
    }

    public function testArgumentsThatAreNotDeclaredParametersAreNotSentAsQuery(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(
            self::tool('GET', '/vps', [], ['page']),
            ['page' => 1, 'undeclared' => 'x'],
            AuthContext::anonymous(),
        );

        self::assertStringNotContainsString('undeclared', $this->lastUri());
    }

    // ------------------------------------------------------------------- body

    public function testRemainingArgumentsBecomeTheJsonBody(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(
            self::tool('POST', '/vps/{id}/rename', ['id'], [], true),
            ['id' => 1, 'hostname' => 'new.example.com'],
            AuthContext::anonymous(),
        );

        $request = $this->sent[0];

        self::assertSame('{"hostname":"new.example.com"}', (string) $request->getBody());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
    }

    public function testPathAndQueryParametersAreExcludedFromTheBody(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(
            self::tool('POST', '/vps/{id}', ['id'], ['dry_run'], true),
            ['id' => 1, 'dry_run' => true, 'field' => 'value'],
            AuthContext::anonymous(),
        );

        self::assertSame('{"field":"value"}', (string) $this->sent[0]->getBody());
    }

    public function testABodylessToolSendsNoBody(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(
            self::tool('POST', '/vps/{id}/start', ['id']), ['id' => 1], AuthContext::anonymous()
        );

        self::assertSame('', (string) $this->sent[0]->getBody());
    }

    public function testAnEmptyBodyIsOmitted(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(
            self::tool('POST', '/vps/{id}', ['id'], [], true), ['id' => 1], AuthContext::anonymous()
        );

        self::assertSame('', (string) $this->sent[0]->getBody());
    }

    // ---------------------------------------------------------------- headers

    public function testABearerTokenIsForwarded(): void
    {
        $auth = new AuthContext(authenticated: true, bearerToken: 'abc123');

        $this->subject(new Response(200, [], '{}'))->call(self::tool(), ['id' => 1], $auth);

        self::assertSame('Bearer abc123', $this->sent[0]->getHeaderLine('Authorization'));
    }

    public function testAnApiKeyIsForwarded(): void
    {
        $auth = new AuthContext(authenticated: true, apiKey: 'key-1');

        $this->subject(new Response(200, [], '{}'))->call(self::tool(), ['id' => 1], $auth);

        self::assertSame('key-1', $this->sent[0]->getHeaderLine('X-API-KEY'));
    }

    public function testASessionIdIsForwarded(): void
    {
        $auth = new AuthContext(authenticated: true, sessionId: 'sess-1');

        $this->subject(new Response(200, [], '{}'))->call(self::tool(), ['id' => 1], $auth);

        self::assertSame('sess-1', $this->sent[0]->getHeaderLine('sessionid'));
    }

    public function testAnAnonymousCallForwardsNoCredential(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        $request = $this->sent[0];

        self::assertFalse($request->hasHeader('Authorization'));
        self::assertFalse($request->hasHeader('X-API-KEY'));
        self::assertFalse($request->hasHeader('sessionid'));
    }

    /**
     * The API's IP-based session limiter assumes browser clients; every MCP call
     * arrives from this server's fixed egress, so the customer's own IP is never
     * the one calling.
     */
    public function testTheProxyMarkerIsAlwaysSent(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame('1', $this->sent[0]->getHeaderLine('X-API-APP'));
    }

    public function testEachCallCarriesATraceableRequestId(): void
    {
        $client = $this->subject(new Response(200, [], '{}'), new Response(200, [], '{}'));
        $client->call(self::tool(), ['id' => 1], AuthContext::anonymous());
        $client->call(self::tool(), ['id' => 2], AuthContext::anonymous());

        $first = $this->sent[0]->getHeaderLine('X-Request-Id');
        $second = $this->sent[1]->getHeaderLine('X-Request-Id');

        self::assertMatchesRegularExpression('/^mcp-[0-9a-f]{8}-\d{4}$/', $first);
        self::assertNotSame($first, $second, 'two calls must be distinguishable in the logs');
    }

    public function testJsonIsRequested(): void
    {
        $this->subject(new Response(200, [], '{}'))->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame('application/json', $this->sent[0]->getHeaderLine('Accept'));
    }

    // --------------------------------------------------------------- decoding

    public function testAJsonObjectIsReturnedAsIs(): void
    {
        $result = $this->subject(new Response(200, [], '{"id":1,"hostname":"a.test"}'))
            ->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame(['id' => 1, 'hostname' => 'a.test'], $result);
    }

    /**
     * `structuredContent` had to be a JSON object before MCP 2026-07-28, so a
     * top-level array was unemittable. ~89 operations across the two specs return
     * one. Both servers speak both eras, so the wrapper stays — and the parser
     * declares the wrapped shape in `outputSchema` to match.
     */
    public function testATopLevelArrayIsWrapped(): void
    {
        $result = $this->subject(new Response(200, [], '[{"id":1},{"id":2}]'))
            ->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame(['items' => [['id' => 1], ['id' => 2]]], $result);
    }

    public function testAnEmptyArrayIsAlsoWrapped(): void
    {
        $result = $this->subject(new Response(200, [], '[]'))
            ->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame(['items' => []], $result);
    }

    public function testAJsonObjectWithNumericKeysIsNotMistakenForAList(): void
    {
        $result = $this->subject(new Response(200, [], '{"0":"a","2":"b"}'))
            ->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame(['0' => 'a', '2' => 'b'], $result);
    }

    public function testANonJsonBodyIsReturnedRaw(): void
    {
        $result = $this->subject(new Response(200, [], '<html>login</html>'))
            ->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame(['raw' => '<html>login</html>'], $result);
    }

    public function testAScalarJsonBodyIsWrapped(): void
    {
        $result = $this->subject(new Response(200, [], '42'))
            ->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame(['value' => 42], $result);
    }

    // ----------------------------------------------------------------- errors

    public function testAnErrorStatusIsReportedWithItsCode(): void
    {
        $result = $this->subject(new Response(404, [], '{"error":"not found"}'))
            ->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame(['error' => 'API returned HTTP 404: not found', 'status' => 404], $result);
    }

    public function testAMessageFieldIsUsedWhenThereIsNoErrorField(): void
    {
        $result = $this->subject(new Response(422, [], '{"message":"bad input"}'))
            ->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame('API returned HTTP 422: bad input', $result['error']);
    }

    public function testAnErrorWithNoUsableBodyStillReportsTheStatus(): void
    {
        $result = $this->subject(new Response(500, [], 'Internal Server Error'))
            ->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertSame(['error' => 'API returned HTTP 500', 'status' => 500], $result);
    }

    /**
     * A transport failure must reach the model as a readable result, not as an
     * uncaught exception that becomes an opaque internal error.
     */
    public function testATransportFailureBecomesAnErrorResult(): void
    {
        $result = $this->subject(new ConnectException('refused', new Request('GET', 'https://my.test')))
            ->call(self::tool(), ['id' => 1], AuthContext::anonymous());

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('API request failed', $result['error']);
    }

    public function testATrailingSlashOnTheBaseUrlDoesNotDoubleUp(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
        $stack->push(fn (callable $next) => function (RequestInterface $req, array $opts) use ($next) {
            $this->sent[] = $req;

            return $next($req, $opts);
        });

        (new UpstreamClient('https://my.test/apiv2/', new GuzzleClient(['handler' => $stack])))
            ->call(self::tool('GET', '/vps', []), [], AuthContext::anonymous());

        self::assertSame('https://my.test/apiv2/vps', $this->lastUri());
    }
}
