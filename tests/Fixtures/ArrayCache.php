<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Fixtures;

use Psr\SimpleCache\CacheInterface;

/**
 * In-memory PSR-16 cache that also records the TTL each entry was written with,
 * so tests can assert on caching *policy* rather than only on hit/miss.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, int|null> */
    private array $ttls = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = \is_int($ttl) ? $ttl : null;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->values[$key], $this->ttls[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->values = [];
        $this->ttls = [];

        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }

        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set((string) $key, $value, $ttl);
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete((string) $key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return isset($this->values[$key]);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->values);
    }

    public function ttlFor(string $key): ?int
    {
        return $this->ttls[$key] ?? null;
    }
}
