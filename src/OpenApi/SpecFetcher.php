<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\OpenApi;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Client as GuzzleClient;
use InterServer\Mcp\Core\Exception\SpecUnavailableException;

/**
 * Retrieves an OpenAPI document and reports a content fingerprint for it.
 *
 * Both fetch modes — `http(s)://` and a local path — return the same
 * {@see FetchedSpec}, so a deployment can serve the spec over HTTP (the normal
 * case: the spec belongs to the API, not to the MCP server) or read a checked-out
 * copy in a test, with no branch in the caller.
 *
 * The fingerprint is what makes the cache correct. The implementation this
 * replaced keyed its cache on `md5($specPath)` — the *path string* — and checked
 * freshness with `filemtime($cache) >= filemtime($spec)`. When the admin spec
 * moved, `filemtime()` on the now-missing file returned `false`, the comparison
 * held, and a stale cache served a phantom tool catalogue for months. Keying on
 * content makes that failure impossible, and a fetch that fails is an error here
 * rather than a silent cache hit.
 */
final class SpecFetcher
{
    private ClientInterface $http;

    public function __construct(?ClientInterface $http = null, private readonly int $timeout = 30)
    {
        $this->http = $http ?? new GuzzleClient(['timeout' => $this->timeout, 'http_errors' => false]);
    }

    public function fetch(string $source): FetchedSpec
    {
        return str_starts_with($source, 'http://') || str_starts_with($source, 'https://')
            ? $this->fetchHttp($source)
            : $this->fetchFile($source);
    }

    private function fetchHttp(string $url): FetchedSpec
    {
        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => $this->timeout,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/yaml, application/json;q=0.9, */*;q=0.5'],
            ]);
        } catch (\Throwable $e) {
            throw new SpecUnavailableException(\sprintf('Could not fetch OpenAPI spec from %s: %s', $url, $e->getMessage()), 0, $e);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            throw new SpecUnavailableException(\sprintf('OpenAPI spec at %s returned HTTP %d.', $url, $status));
        }

        $body = (string) $response->getBody();
        if ('' === trim($body)) {
            throw new SpecUnavailableException(\sprintf('OpenAPI spec at %s was empty.', $url));
        }

        // Prefer the origin's strong ETag when it offers one — it is cheaper for
        // the origin to compute than a re-hash here, and it already tracks exactly
        // the notion of "changed" the origin cares about. A weak validator (W/…)
        // is explicitly not a content identity, so it is ignored.
        $etag = $response->getHeaderLine('ETag');
        $fingerprint = ('' !== $etag && !str_starts_with($etag, 'W/'))
            ? 'etag:'.trim($etag, '"')
            : 'sha256:'.hash('sha256', $body);

        return new FetchedSpec($body, $fingerprint, $url);
    }

    private function fetchFile(string $path): FetchedSpec
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new SpecUnavailableException(\sprintf('OpenAPI spec file %s does not exist or is not readable.', $path));
        }

        $body = file_get_contents($path);
        if (false === $body || '' === trim($body)) {
            throw new SpecUnavailableException(\sprintf('OpenAPI spec file %s was empty or unreadable.', $path));
        }

        return new FetchedSpec($body, 'sha256:'.hash('sha256', $body), $path);
    }
}
