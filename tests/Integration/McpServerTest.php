<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Integration;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use InterServer\Mcp\Core\Auth\AuthContext;
use InterServer\Mcp\Core\Auth\ScopeMap;
use InterServer\Mcp\Core\OpenApi\ToolCache;
use InterServer\Mcp\Core\Profile\Profile;
use InterServer\Mcp\Core\Server\ServerFactory;
use InterServer\Mcp\Core\Tests\Fixtures\RecordingTransport;
use InterServer\Mcp\Core\Tests\Fixtures\SpecBuilder;
use Mcp\Server\Session\InMemorySessionStore;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end through a real SDK server: raw JSON-RPC in, real results out.
 *
 * Everything below the transport is genuine — the registry, the loader, the
 * handler binding, the tool schemas. Only the two edges are doubled: the transport
 * (in memory instead of HTTP) and the upstream REST API (a Guzzle mock). That is
 * the layer where the wiring bugs live: a tool that registers but cannot be
 * called, a handler that receives reflection-mapped arguments instead of the raw
 * bag, an input schema the SDK refuses.
 */
final class McpServerTest extends TestCase
{
    private string $cacheDir;
    private string $specFile;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir().'/mcp-int-cache-'.$suffix;
        mkdir($this->cacheDir);

        $this->specFile = sys_get_temp_dir().'/mcp-int-spec-'.$suffix.'.yaml';
        file_put_contents($this->specFile, $this->spec());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->cacheDir);
        @unlink($this->specFile);
    }

    private function spec(): string
    {
        return SpecBuilder::make()
            ->operation('/vps/{id}', 'get', [
                'operationId' => 'getVps',
                'summary' => 'Get a VPS',
                'tags' => ['Vps'],
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['name' => 'verbose', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                ],
                'responses' => ['200' => ['description' => 'ok', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object', 'properties' => ['id' => ['type' => 'integer'], 'hostname' => ['type' => 'string']],
                ]]]]],
            ])
            ->operation('/vps', 'get', ['operationId' => 'listVps', 'summary' => 'List VPSes', 'tags' => ['Vps']])
            ->operation('/vps/{id}', 'delete', [
                'operationId' => 'deleteVps', 'summary' => 'Delete a VPS', 'tags' => ['Vps'],
                'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
            ])
            ->operation('/billing/invoices', 'get', ['operationId' => 'listInvoices', 'summary' => 'List invoices', 'tags' => ['Billing']])
            ->operation('/ping', 'get', ['operationId' => 'ping', 'summary' => 'Ping'])
            ->toYaml();
    }

    private function profile(array $overrides = []): Profile
    {
        return Profile::fromArray('client', array_merge([
            'specSource' => $this->specFile,
            'upstreamBaseUrl' => 'https://my.test/apiv2',
            'serverName' => 'InterServer Client API',
            'serverVersion' => '1.2.3',
            'scopeMap' => ScopeMap::forClient(['vps' => 'vps', 'billing' => 'billing']),
        ], $overrides));
    }

    private function factory(Response ...$upstream): ServerFactory
    {
        $cache = new ToolCache($this->cacheDir);

        if ([] === $upstream) {
            return new ServerFactory($cache);
        }

        // Swap the upstream HTTP client in by rebuilding the factory's inner
        // client through the profile — see runWithUpstream().
        return new ServerFactory($cache);
    }

    /**
     * Drive a server with a list of raw JSON-RPC frames.
     *
     * @param list<array<string, mixed>> $messages
     */
    private function drive(Profile $profile, AuthContext $auth, array $messages): RecordingTransport
    {
        $transport = new RecordingTransport(array_map(
            static fn (array $m): string => (string) json_encode($m),
            $messages,
        ));

        $this->factory()
            ->build($profile, $auth, new InMemorySessionStore())
            ->run($transport);

        return $transport;
    }

    /**
     * @param list<array<string, mixed>> $messages
     */
    private function driveWithUpstream(Profile $profile, AuthContext $auth, array $messages, Response ...$upstream): RecordingTransport
    {
        $transport = new RecordingTransport(array_map(
            static fn (array $m): string => (string) json_encode($m),
            $messages,
        ));

        $factory = new ServerFactory(
            new ToolCache($this->cacheDir),
            null,
            new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($upstream))]),
        );

        $factory->build($profile, $auth, new InMemorySessionStore())->run($transport);

        return $transport;
    }

    /** @return array<string, mixed> */
    private static function initialize(int $id = 1, string $version = '2025-06-18'): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'initialize', 'params' => [
            'protocolVersion' => $version,
            'capabilities' => [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ]];
    }

    /** @return array<string, mixed> */
    private static function initialized(): array
    {
        return ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];
    }

    // ---------------------------------------------------------- handshake era

    public function testInitializeReportsTheProfilesServerInfo(): void
    {
        $transport = $this->drive($this->profile(), AuthContext::anonymous(), [self::initialize()]);

        $result = $transport->responseTo(1)['result'];

        self::assertSame('InterServer Client API', $result['serverInfo']['name']);
        self::assertSame('1.2.3', $result['serverInfo']['version']);
        self::assertSame('2025-06-18', $result['protocolVersion']);
    }

    public function testToolsListReturnsTheCatalogue(): void
    {
        $transport = $this->drive($this->profile(), new AuthContext(authenticated: true, scopes: ['admin']), [
            self::initialize(), self::initialized(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []],
        ]);

        $names = array_column($transport->responseTo(2)['result']['tools'], 'name');

        self::assertSame(['deleteVps', 'getVps', 'listInvoices', 'listVps', 'ping'], $names);
    }

    /**
     * MCP 2026-07-28 §3.3: tools/list SHOULD be deterministically ordered. Spec
     * order is not stable across spec edits, and an unstable list costs both the
     * client's list cache and the model's prompt cache.
     */
    public function testToolsListIsSortedByName(): void
    {
        $transport = $this->drive($this->profile(), new AuthContext(authenticated: true, scopes: ['admin']), [
            self::initialize(), self::initialized(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []],
        ]);

        $names = array_column($transport->responseTo(2)['result']['tools'], 'name');
        $sorted = $names;
        sort($sorted, \SORT_STRING);

        self::assertSame($sorted, $names);
    }

    public function testEveryToolCarriesItsAnnotations(): void
    {
        $transport = $this->drive($this->profile(), new AuthContext(authenticated: true, scopes: ['admin']), [
            self::initialize(), self::initialized(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []],
        ]);

        $tools = array_column($transport->responseTo(2)['result']['tools'], null, 'name');

        self::assertTrue($tools['getVps']['annotations']['readOnlyHint']);
        self::assertFalse($tools['getVps']['annotations']['destructiveHint']);
        self::assertTrue($tools['deleteVps']['annotations']['destructiveHint']);
        self::assertSame('Get a VPS', $tools['getVps']['annotations']['title']);
    }

    public function testOutputSchemasReachTheClient(): void
    {
        $transport = $this->drive($this->profile(), new AuthContext(authenticated: true, scopes: ['admin']), [
            self::initialize(), self::initialized(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []],
        ]);

        $tools = array_column($transport->responseTo(2)['result']['tools'], null, 'name');

        self::assertSame(['id', 'hostname'], array_keys($tools['getVps']['outputSchema']['properties']));
    }

    // -------------------------------------------------------------- filtering

    /**
     * The tool set may not vary per connection, but MCP 2026-07-28 explicitly
     * allows it to vary by presented authorization. This is that.
     */
    public function testTheCatalogueIsNarrowedToTheCallersScopes(): void
    {
        $transport = $this->drive($this->profile(), new AuthContext(authenticated: true, scopes: ['vps:read']), [
            self::initialize(), self::initialized(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []],
        ]);

        $names = array_column($transport->responseTo(2)['result']['tools'], 'name');

        self::assertContains('getVps', $names);
        self::assertContains('listVps', $names);
        self::assertNotContains('deleteVps', $names, 'a read scope must not expose a DELETE tool');
        self::assertNotContains('listInvoices', $names, 'a vps scope must not expose billing tools');
    }

    public function testUnscopedToolsSurviveAnEmptyGrant(): void
    {
        $transport = $this->drive($this->profile(), AuthContext::anonymous(), [
            self::initialize(), self::initialized(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []],
        ]);

        self::assertSame(['ping'], array_column($transport->responseTo(2)['result']['tools'], 'name'));
    }

    public function testAnAllowlistNarrowsTheCatalogueFurther(): void
    {
        $profile = $this->profile(['toolAllowlist' => ['ping', 'listVps'], 'scopeMap' => null]);

        $transport = $this->drive($profile, AuthContext::anonymous(), [
            self::initialize(), self::initialized(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []],
        ]);

        self::assertSame(['listVps', 'ping'], array_column($transport->responseTo(2)['result']['tools'], 'name'));
    }

    public function testAToolOutsideTheCallersScopeIsNotCallable(): void
    {
        $transport = $this->drive($this->profile(), new AuthContext(authenticated: true, scopes: ['vps:read']), [
            self::initialize(), self::initialized(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                'name' => 'deleteVps', 'arguments' => ['id' => 1],
            ]],
        ]);

        $response = $transport->responseTo(2);

        self::assertTrue(
            isset($response['error']) || true === ($response['result']['isError'] ?? false),
            'calling a filtered-out tool must not succeed',
        );
    }

    // ------------------------------------------------------------- tools/call

    /**
     * The reason the handler is a `ToolHandlerInterface` and not a closure:
     * `execute()` gets the raw argument bag. Tool parameters come from an OpenAPI
     * document and can be named anything, so reflection-based name mapping — what
     * `Builder::addTool(closure)` does — is not an option.
     */
    public function testCallingAToolProxiesToTheApiAndReturnsStructuredContent(): void
    {
        $transport = $this->driveWithUpstream(
            $this->profile(),
            new AuthContext(authenticated: true, scopes: ['admin'], bearerToken: 'tok'),
            [
                self::initialize(), self::initialized(),
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                    'name' => 'getVps', 'arguments' => ['id' => 4242, 'verbose' => true],
                ]],
            ],
            new Response(200, [], '{"id":4242,"hostname":"vps.example.com"}'),
        );

        $result = $transport->responseTo(2)['result'];

        self::assertArrayNotHasKey('error', $transport->responseTo(2));
        self::assertSame(['id' => 4242, 'hostname' => 'vps.example.com'], $result['structuredContent']);
        self::assertNotEmpty($result['content']);
    }

    public function testAToolCallWithNoArgumentsWorks(): void
    {
        $transport = $this->driveWithUpstream(
            $this->profile(),
            new AuthContext(authenticated: true, scopes: ['admin']),
            [
                self::initialize(), self::initialized(),
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'ping', 'arguments' => []]],
            ],
            new Response(200, [], '{"pong":true}'),
        );

        self::assertSame(['pong' => true], $transport->responseTo(2)['result']['structuredContent']);
    }

    public function testAnApiErrorReachesTheClientAsAReadableResult(): void
    {
        $transport = $this->driveWithUpstream(
            $this->profile(),
            new AuthContext(authenticated: true, scopes: ['admin']),
            [
                self::initialize(), self::initialized(),
                ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
                    'name' => 'getVps', 'arguments' => ['id' => 1],
                ]],
            ],
            new Response(404, [], '{"error":"no such vps"}'),
        );

        self::assertStringContainsString('no such vps', json_encode($transport->responseTo(2)));
    }

    public function testCallingAnUnknownToolIsAnError(): void
    {
        $transport = $this->drive($this->profile(), new AuthContext(authenticated: true, scopes: ['admin']), [
            self::initialize(), self::initialized(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'noSuchTool', 'arguments' => []]],
        ]);

        $response = $transport->responseTo(2);

        self::assertTrue(isset($response['error']) || true === ($response['result']['isError'] ?? false));
    }

    // ------------------------------------------------------------ the modern era

    /**
     * The whole point of D4: both eras from one server, with no protocol code of
     * our own. A modern request carries its version in `params._meta` and skips
     * `initialize` entirely.
     */
    public function testTheModernEraNeedsNoHandshake(): void
    {
        $transport = $this->drive($this->profile(), new AuthContext(authenticated: true, scopes: ['admin']), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]]],
        ]);

        $result = $transport->responseTo(1)['result'] ?? null;

        self::assertNotNull($result, 'a modern tools/list must succeed without initialize');
        self::assertNotEmpty($result['tools']);
    }

    public function testServerDiscoverAdvertisesTheModernRevision(): void
    {
        $transport = $this->drive($this->profile(), AuthContext::anonymous(), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]]],
        ]);

        $result = $transport->responseTo(1)['result'];

        self::assertContains('2026-07-28', $result['supportedVersions']);

        // The modern era has no `initialize` result to carry serverInfo, so it
        // moves into the reserved `_meta` namespace. Clients that look for a
        // top-level `serverInfo` here find nothing.
        self::assertSame(
            'InterServer Client API',
            $result['_meta']['io.modelcontextprotocol/serverInfo']['name'],
        );
    }

    public function testServerDiscoverIsCacheable(): void
    {
        $transport = $this->drive($this->profile(), AuthContext::anonymous(), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'server/discover', 'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]]],
        ]);

        $result = $transport->responseTo(1)['result'];

        self::assertSame(ServerFactory::DISCOVER_TTL_MS, $result['ttlMs']);
        self::assertSame('private', $result['cacheScope']);
    }

    /**
     * §3.3 makes `ttlMs` and `cacheScope` required on cacheable results. The SDK's
     * default is `ttlMs: 0, private` — conformant, and a flat refusal to cache
     * anything, which costs a client a full re-list per connection.
     */
    public function testModernResultsCarryTheCachingHints(): void
    {
        $transport = $this->drive($this->profile(), new AuthContext(authenticated: true, scopes: ['admin']), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]]],
        ]);

        $result = $transport->responseTo(1)['result'];

        self::assertSame('complete', $result['resultType']);
        self::assertSame(ServerFactory::TOOL_LIST_TTL_MS, $result['ttlMs']);
    }

    /**
     * A scope-filtered catalogue is derived from the caller's own token. A shared
     * cache serving one caller's list to another would leak which tools — and so
     * which entitlements — that first caller has.
     */
    public function testAFilteredToolListIsNeverPubliclyCacheable(): void
    {
        $transport = $this->drive($this->profile(), new AuthContext(authenticated: true, scopes: ['vps:read']), [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]]],
        ]);

        self::assertSame('private', $transport->responseTo(1)['result']['cacheScope']);
    }

    /**
     * Both eras, one endpoint, one build — served in the same process.
     */
    public function testBothErasAreServedFromOneServer(): void
    {
        $auth = new AuthContext(authenticated: true, scopes: ['admin']);

        $handshake = $this->drive($this->profile(), $auth, [
            self::initialize(1), self::initialized(),
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []],
        ]);

        $modern = $this->drive($this->profile(), $auth, [
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
                'io.modelcontextprotocol/clientCapabilities' => [],
            ]]],
        ]);

        self::assertSame(
            array_column($handshake->responseTo(2)['result']['tools'], 'name'),
            array_column($modern->responseTo(2)['result']['tools'], 'name'),
            'the same caller must see the same catalogue in both eras',
        );
    }

    // ---------------------------------------------------------------- laziness

    /**
     * The loader runs on first registry read, not at build time. The origin repo's
     * client build paid a full OpenAPI parse on every CORS preflight — a request
     * that never reads the registry at all.
     */
    public function testBuildingAServerParsesNothing(): void
    {
        $profile = $this->profile(['specSource' => '/absolutely/not/a/file.yaml']);

        $server = $this->factory()->build($profile, AuthContext::anonymous(), new InMemorySessionStore());

        self::assertNotNull($server, 'building must not touch the spec');
    }
}
