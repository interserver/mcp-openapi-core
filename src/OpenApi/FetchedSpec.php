<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\OpenApi;

/**
 * A retrieved OpenAPI document plus the identity its cache entry is keyed on.
 */
final class FetchedSpec
{
    public function __construct(
        public readonly string $content,
        /** Strong content identity: `etag:<value>` or `sha256:<hex>`. */
        public readonly string $fingerprint,
        public readonly string $source,
    ) {
    }

    /**
     * Filesystem-safe, collision-resistant cache key.
     *
     * The source is folded in so two profiles pointing at different specs never
     * share an entry even in the (impossible-in-practice, but cheap to exclude)
     * event of identical content.
     */
    public function cacheKey(string $namespace = ''): string
    {
        return hash('sha256', $namespace."\0".$this->source."\0".$this->fingerprint);
    }
}
