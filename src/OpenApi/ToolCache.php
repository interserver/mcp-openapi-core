<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\OpenApi;

/**
 * Content-keyed, opcache-backed cache of parsed tool definitions.
 *
 * Parsing is not cheap enough to do per request: measured on the 1.31 MB client
 * spec, `Yaml::parse()` alone costs ~593 ms against ~14 ms to build and register
 * the resulting 311 tools. Warming this cache is the single largest determinant
 * of first-request latency, which is why {@see warm()} exists and why deploys and
 * cron are expected to call it.
 *
 * Entries are written as `<?php return [...];` so opcache holds the parsed opcodes
 * and a warm read is a `require` of already-compiled code rather than a JSON decode.
 *
 * The key is the spec's **content** fingerprint, never its path — see
 * {@see SpecFetcher} for the failure that choice prevents. A superseded entry is
 * simply never read again; {@see prune()} removes them.
 */
final class ToolCache
{
    private const PREFIX = 'mcp_tools_';

    public function __construct(
        private readonly string $cacheDir,
        private readonly SpecFetcher $fetcher = new SpecFetcher(),
        private readonly OpenApiParser $parser = new OpenApiParser(),
    ) {
    }

    /**
     * Fetch (or re-use), parse (or re-use), and return the definitions for a spec.
     *
     * @return list<ToolDefinition>
     */
    public function get(string $specSource, string $namespace = '', ?DestructiveClassifier $classifier = null): array
    {
        $spec = $this->fetcher->fetch($specSource);

        // The classifier decides every tool's annotations, so two profiles with
        // different rules produce different tools from the same spec and must not
        // share an entry. Folding its fingerprint into the namespace also means a
        // rule change invalidates the cache on its own — the alternative is a stale
        // annotation that looks like the change simply did not take.
        if (null !== $classifier) {
            $namespace .= "\0".$classifier->fingerprint();
        }

        $file = $this->fileFor($spec->cacheKey($namespace));

        if (is_readable($file)) {
            /** @var mixed $cached */
            $cached = require $file;
            if (\is_array($cached)) {
                return array_map(
                    static fn (array $row): ToolDefinition => ToolDefinition::fromArray($row),
                    $cached
                );
            }
        }

        // Parse with the caller's classifier when there is one. The default parser
        // is only correct for a caller that has no rules of its own.
        $parser = null === $classifier ? $this->parser : new OpenApiParser($classifier);

        $tools = $parser->parseContent($spec->content);
        $this->write($file, $tools);

        return $tools;
    }

    /**
     * Parse and store, unconditionally. Called from `bin/warm-cache` on deploy.
     *
     * @return list<ToolDefinition>
     */
    public function warm(string $specSource, string $namespace = ''): array
    {
        $spec = $this->fetcher->fetch($specSource);
        $tools = $this->parser->parseContent($spec->content);
        $this->write($this->fileFor($spec->cacheKey($namespace)), $tools);

        return $tools;
    }

    /**
     * Delete entries not read for $olderThanSeconds.
     *
     * Superseded entries are inert, not harmful — but a spec that changes on every
     * deploy would otherwise accumulate one 650 KB file per revision forever.
     */
    public function prune(int $olderThanSeconds = 604800): int
    {
        $cutoff = time() - $olderThanSeconds;
        $removed = 0;

        foreach (glob($this->cacheDir.'/'.self::PREFIX.'*.php') ?: [] as $file) {
            if (filemtime($file) < $cutoff && @unlink($file)) {
                ++$removed;
            }
        }

        return $removed;
    }

    public function clear(): int
    {
        $removed = 0;
        foreach (glob($this->cacheDir.'/'.self::PREFIX.'*.php') ?: [] as $file) {
            if (@unlink($file)) {
                if (\function_exists('opcache_invalidate')) {
                    opcache_invalidate($file, true);
                }
                ++$removed;
            }
        }

        return $removed;
    }

    private function fileFor(string $key): string
    {
        return $this->cacheDir.'/'.self::PREFIX.$key.'.php';
    }

    /**
     * @param list<ToolDefinition> $tools
     */
    private function write(string $file, array $tools): void
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0o750, true) && !is_dir($this->cacheDir)) {
            return; // Un-writable cache is a latency problem, not a correctness one.
        }

        $export = var_export(array_map(static fn (ToolDefinition $t): array => $t->toArray(), $tools), true);

        // Write-then-rename so a concurrent request never `require`s a half-written
        // file. Two workers racing produce identical bytes, so either winner is correct.
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        if (false === @file_put_contents($tmp, "<?php\n\nreturn {$export};\n", LOCK_EX)) {
            return;
        }
        if (!@rename($tmp, $file)) {
            @unlink($tmp);

            return;
        }

        if (\function_exists('opcache_invalidate')) {
            opcache_invalidate($file, true);
        }
    }
}
