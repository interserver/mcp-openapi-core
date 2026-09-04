<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Auth;

use InterServer\Mcp\Core\OpenApi\ToolDefinition;

/**
 * Narrows a tool catalogue to what a token's scopes may actually invoke.
 *
 * MCP 2026-07-28 allows the tool set to vary by presented authorization (it may
 * not vary per *connection*), which is exactly this. A filtered list is therefore
 * `cacheScope: "private"` — see {@see \InterServer\Mcp\Core\Server\ServerFactory}.
 *
 * Pure functions over `(path, httpMethod, grantedScopes)`; no session, no request,
 * no database. That is what makes the cross-surface rejection test cheap to write.
 */
final class ScopeToolFilter
{
    /**
     * Does $grantedScopes satisfy $requiredScope?
     *
     *  - the superscope (`admin`) grants everything;
     *  - `{module}` grants both `{module}` and `{module}:read`;
     *  - `{module}:read` grants only the read variant;
     *  - an empty requirement is satisfied by anything, including no token.
     *
     * @param string|iterable<string> $grantedScopes space-separated string or list
     */
    public static function hasScope(string|iterable $grantedScopes, ?string $requiredScope, string $superScope = 'admin'): bool
    {
        if (null === $requiredScope || '' === $requiredScope) {
            return true;
        }

        $granted = self::normalize($grantedScopes);

        if (\in_array($superScope, $granted, true)) {
            return true;
        }
        if (\in_array($requiredScope, $granted, true)) {
            return true;
        }

        // A write scope subsumes its own read variant. The reverse never holds.
        if (str_ends_with($requiredScope, ':read')) {
            return \in_array(substr($requiredScope, 0, -\strlen(':read')), $granted, true);
        }

        return false;
    }

    /**
     * @param list<ToolDefinition>    $tools
     * @param string|iterable<string> $grantedScopes
     *
     * @return list<ToolDefinition>
     */
    public static function filter(array $tools, string|iterable $grantedScopes, ScopeMap $map): array
    {
        $granted = self::normalize($grantedScopes);
        $superScope = $map->superScope();

        return array_values(array_filter(
            $tools,
            static fn (ToolDefinition $tool): bool => self::hasScope(
                $granted,
                $map->requiredScope($tool->path, $tool->httpMethod),
                $superScope,
            ),
        ));
    }

    /**
     * The union of scopes a caller would need to see every tool in $tools.
     *
     * Used to build the `scope=` parameter of an insufficient-scope `403`. Claude
     * caches that value per user per server for ~15 minutes and does not reliably
     * carry earlier step-up scopes forward, so the challenge must always name the
     * whole set rather than just the scope the current call happened to miss.
     *
     * @param list<ToolDefinition> $tools
     *
     * @return list<string>
     */
    public static function requiredScopeUnion(array $tools, ScopeMap $map): array
    {
        $scopes = [];
        foreach ($tools as $tool) {
            $required = $map->requiredScope($tool->path, $tool->httpMethod);
            if (null !== $required && '' !== $required) {
                $scopes[$required] = true;
            }
        }

        $names = array_keys($scopes);
        sort($names);

        return $names;
    }

    /**
     * @param string|iterable<string> $scopes
     *
     * @return list<string>
     */
    private static function normalize(string|iterable $scopes): array
    {
        if (\is_string($scopes)) {
            return preg_split('/\s+/', trim($scopes), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $out = [];
        foreach ($scopes as $scope) {
            $scope = trim((string) $scope);
            if ('' !== $scope) {
                $out[] = $scope;
            }
        }

        return $out;
    }
}
