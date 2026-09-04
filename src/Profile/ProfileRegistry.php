<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Profile;

use InterServer\Mcp\Core\Exception\UnknownProfileException;

/**
 * The profiles one application serves, keyed by name.
 */
final class ProfileRegistry
{
    /** @var array<string, Profile> */
    private array $profiles = [];

    /**
     * @param iterable<Profile> $profiles
     */
    public function __construct(iterable $profiles = [])
    {
        foreach ($profiles as $profile) {
            $this->profiles[$profile->name] = $profile;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $rows
     */
    public static function fromArray(array $rows): self
    {
        $profiles = [];
        foreach ($rows as $name => $row) {
            $profiles[] = Profile::fromArray((string) $name, $row);
        }

        return new self($profiles);
    }

    public function get(string $name): Profile
    {
        return $this->profiles[$name]
            ?? throw new UnknownProfileException(\sprintf('No MCP profile named "%s". Known: %s.', $name, implode(', ', $this->names())));
    }

    public function has(string $name): bool
    {
        return isset($this->profiles[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->profiles);
    }

    /**
     * @return array<string, Profile>
     */
    public function all(): array
    {
        return $this->profiles;
    }
}
