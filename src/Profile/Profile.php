<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Profile;

use InterServer\Mcp\Core\Auth\ScopeMap;
use InterServer\Mcp\Core\OpenApi\DestructiveClassifier;

/**
 * One MCP surface: which spec it exposes, where it proxies to, and how it is gated.
 *
 * A profile is data. Everything that differs between the client, public and admin
 * surfaces lives in a profile row in each app's `config/profiles.php` — never in a
 * branch in the code. That is the mechanism by which the admin destructive rules
 * stop shipping in the client build.
 */
final class Profile
{
    /**
     * @param string             $name             stable identifier, also the tool-cache namespace
     * @param string             $specSource       URL or path of the OpenAPI document
     * @param string             $upstreamBaseUrl  REST API base a tool call proxies to
     * @param list<string>|null  $toolAllowlist    null exposes every operation in the spec
     * @param ScopeMap|null      $scopeMap         null disables scope filtering entirely
     * @param string|null        $requiredScope    scope every request must carry (admin surface)
     * @param bool               $requiresAdminTier caller's `ext.ima` must be `admin`
     * @param bool               $listRequiresAuth gate tools/list as well as tools/call
     */
    public function __construct(
        public readonly string $name,
        public readonly string $specSource,
        public readonly string $upstreamBaseUrl,
        public readonly string $serverName,
        public readonly string $serverVersion = '1.0.0',
        public readonly bool $requiresAuth = true,
        public readonly ?array $toolAllowlist = null,
        public readonly ?ScopeMap $scopeMap = null,
        public readonly ?string $requiredScope = null,
        public readonly bool $requiresAdminTier = false,
        public readonly bool $listRequiresAuth = false,
        public readonly DestructiveClassifier $destructiveClassifier = new DestructiveClassifier(),
        /**
         * RFC 8707 resource identifier for this surface — the exact URL a user
         * types. A token whose audience is not in this set is rejected, which is
         * what stops an admin-audience token being replayed at the client server.
         *
         * @var list<string>
         */
        public readonly array $resourceIdentifiers = [],
        public readonly string $authRealm = 'interserver',
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(string $name, array $row): self
    {
        return new self(
            name: $name,
            specSource: (string) $row['specSource'],
            upstreamBaseUrl: (string) $row['upstreamBaseUrl'],
            serverName: (string) ($row['serverName'] ?? $name),
            serverVersion: (string) ($row['serverVersion'] ?? '1.0.0'),
            requiresAuth: (bool) ($row['requiresAuth'] ?? true),
            toolAllowlist: isset($row['toolAllowlist']) ? array_values($row['toolAllowlist']) : null,
            scopeMap: $row['scopeMap'] ?? null,
            requiredScope: $row['requiredScope'] ?? null,
            requiresAdminTier: (bool) ($row['requiresAdminTier'] ?? false),
            listRequiresAuth: (bool) ($row['listRequiresAuth'] ?? false),
            destructiveClassifier: $row['destructiveClassifier'] ?? new DestructiveClassifier(),
            resourceIdentifiers: array_values($row['resourceIdentifiers'] ?? []),
            authRealm: (string) ($row['authRealm'] ?? 'interserver'),
        );
    }

    /**
     * Is $audience one of this surface's resource identifiers?
     *
     * An empty identifier set accepts anything and exists only so a local
     * development profile need not invent a URL. Production profiles must declare
     * theirs: with two resources on two hosts, this comparison is the control that
     * stops a client token reaching admin tools.
     */
    public function acceptsAudience(?string $audience): bool
    {
        if ([] === $this->resourceIdentifiers) {
            return true;
        }
        if (null === $audience || '' === $audience) {
            return false;
        }

        foreach ($this->resourceIdentifiers as $identifier) {
            if (hash_equals($identifier, $audience)) {
                return true;
            }
        }

        return false;
    }
}
