<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Unit\OpenApi;

use InterServer\Mcp\Core\Exception\SpecUnavailableException;
use InterServer\Mcp\Core\OpenApi\DestructiveClassifier;
use InterServer\Mcp\Core\OpenApi\SpecFetcher;
use InterServer\Mcp\Core\OpenApi\ToolCache;
use InterServer\Mcp\Core\OpenApi\ToolDefinition;
use InterServer\Mcp\Core\Tests\Fixtures\SpecBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \InterServer\Mcp\Core\OpenApi\ToolCache
 * @covers \InterServer\Mcp\Core\OpenApi\ToolDefinition
 */
final class ToolCacheTest extends TestCase
{
    private string $cacheDir;
    private string $specDir;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->cacheDir = sys_get_temp_dir().'/tool-cache-'.$suffix;
        $this->specDir = sys_get_temp_dir().'/tool-spec-'.$suffix;
        mkdir($this->cacheDir);
        mkdir($this->specDir);
    }

    protected function tearDown(): void
    {
        foreach ([$this->cacheDir, $this->specDir] as $dir) {
            foreach (glob($dir.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    private function writeSpec(string $name = 'spec.yaml', ?string $body = null): string
    {
        $path = $this->specDir.'/'.$name;
        file_put_contents(
            $path,
            $body ?? SpecBuilder::make()->operation('/things/{id}', 'get', SpecBuilder::typicalGet())->toYaml()
        );

        return $path;
    }

    private function cache(): ToolCache
    {
        return new ToolCache($this->cacheDir);
    }

    /** @return list<string> */
    private function cacheFiles(): array
    {
        return glob($this->cacheDir.'/mcp_tools_*.php') ?: [];
    }

    public function testFirstReadParsesAndWritesAnEntry(): void
    {
        $tools = $this->cache()->get($this->writeSpec());

        self::assertCount(1, $tools);
        self::assertInstanceOf(ToolDefinition::class, $tools[0]);
        self::assertCount(1, $this->cacheFiles());
    }

    public function testSecondReadIsServedFromTheCache(): void
    {
        $spec = $this->writeSpec();
        $cache = $this->cache();

        $first = $cache->get($spec);

        // Overwrite the entry with a sentinel. If it is used, the cache was read;
        // if the parser ran again, the sentinel would be gone.
        $entry = $this->cacheFiles()[0];
        file_put_contents($entry, "<?php\n\nreturn ".var_export([[
            'name' => 'fromCache', 'description' => '', 'httpMethod' => 'GET', 'path' => '/x',
            'inputSchema' => ['type' => 'object'], 'pathParams' => [], 'queryParams' => [],
            'hasBody' => false, 'annotations' => [], 'outputSchema' => null, 'tag' => '',
        ]], true).";\n");

        $second = $cache->get($spec);

        self::assertSame('getThing', $first[0]->name);
        self::assertSame('fromCache', $second[0]->name);
    }

    /**
     * The whole point of content keying: an edited spec must not be served from
     * the entry built for the previous revision.
     */
    public function testChangedSpecContentInvalidatesTheEntry(): void
    {
        $spec = $this->writeSpec();
        $cache = $this->cache();

        self::assertSame('getThing', $cache->get($spec)[0]->name);

        file_put_contents(
            $spec,
            SpecBuilder::make()->operation('/things/{id}', 'get', SpecBuilder::typicalGet('getRenamedThing'))->toYaml()
        );

        self::assertSame('getRenamedThing', $cache->get($spec)[0]->name);
    }

    /**
     * Content keying also means an mtime bump alone must not invalidate: a deploy
     * that rewrites an identical spec should not cost a 593 ms reparse.
     */
    public function testTouchingTheSpecWithoutChangingItKeepsTheEntry(): void
    {
        $spec = $this->writeSpec();
        $cache = $this->cache();
        $cache->get($spec);

        $before = $this->cacheFiles();
        touch($spec, time() + 60);
        $cache->get($spec);

        self::assertSame($before, $this->cacheFiles(), 'an unchanged spec must not produce a second entry');
    }

    public function testTwoProfilesOverOneSpecGetSeparateEntries(): void
    {
        $spec = $this->writeSpec();
        $cache = $this->cache();

        $cache->get($spec, 'client');
        $cache->get($spec, 'public');

        self::assertCount(2, $this->cacheFiles());
    }

    /**
     * A missing spec is an error, never an empty tool list. A server answering
     * `tools/list` with `[]` looks healthy and is indistinguishable from one that
     * legitimately exposes nothing — the exact shape defect ① took.
     */
    public function testMissingSpecThrowsRatherThanReturningNothing(): void
    {
        $this->expectException(SpecUnavailableException::class);

        $this->cache()->get($this->specDir.'/absent.yaml');
    }

    public function testMissingSpecThrowsEvenWhenAnEntryExists(): void
    {
        $spec = $this->writeSpec();
        $cache = $this->cache();
        $cache->get($spec);

        unlink($spec);

        $this->expectException(SpecUnavailableException::class);

        $cache->get($spec);
    }

    public function testWarmWritesAnEntryWithoutReadingOne(): void
    {
        $tools = $this->cache()->warm($this->writeSpec());

        self::assertCount(1, $tools);
        self::assertCount(1, $this->cacheFiles());
    }

    public function testWarmOverwritesACorruptEntry(): void
    {
        $spec = $this->writeSpec();
        $cache = $this->cache();
        $cache->get($spec);

        file_put_contents($this->cacheFiles()[0], "<?php\n\nreturn 'not an array';\n");

        self::assertSame('getThing', $cache->warm($spec)[0]->name);
    }

    public function testACorruptEntryIsReparsedRatherThanReturned(): void
    {
        $spec = $this->writeSpec();
        $cache = $this->cache();
        $cache->get($spec);

        file_put_contents($this->cacheFiles()[0], "<?php\n\nreturn 'not an array';\n");

        self::assertSame('getThing', $cache->get($spec)[0]->name);
    }

    public function testRoundTripPreservesEveryToolField(): void
    {
        $spec = $this->writeSpec();
        $cache = $this->cache();

        $parsed = $cache->get($spec);
        $cached = $cache->get($spec);

        self::assertEquals($parsed[0]->toArray(), $cached[0]->toArray());
        self::assertNotNull($cached[0]->outputSchema);
        self::assertSame('Things', $cached[0]->tag);
        self::assertTrue($cached[0]->isReadOnly());
    }

    public function testClearRemovesEveryEntry(): void
    {
        $cache = $this->cache();
        $cache->get($this->writeSpec('a.yaml'));
        $cache->get($this->writeSpec('b.yaml', SpecBuilder::make()->operation('/other', 'get', ['operationId' => 'getOther'])->toYaml()));

        self::assertSame(2, $cache->clear());
        self::assertSame([], $this->cacheFiles());
    }

    public function testPruneRemovesOnlyStaleEntries(): void
    {
        $cache = $this->cache();
        $cache->get($this->writeSpec('a.yaml'));
        $cache->get($this->writeSpec('b.yaml', SpecBuilder::make()->operation('/other', 'get', ['operationId' => 'getOther'])->toYaml()));

        $files = $this->cacheFiles();
        touch($files[0], time() - 800000);

        self::assertSame(1, $cache->prune(604800));
        self::assertCount(1, $this->cacheFiles());
    }

    public function testPruneOnAnEmptyDirectoryIsHarmless(): void
    {
        self::assertSame(0, $this->cache()->prune());
    }

    public function testCacheDirectoryIsCreatedOnDemand(): void
    {
        $nested = $this->cacheDir.'/deep/nested';
        $cache = new ToolCache($nested);

        $cache->get($this->writeSpec());

        self::assertDirectoryExists($nested);
        self::assertCount(1, glob($nested.'/mcp_tools_*.php') ?: []);

        array_map('unlink', glob($nested.'/*') ?: []);
        rmdir($nested);
        rmdir($this->cacheDir.'/deep');
    }

    /**
     * An unwritable cache is a latency problem, not a correctness one: the server
     * must keep answering, just slowly.
     */
    public function testAnUnwritableCacheStillReturnsTools(): void
    {
        $cache = new ToolCache('/proc/nonexistent-cache-dir');

        self::assertCount(1, $cache->get($this->writeSpec()));
    }

    public function testEntriesAreValidPhpReturningAList(): void
    {
        $this->cache()->get($this->writeSpec());

        $contents = file_get_contents($this->cacheFiles()[0]);

        self::assertStringStartsWith('<?php', $contents);
        self::assertIsArray(require $this->cacheFiles()[0]);
    }

    public function testNoTemporaryFilesAreLeftBehind(): void
    {
        $this->cache()->get($this->writeSpec());

        self::assertSame([], glob($this->cacheDir.'/*.tmp') ?: []);
    }

    public function testAnInjectedFetcherIsUsed(): void
    {
        $cache = new ToolCache($this->cacheDir, new SpecFetcher());

        self::assertCount(1, $cache->get($this->writeSpec()));
    }

    // ------------------------------------------------------------------------
    // The caller's classifier.
    //
    // Profile::$destructiveClassifier existed, was documented as the way each
    // application supplies its own rules, and was read by nothing — so every app's
    // rules were silently discarded and the defaults applied. These pin the wiring
    // that was missing, and the cache key that has to move with it.
    // ------------------------------------------------------------------------

    public function testTheCallersClassifierDecidesTheAnnotations(): void
    {
        // A GET the default rules consider read-only.
        $spec = $this->writeSpec(
            'acting.yaml',
            SpecBuilder::make()
                ->operation('/vps/{id}/stop', 'get', SpecBuilder::typicalGet('doVpsStop', 'Power off a VPS'))
                ->toYaml()
        );

        $default = $this->cache()->get($spec, 'p');
        self::assertTrue($default[0]->isReadOnly(), 'precondition: the default rules see a plain GET');

        $classifier = new DestructiveClassifier(operationIdPatterns: ['/^do[A-Z]/']);
        $tools = $this->cache()->get($spec, 'p', $classifier);

        self::assertFalse(
            $tools[0]->isReadOnly(),
            'a GET that mutates must not be advertised read-only — that is what tells a client it needs no confirmation'
        );
    }

    /**
     * Two rule sets over one spec are two different tool catalogues. Sharing a
     * cache entry would serve one profile's annotations to the other.
     */
    public function testDifferentRulesDoNotShareACacheEntry(): void
    {
        $spec = $this->writeSpec(
            'shared.yaml',
            SpecBuilder::make()
                ->operation('/vps/{id}/stop', 'get', SpecBuilder::typicalGet('doVpsStop', 'Power off a VPS'))
                ->toYaml()
        );

        $strict = new DestructiveClassifier(operationIdPatterns: ['/^do[A-Z]/']);

        $lenientTools = $this->cache()->get($spec, 'same-namespace');
        $strictTools = $this->cache()->get($spec, 'same-namespace', $strict);

        self::assertTrue($lenientTools[0]->isReadOnly());
        self::assertFalse($strictTools[0]->isReadOnly(), 'the same namespace and spec, but different rules');
        self::assertCount(2, $this->cacheFiles(), 'each rule set needs its own entry');
    }

    /**
     * The property that cost real debugging time: after editing a rule, a warm
     * cache kept serving the old annotation, which reads as "the change did not
     * work" rather than "the cache is stale".
     */
    public function testChangingARuleInvalidatesTheCacheByItself(): void
    {
        $spec = $this->writeSpec(
            'evolving.yaml',
            SpecBuilder::make()
                ->operation('/vps/{id}/stop', 'get', SpecBuilder::typicalGet('doVpsStop', 'Power off a VPS'))
                ->toYaml()
        );

        $before = $this->cache()->get($spec, 'p', new DestructiveClassifier(operationIdPatterns: []));
        self::assertTrue($before[0]->isReadOnly());

        $after = $this->cache()->get($spec, 'p', new DestructiveClassifier(operationIdPatterns: ['/^do[A-Z]/']));
        self::assertFalse($after[0]->isReadOnly(), 'the new rule must take effect without clearing the cache by hand');
    }

    /**
     * Reordering a list changes nothing about what the classifier decides, so it
     * must not throw away a warm cache — warming it is the largest single
     * determinant of first-request latency.
     */
    public function testReorderingRulesKeepsTheSameCacheEntry(): void
    {
        $spec = $this->writeSpec('order.yaml');

        $a = new DestructiveClassifier(pathTerms: ['cancel', 'delete', 'purge']);
        $b = new DestructiveClassifier(pathTerms: ['purge', 'cancel', 'delete']);

        self::assertSame($a->fingerprint(), $b->fingerprint());

        $this->cache()->get($spec, 'p', $a);
        $this->cache()->get($spec, 'p', $b);

        self::assertCount(1, $this->cacheFiles());
    }
}
