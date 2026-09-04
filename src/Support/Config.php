<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Support;

/**
 * Application configuration, read from the environment.
 *
 * {@see get()} coalesces an empty string to the default, not only null. MCPB and
 * several container runtimes substitute `""` for a blank optional setting, and
 * `??` does not catch that — a bug the origin repos fixed in the admin build and
 * never backported to the client, which is the kind of thing this package exists
 * to stop happening twice.
 */
final class Config
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private readonly array $values = [])
    {
    }

    public static function fromEnvironment(): self
    {
        $values = [];
        foreach ($_ENV + $_SERVER as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $values[$key] = (string) $value;
            }
        }

        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->values[$key] ?? getenv($key);

        if (false === $value || null === $value || '' === trim((string) $value)) {
            return $default;
        }

        return (string) $value;
    }

    /**
     * A setting with no usable default, whose absence must stop the deployment
     * rather than degrade it.
     */
    public function require(string $key): string
    {
        return $this->get($key)
            ?? throw new \RuntimeException(\sprintf('Required configuration "%s" is missing or empty.', $key));
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);

        if (null === $value) {
            return $default;
        }

        return \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);

        return null !== $value && is_numeric($value) ? (int) $value : $default;
    }
}
