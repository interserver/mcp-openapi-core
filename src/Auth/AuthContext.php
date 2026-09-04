<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Auth;

/**
 * What the server knows about the caller, after validating whatever credential
 * they presented.
 *
 * Deliberately carries the *credential* as well as the identity: a tool call is
 * proxied upstream with the caller's own credential attached, so the API applies
 * the same ownership checks it would for a direct call. The MCP server holds no
 * standing authority of its own and cannot act for a user it did not authenticate.
 */
final class AuthContext
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly bool $authenticated = false,
        public readonly array $scopes = [],
        public readonly ?string $subject = null,
        /**
         * Account tier, from introspection's `ext.ima`. Populated only for the
         * admin app's credential — the client app must never receive it, or a
         * client-tier token could be mistaken for an admin one.
         */
        public readonly ?string $ima = null,
        public readonly ?string $bearerToken = null,
        public readonly ?string $apiKey = null,
        public readonly ?string $sessionId = null,
        public readonly ?string $audience = null,
        public readonly ?string $clientId = null,
    ) {
    }

    public static function anonymous(): self
    {
        return new self();
    }

    public function isAdmin(): bool
    {
        return 'admin' === $this->ima;
    }

    public function scopeString(): string
    {
        return implode(' ', $this->scopes);
    }

    public function hasScope(string $scope): bool
    {
        return ScopeToolFilter::hasScope($this->scopes, $scope);
    }

    /**
     * Credential headers to forward on the proxied upstream call.
     *
     * @return array<string, string>
     */
    public function upstreamHeaders(): array
    {
        $headers = [];
        if (null !== $this->bearerToken && '' !== $this->bearerToken) {
            $headers['Authorization'] = 'Bearer '.$this->bearerToken;
        }
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            $headers['X-API-KEY'] = $this->apiKey;
        }
        if (null !== $this->sessionId && '' !== $this->sessionId) {
            $headers['sessionid'] = $this->sessionId;
        }

        return $headers;
    }
}
