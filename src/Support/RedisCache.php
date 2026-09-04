<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * A small PSR-16 cache over phpredis.
 *
 * Two things in this package need shared state across PHP-FPM workers, and both
 * are correctness requirements rather than optimisations:
 *
 *  - **handshake-era sessions.** With an in-process store every request is a
 *    fresh worker, so the session `initialize` created is gone by the next call.
 *    The official conformance suite fails *every* scenario that way, with
 *    "Session not found or has expired".
 *  - **introspection results**, including the negative ones — without a shared
 *    negative cache a token-guessing loop reaches the authorization server's
 *    database once per guess, per worker.
 *
 * Prefixes are per application, so the client and admin servers can share one
 * Redis without either being able to read the other's sessions.
 *
 * Values are serialised, not JSON-encoded: introspection results and session
 * payloads are PHP structures, and a JSON round-trip would quietly turn an
 * integer key into a string.
 */
final class RedisCache implements CacheInterface
{
    public function __construct(
        private readonly \Redis $redis,
        private readonly string $prefix = 'mcp:',
    ) {
    }

    /**
     * Build from a `redis://host:port/db` or `tcp://host:port` DSN.
     *
     * A connection failure returns null rather than throwing: losing Redis should
     * degrade this server to file-backed sessions and uncached introspection, not
     * take it down. The caller decides what to fall back to.
     */
    public static function fromDsn(string $dsn, string $prefix = 'mcp:'): ?self
    {
        if (!class_exists(\Redis::class)) {
            return null;
        }

        $parts = parse_url($dsn);
        if (false === $parts || !isset($parts['host'])) {
            return null;
        }

        $redis = new \Redis();

        try {
            if (!$redis->connect($parts['host'], $parts['port'] ?? 6379, 1.0)) {
                return null;
            }
            if (isset($parts['pass'])) {
                $redis->auth($parts['pass']);
            }
            $database = (int) ltrim($parts['path'] ?? '', '/');
            if ($database > 0) {
                $redis->select($database);
            }
        } catch (\Throwable) {
            return null;
        }

        return new self($redis, $prefix);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        try {
            $raw = $this->redis->get($this->prefix.$key);
        } catch (\Throwable) {
            return $default;
        }

        if (!\is_string($raw)) {
            return $default;
        }

        $value = @unserialize($raw, ['allowed_classes' => false]);

        return false === $value && 'b:0;' !== $raw ? $default : $value;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $seconds = $this->seconds($ttl);
        $serialised = serialize($value);

        try {
            // A zero or negative TTL means "already expired" — storing it would be
            // worse than not storing it, because a later read would treat a dead
            // token as live for the remainder of the default TTL.
            if (null !== $seconds && $seconds <= 0) {
                $this->redis->del($this->prefix.$key);

                return true;
            }

            return null === $seconds
                ? (bool) $this->redis->set($this->prefix.$key, $serialised)
                : (bool) $this->redis->setex($this->prefix.$key, $seconds, $serialised);
        } catch (\Throwable) {
            return false;
        }
    }

    public function delete(string $key): bool
    {
        try {
            $this->redis->del($this->prefix.$key);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Deletes only this prefix's keys — never `FLUSHDB`, which would take out
     * whatever else shares the instance.
     */
    public function clear(): bool
    {
        try {
            $iterator = null;
            do {
                $keys = $this->redis->scan($iterator, $this->prefix.'*', 500);
                if (\is_array($keys) && [] !== $keys) {
                    $this->redis->del($keys);
                }
            } while ($iterator > 0);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param iterable<string> $keys
     *
     * @return iterable<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[(string) $key] = $this->get((string) $key, $default);
        }

        return $out;
    }

    /**
     * @param iterable<string, mixed> $values
     */
    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        $ok = true;
        foreach ($values as $key => $value) {
            $ok = $this->set((string) $key, $value, $ttl) && $ok;
        }

        return $ok;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        $ok = true;
        foreach ($keys as $key) {
            $ok = $this->delete((string) $key) && $ok;
        }

        return $ok;
    }

    public function has(string $key): bool
    {
        try {
            return (bool) $this->redis->exists($this->prefix.$key);
        } catch (\Throwable) {
            return false;
        }
    }

    private function seconds(null|int|\DateInterval $ttl): ?int
    {
        if (null === $ttl) {
            return null;
        }
        if (\is_int($ttl)) {
            return $ttl;
        }

        return (new \DateTimeImmutable())->add($ttl)->getTimestamp() - time();
    }
}
