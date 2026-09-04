<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Auth;

/**
 * Maps a URL section to the OAuth scope that gates it.
 *
 * The client and admin surfaces were two hand-written near-copies of the same
 * function in the origin repo, differing in exactly two decisions:
 *
 *  - how the section is cut out of the path (`/admin/vps/…` vs `/apiv2/vps/…`);
 *  - what an *unmapped* section means — the admin surface falls back to the
 *    blanket `admin` scope (fail closed: a new admin endpoint is gated until
 *    someone maps it), while the client surface falls back to no scope at all
 *    (`/login`, `/info`, `/ping` are reachable by any authenticated token).
 *
 * Both are parameters here, so the two surfaces share one implementation and the
 * per-app maps stay data in each app's `config/scope-map.php`.
 */
final class ScopeMap
{
    /**
     * @param array<string, string> $sections            section => module scope
     * @param string                $sectionPattern      PCRE with one capture group for the section
     * @param string|null           $unmappedSectionScope scope required by an unmapped section, or
     *                                                    null if unmapped sections need no scope
     */
    public function __construct(
        private readonly array $sections,
        private readonly string $sectionPattern,
        private readonly ?string $unmappedSectionScope,
        /**
         * Scope that grants everything and has no `:read` variant.
         */
        private readonly string $superScope = 'admin',
    ) {
    }

    /**
     * Admin surface: paths look like `/admin/{section}/…`; unmapped sections
     * (config, reports, server ordering) require the blanket `admin` scope.
     *
     * @param array<string, string> $sections
     */
    public static function forAdmin(array $sections, string $superScope = 'admin'): self
    {
        return new self($sections, '#^/admin/([a-z_]+)(?:/|$)#', $superScope, $superScope);
    }

    /**
     * Client surface: paths look like `/{section}/…`, optionally `/apiv2/`-prefixed;
     * unmapped sections are public to any authenticated token.
     *
     * @param array<string, string> $sections
     */
    public static function forClient(array $sections, string $superScope = 'admin'): self
    {
        return new self($sections, '#^/?(?:apiv2/)?([a-z_]+)(?:/|$)#', null, $superScope);
    }

    /**
     * The scope a call to $path with $method requires, or null if none.
     *
     * GET maps to `{module}:read`; every other method to the bare `{module}`.
     * The superscope has no `:read` variant — it already covers everything.
     */
    public function requiredScope(string $path, string $method): ?string
    {
        $section = '';
        if (1 === preg_match($this->sectionPattern, strtolower($path), $m)) {
            $section = $m[1];
        }

        $scope = $this->sections[$section] ?? $this->unmappedSectionScope;

        if (null === $scope || $this->superScope === $scope) {
            return $scope;
        }

        return 'GET' === strtoupper($method) ? $scope.':read' : $scope;
    }

    /**
     * @return array<string, string>
     */
    public function sections(): array
    {
        return $this->sections;
    }

    public function superScope(): string
    {
        return $this->superScope;
    }
}
