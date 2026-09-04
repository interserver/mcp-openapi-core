<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Auth;

use InterServer\Mcp\Core\Profile\Profile;
use Mcp\Server\Transport\Http\OAuth\AuthorizationResult;
use Mcp\Server\Transport\Http\OAuth\AuthorizationTokenValidatorInterface;

/**
 * Validates a bearer token by introspection, then binds it to this surface.
 *
 * The audience check is the load-bearing part. With two resource servers on two
 * hosts sharing one authorization server, RFC 8707 audience binding is the only
 * thing that stops an admin-scoped token being replayed against the client server
 * or the reverse. Get it wrong and a client-tier token reaches ~445 admin tools.
 * It is checked here, before any scope logic, and the cross-surface rejection test
 * exists specifically to pin it.
 *
 * Note what this does *not* do: it never decides that a caller is an admin. The
 * `ima` tier comes from introspection's `ext.ima`, which the authorization server
 * returns only to the admin app's own client credential.
 */
final class IntrospectionTokenValidator implements AuthorizationTokenValidatorInterface
{
    public function __construct(
        private readonly IntrospectionClient $client,
        private readonly Profile $profile,
    ) {
    }

    public function validate(string $accessToken): AuthorizationResult
    {
        $result = $this->client->introspect($accessToken);

        if (!($result['active'] ?? false)) {
            return AuthorizationResult::unauthorized('invalid_token', 'Token is not active.');
        }

        if (!$this->audienceAccepted($result['aud'] ?? null)) {
            // Deliberately indistinguishable from an invalid token. Saying
            // "right token, wrong server" tells a caller holding an admin token
            // that an admin server exists and that this is not it.
            return AuthorizationResult::unauthorized('invalid_token', 'Token is not valid for this resource.');
        }

        $scopes = $this->scopes($result);

        if (null !== $this->profile->requiredScope
            && !ScopeToolFilter::hasScope($scopes, $this->profile->requiredScope)) {
            return AuthorizationResult::forbidden(
                'insufficient_scope',
                \sprintf('This server requires the "%s" scope.', $this->profile->requiredScope),
                [$this->profile->requiredScope],
            );
        }

        $ima = $result['ext']['ima'] ?? null;

        if ($this->profile->requiresAdminTier && 'admin' !== $ima) {
            // A client-tier credential presented to the admin server. The scope
            // check above can pass here — a token can carry the `admin` scope
            // without the account behind it being an admin — so the tier is
            // checked separately, against the account, not against the grant.
            return AuthorizationResult::forbidden(
                'insufficient_scope',
                'This server is restricted to administrator accounts.',
                null,
            );
        }

        return AuthorizationResult::allow(array_filter([
            'oauth.scopes' => $scopes,
            'oauth.subject' => $result['sub'] ?? null,
            'oauth.client_id' => $result['client_id'] ?? null,
            'oauth.audience' => \is_string($result['aud'] ?? null) ? $result['aud'] : null,
            'oauth.ima' => \is_string($ima) ? $ima : null,
        ], static fn ($v): bool => null !== $v && [] !== $v));
    }

    /**
     * Build the {@see AuthContext} the tool layer runs against.
     *
     * Separate from {@see validate()} because the SDK's middleware owns the
     * `AuthorizationResult` contract, while the tool loader and the upstream proxy
     * need the caller's token itself in order to forward it.
     */
    public function contextFor(string $accessToken): AuthContext
    {
        $result = $this->client->introspect($accessToken);

        if (!($result['active'] ?? false) || !$this->audienceAccepted($result['aud'] ?? null)) {
            return AuthContext::anonymous();
        }

        $ima = $result['ext']['ima'] ?? null;

        return new AuthContext(
            authenticated: true,
            scopes: $this->scopes($result),
            subject: isset($result['sub']) ? (string) $result['sub'] : null,
            ima: \is_string($ima) ? $ima : null,
            bearerToken: $accessToken,
            audience: \is_string($result['aud'] ?? null) ? $result['aud'] : null,
            clientId: isset($result['client_id']) ? (string) $result['client_id'] : null,
        );
    }

    /**
     * RFC 8707 permits `aud` to be a single string or an array of them.
     */
    private function audienceAccepted(mixed $aud): bool
    {
        if (\is_array($aud)) {
            foreach ($aud as $candidate) {
                if (\is_string($candidate) && $this->profile->acceptsAudience($candidate)) {
                    return true;
                }
            }

            return false;
        }

        return $this->profile->acceptsAudience(\is_string($aud) ? $aud : null);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return list<string>
     */
    private function scopes(array $result): array
    {
        $scope = $result['scope'] ?? '';

        if (\is_array($scope)) {
            return array_values(array_map(strval(...), $scope));
        }

        return preg_split('/\s+/', trim((string) $scope), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
    }
}
