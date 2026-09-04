<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\OpenApi;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use InterServer\Mcp\Core\Exception\SpecUnavailableException;
use InterServer\Mcp\Core\OpenApi\SpecFetcher;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\OpenApi\SpecFetcher
 * @covers \InterServer\Mcp\Core\OpenApi\FetchedSpec
 */
final class SpecFetcherTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/spec-fetcher-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    private function clientReturning(Response|\Throwable ...$responses): GuzzleClient
    {
        return new GuzzleClient(['handler' => HandlerStack::create(new MockHandler($responses))]);
    }

    // ------------------------------------------------------------------ file

    public function testReadsALocalFile(): void
    {
        $path = $this->tmpDir.'/spec.yaml';
        file_put_contents($path, "openapi: 3.0.0\n");

        $spec = (new SpecFetcher())->fetch($path);

        self::assertSame("openapi: 3.0.0\n", $spec->content);
        self::assertSame($path, $spec->source);
        self::assertSame('sha256:'.hash('sha256', "openapi: 3.0.0\n"), $spec->fingerprint);
    }

    /**
     * The failure mode this class exists to eliminate: the implementation it
     * replaced compared `filemtime($cache) >= filemtime($spec)`, and `filemtime()`
     * on a missing file returns false, so the comparison held and a stale cache
     * served a phantom catalogue. Here a missing spec is an error, always.
     */
    public function testMissingFileIsAnError(): void
    {
        $this->expectException(SpecUnavailableException::class);
        $this->expectExceptionMessageMatches('/does not exist or is not readable/');

        (new SpecFetcher())->fetch($this->tmpDir.'/nope.yaml');
    }

    public function testEmptyFileIsAnError(): void
    {
        $path = $this->tmpDir.'/empty.yaml';
        file_put_contents($path, "   \n");

        $this->expectException(SpecUnavailableException::class);

        (new SpecFetcher())->fetch($path);
    }

    public function testDirectoryIsNotAcceptedAsASpec(): void
    {
        $this->expectException(SpecUnavailableException::class);

        (new SpecFetcher())->fetch($this->tmpDir);
    }

    // ------------------------------------------------------------------ http

    public function testFetchesOverHttp(): void
    {
        $fetcher = new SpecFetcher($this->clientReturning(new Response(200, [], 'openapi: 3.0.0')));

        $spec = $fetcher->fetch('https://api.test/spec.yaml');

        self::assertSame('openapi: 3.0.0', $spec->content);
        self::assertSame('https://api.test/spec.yaml', $spec->source);
    }

    public function testPlainHttpIsAlsoFetched(): void
    {
        $fetcher = new SpecFetcher($this->clientReturning(new Response(200, [], 'openapi: 3.0.0')));

        self::assertSame('openapi: 3.0.0', $fetcher->fetch('http://api.test/spec.yaml')->content);
    }

    public function testStrongEtagIsUsedAsTheFingerprint(): void
    {
        $fetcher = new SpecFetcher($this->clientReturning(new Response(200, ['ETag' => '"abc123"'], 'body')));

        self::assertSame('etag:abc123', $fetcher->fetch('https://api.test/spec.yaml')->fingerprint);
    }

    /**
     * A weak validator explicitly is not a content identity, so it must not be
     * used as a cache key — two different documents may legitimately share one.
     */
    public function testWeakEtagIsIgnoredInFavourOfAContentHash(): void
    {
        $fetcher = new SpecFetcher($this->clientReturning(new Response(200, ['ETag' => 'W/"abc123"'], 'body')));

        self::assertSame('sha256:'.hash('sha256', 'body'), $fetcher->fetch('https://api.test/spec.yaml')->fingerprint);
    }

    public function testNonSuccessStatusIsAnError(): void
    {
        $fetcher = new SpecFetcher($this->clientReturning(new Response(404, [], 'nope')));

        $this->expectException(SpecUnavailableException::class);
        $this->expectExceptionMessageMatches('/returned HTTP 404/');

        $fetcher->fetch('https://api.test/spec.yaml');
    }

    public function testRedirectStatusIsAnError(): void
    {
        $fetcher = new SpecFetcher($this->clientReturning(new Response(304, [], '')));

        $this->expectException(SpecUnavailableException::class);

        $fetcher->fetch('https://api.test/spec.yaml');
    }

    public function testEmptyHttpBodyIsAnError(): void
    {
        $fetcher = new SpecFetcher($this->clientReturning(new Response(200, [], '  ')));

        $this->expectException(SpecUnavailableException::class);
        $this->expectExceptionMessageMatches('/was empty/');

        $fetcher->fetch('https://api.test/spec.yaml');
    }

    public function testTransportFailureIsAnError(): void
    {
        $fetcher = new SpecFetcher($this->clientReturning(
            new ConnectException('connection refused', new Request('GET', 'https://api.test/spec.yaml'))
        ));

        $this->expectException(SpecUnavailableException::class);
        $this->expectExceptionMessageMatches('/Could not fetch OpenAPI spec/');

        $fetcher->fetch('https://api.test/spec.yaml');
    }

    // -------------------------------------------------------------- cacheKey

    public function testIdenticalContentAtOneSourceYieldsOneKey(): void
    {
        $path = $this->tmpDir.'/spec.yaml';
        file_put_contents($path, 'openapi: 3.0.0');

        $fetcher = new SpecFetcher();

        self::assertSame($fetcher->fetch($path)->cacheKey(), $fetcher->fetch($path)->cacheKey());
    }

    public function testChangedContentYieldsADifferentKey(): void
    {
        $path = $this->tmpDir.'/spec.yaml';
        $fetcher = new SpecFetcher();

        file_put_contents($path, 'openapi: 3.0.0');
        $first = $fetcher->fetch($path)->cacheKey();

        file_put_contents($path, 'openapi: 3.1.0');
        $second = $fetcher->fetch($path)->cacheKey();

        self::assertNotSame($first, $second);
    }

    public function testTwoSourcesWithIdenticalContentDoNotShareAKey(): void
    {
        $a = $this->tmpDir.'/a.yaml';
        $b = $this->tmpDir.'/b.yaml';
        file_put_contents($a, 'same');
        file_put_contents($b, 'same');

        $fetcher = new SpecFetcher();

        self::assertNotSame($fetcher->fetch($a)->cacheKey(), $fetcher->fetch($b)->cacheKey());
    }

    public function testNamespaceSeparatesKeysForTheSameSpec(): void
    {
        $path = $this->tmpDir.'/spec.yaml';
        file_put_contents($path, 'openapi: 3.0.0');

        $spec = (new SpecFetcher())->fetch($path);

        self::assertNotSame($spec->cacheKey('client'), $spec->cacheKey('public'));
    }

    public function testCacheKeyIsFilesystemSafe(): void
    {
        $path = $this->tmpDir.'/spec.yaml';
        file_put_contents($path, 'openapi: 3.0.0');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (new SpecFetcher())->fetch($path)->cacheKey());
    }
}
