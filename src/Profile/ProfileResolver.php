<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Profile;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Picks the profile a request is for, from its URL path.
 *
 * A single-profile application (the admin server) does almost nothing here, but
 * it uses the same class as the multi-profile one so both apps stay structurally
 * identical — the failure mode this package exists to prevent is two codebases
 * that drift because they only *look* alike.
 */
final class ProfileResolver
{
    /**
     * @param array<string, string> $pathMap    URL path => profile name
     * @param string                $default    profile for `/` and anything unmatched
     */
    public function __construct(
        private readonly ProfileRegistry $registry,
        private readonly array $pathMap,
        private readonly string $default,
    ) {
    }

    public function resolve(ServerRequestInterface $request): Profile
    {
        return $this->registry->get($this->resolveName($request->getUri()->getPath()));
    }

    public function resolveName(string $path): string
    {
        $path = '/'.trim(parse_url($path, \PHP_URL_PATH) ?: '/', '/');

        // Longest match first, so `/mcp/client` beats a `/mcp` entry rather than
        // depending on the order the config happened to list them in.
        $candidates = $this->pathMap;
        uksort($candidates, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        foreach ($candidates as $prefix => $profileName) {
            $prefix = '/'.trim($prefix, '/');
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return $profileName;
            }
        }

        return $this->default;
    }

    /**
     * The URL path a profile is served at — needed to build its RFC 9728
     * protected-resource metadata URL, which must be keyed to the exact path the
     * client used.
     */
    public function pathFor(string $profileName): ?string
    {
        foreach ($this->pathMap as $prefix => $name) {
            if ($name === $profileName) {
                return '/'.trim($prefix, '/');
            }
        }

        return null;
    }
}
