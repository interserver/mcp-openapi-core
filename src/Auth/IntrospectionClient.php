<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Auth;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * RFC 7662 token introspection against the authorization server.
 *
 * This is how both MCP servers learn anything about a token without holding a
 * database credential. It is also a credential oracle, so several of the
 * behaviours here are security properties rather than optimisations:
 *
 *  - **Negative results are cached** (briefly). Without it, a token-guessing loop
 *    reaches the authorization server's database once per guess; with it, the
 *    same loop is answered from local memory.
 *  - **Every failure looks identical.** An expired token, an unknown token and an
 *    introspection outage all return `{"active": false}`. Distinguishing them
 *    tells an attacker which guesses were once real.
 *  - **The token is never logged.** Only the first 8 hex characters of its
 *    SHA-256, which is enough to correlate two log lines and not enough to replay.
 *  - **The cache TTL never outlives the token.** `min(ttl, exp - now)`, so a
 *    token that expires in 20 s is not treated as live for 300.
 */
final class IntrospectionClient
{
    public const DEFAULT_TTL = 300;

    /**
     * Short enough that a legitimate token issued moments ago is usable quickly,
     * long enough to blunt a guessing loop.
     */
    public const NEGATIVE_TTL = 30;

    private const CACHE_PREFIX = 'mcp:introspect:';

    private ClientInterface $http;

    public function __construct(
        private readonly string $endpoint,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?CacheInterface $cache = null,
        ?ClientInterface $http = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $ttl = self::DEFAULT_TTL,
        private readonly int $timeout = 5,
    ) {
        $this->http = $http ?? new GuzzleClient(['timeout' => $this->timeout, 'http_errors' => false]);
    }

    /**
     * @return array<string, mixed> RFC 7662 introspection response; `active` is always present
     */
    public function introspect(string $token): array
    {
        if ('' === trim($token)) {
            return ['active' => false];
        }

        $key = self::CACHE_PREFIX.hash('sha256', $token);

        $cached = $this->cache?->get($key);
        if (\is_array($cached)) {
            return $cached;
        }

        $result = $this->request($token);
        $this->cache?->set($key, $result, $this->ttlFor($result));

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $token): array
    {
        try {
            $response = $this->http->request('POST', $this->endpoint, [
                'timeout' => $this->timeout,
                'http_errors' => false,
                // RFC 7662 §2.1: the introspecting client authenticates to the
                // endpoint. Each app has its own confidential client, which is
                // what makes the `ext.ima` gate enforceable and gives per-app
                // revocation.
                'auth' => [$this->clientId, $this->clientSecret],
                'headers' => ['Accept' => 'application/json'],
                'form_params' => ['token' => $token, 'token_type_hint' => 'access_token'],
            ]);
        } catch (\Throwable $e) {
            $this->logger?->warning('Token introspection failed', [
                'token_fp' => $this->fingerprint($token),
                'error' => $e->getMessage(),
            ]);

            // Fail closed. An unreachable authorization server means we cannot
            // establish that this caller is who they claim, and "cannot establish"
            // is indistinguishable from "is not" for authorization purposes.
            return ['active' => false];
        }

        if (200 !== $response->getStatusCode()) {
            $this->logger?->warning('Token introspection returned an unexpected status', [
                'token_fp' => $this->fingerprint($token),
                'status' => $response->getStatusCode(),
            ]);

            return ['active' => false];
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (!\is_array($decoded) || !($decoded['active'] ?? false)) {
            return ['active' => false];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function ttlFor(array $result): int
    {
        if (!($result['active'] ?? false)) {
            return self::NEGATIVE_TTL;
        }

        $ttl = $this->ttl;
        if (isset($result['exp']) && is_numeric($result['exp'])) {
            $remaining = (int) $result['exp'] - time();
            if ($remaining <= 0) {
                return 0;
            }
            $ttl = min($ttl, $remaining);
        }

        return max(0, $ttl);
    }

    private function fingerprint(string $token): string
    {
        return substr(hash('sha256', $token), 0, 8);
    }
}
