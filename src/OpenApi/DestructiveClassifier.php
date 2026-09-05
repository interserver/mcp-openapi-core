<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\OpenApi;

/**
 * Decides whether an operation is state-mutating in a way an AI client should
 * confirm with the user before invoking.
 *
 * Every rule beyond "DELETE is destructive" is **injected**, not hardcoded. In
 * the two servers this package replaced, the admin heuristics (`/admin/orders/`,
 * the `^admin(Cancel|Delete|Refund|…)` operationId regex) shipped in the client
 * build as well, where they could never match — dead logic that still had to be
 * read and maintained. Rules now come from each app's own configuration, so the
 * client build carries client rules and nothing else.
 */
final class DestructiveClassifier
{
    /**
     * Path fragments that make a POST/PUT/PATCH destructive.
     */
    public const DEFAULT_PATH_TERMS = [
        'cancel', 'delete', 'refund', 'purge', 'wipe', 'remove',
        'destroy', 'reinstall', 'reset_password', 'change_root_password',
        'change_password', 'mark_fraud', 'disable', 'suspend',
        'restore', 'change_ip', 'migration', 'ipmi_power', 'powerstrip',
        'null_routes', 'clean_login_logs', 'switch_port', 'switchport_config',
        'mass_email', 'buy_hd_space', 'buy_ip',
    ];

    /**
     * The subset of {@see DEFAULT_PATH_TERMS} that is destructive even on GET.
     *
     * These are real endpoints in this API that mutate on a GET — mail
     * `reset_password` rotates a password, `ipmi_power` cycles a machine. A model
     * that treats "GET is safe" as universal will call them speculatively.
     */
    public const DEFAULT_UNSAFE_GET_TERMS = [
        'reset_password', 'change_password', 'change_root_password',
        'reinstall', 'restore', 'ipmi_power', 'powerstrip',
    ];

    /**
     * @param list<string>  $pathPrefixes   path fragments destructive on any method (e.g. '/admin/orders/')
     * @param list<string>  $pathTerms      path fragments destructive on POST/PUT/PATCH
     * @param list<string>  $unsafeGetTerms subset of $pathTerms that is destructive on GET too
     * @param list<string>  $operationIdPatterns full PCRE patterns matched against the operationId
     */
    public function __construct(
        private readonly array $pathPrefixes = [],
        private readonly array $pathTerms = self::DEFAULT_PATH_TERMS,
        private readonly array $unsafeGetTerms = self::DEFAULT_UNSAFE_GET_TERMS,
        private readonly array $operationIdPatterns = [],
    ) {
    }

    /**
     * @param array<string, mixed> $config keys: pathPrefixes, pathTerms, unsafeGetTerms, operationIdPatterns
     */
    public static function fromArray(array $config): self
    {
        return new self(
            pathPrefixes: array_values($config['pathPrefixes'] ?? []),
            pathTerms: array_values($config['pathTerms'] ?? self::DEFAULT_PATH_TERMS),
            unsafeGetTerms: array_values($config['unsafeGetTerms'] ?? self::DEFAULT_UNSAFE_GET_TERMS),
            operationIdPatterns: array_values($config['operationIdPatterns'] ?? []),
        );
    }

    /**
     * A stable fingerprint of this classifier's rules.
     *
     * Folded into the tool-cache key so that changing a rule invalidates the cache
     * by itself. Without it, editing the rules leaves every cached annotation stale
     * until someone remembers to clear the directory by hand — and the symptom is a
     * tool still advertised `readOnlyHint: true` after being reclassified, which
     * reads as "the change did not work" rather than "the cache is old".
     *
     * Sorted before hashing so that reordering a list, which changes nothing about
     * what the classifier decides, does not needlessly discard a warm cache.
     */
    public function fingerprint(): string
    {
        $rules = [
            'pathPrefixes' => $this->pathPrefixes,
            'pathTerms' => $this->pathTerms,
            'unsafeGetTerms' => $this->unsafeGetTerms,
            'operationIdPatterns' => $this->operationIdPatterns,
        ];
        foreach ($rules as &$list) {
            sort($list);
        }
        unset($list);

        return hash('sha256', (string) json_encode($rules));
    }

    public function isDestructive(string $httpMethod, string $path, string $operationId = ''): bool
    {
        $method = strtoupper($httpMethod);

        if ('DELETE' === $method) {
            return true;
        }

        $lowerPath = strtolower($path);

        foreach ($this->pathPrefixes as $prefix) {
            if (str_contains($lowerPath, strtolower($prefix))) {
                return true;
            }
        }

        if (\in_array($method, ['GET', 'POST', 'PUT', 'PATCH'], true)) {
            $mutatingMethod = \in_array($method, ['POST', 'PUT', 'PATCH'], true);
            foreach ($this->pathTerms as $term) {
                if (!str_contains($lowerPath, $term)) {
                    continue;
                }
                if ($mutatingMethod) {
                    return true;
                }
                if (\in_array($term, $this->unsafeGetTerms, true)) {
                    return true;
                }
            }
        }

        if ('' !== $operationId) {
            foreach ($this->operationIdPatterns as $pattern) {
                if (1 === preg_match($pattern, $operationId)) {
                    return true;
                }
            }
        }

        return false;
    }
}
