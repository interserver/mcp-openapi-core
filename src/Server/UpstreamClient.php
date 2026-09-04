<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Server;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use InterServer\Mcp\Core\Auth\AuthContext;
use InterServer\Mcp\Core\OpenApi\ToolDefinition;

/**
 * Turns a tool call into an HTTP request against the REST API, and the response
 * back into something an MCP client can read.
 *
 * This is the whole of the tool path. The MCP server never touches a database:
 * it forwards the caller's own credential and lets the API make every
 * authorization decision it would make for a direct call. That property is what
 * lets both servers run with no database credentials at all.
 */
final class UpstreamClient
{
    /**
     * Raised from 30 s: VPS, mail and order endpoints legitimately exceed 30 s
     * when a hypervisor or mail backend is slow, and a timeout surfaces to the
     * model as a hard failure it will usually retry.
     */
    public const DEFAULT_TIMEOUT = 60;

    public const DEFAULT_CONNECT_TIMEOUT = 10;

    private ClientInterface $http;

    public function __construct(
        private readonly string $baseUrl,
        ?ClientInterface $http = null,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        private readonly int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
    ) {
        $this->http = $http ?? new GuzzleClient();
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function call(ToolDefinition $tool, array $arguments, AuthContext $auth): array
    {
        $path = $tool->path;
        foreach ($tool->pathParams as $param) {
            if (isset($arguments[$param])) {
                $path = str_replace('{'.$param.'}', rawurlencode((string) $arguments[$param]), $path);
            }
        }

        $query = [];
        foreach ($tool->queryParams as $param) {
            if (isset($arguments[$param])) {
                $query[$param] = $arguments[$param];
            }
        }

        $body = null;
        if ($tool->hasBody) {
            $body = array_diff_key($arguments, array_flip([...$tool->pathParams, ...$tool->queryParams]));
        }

        // Set per request, not as client defaults: an injected client (a shared
        // one, or a test's) would otherwise not get them, and `http_errors` in
        // particular changes an API error from a readable result into an
        // uncaught exception.
        $options = [
            'headers' => $this->headers($auth),
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            // Let a 4xx/5xx come back as a response so the error body can be
            // shown to the model, rather than as a Guzzle exception.
            'http_errors' => false,
        ];
        if ([] !== $query) {
            $options['query'] = $query;
        }
        if (null !== $body && [] !== $body) {
            $options['json'] = $body;
        }

        try {
            $response = $this->http->request($tool->httpMethod, $this->url($path), $options);
        } catch (\Throwable $e) {
            return ['error' => 'API request failed: '.$e->getMessage()];
        }

        return $this->decode(
            $response->getStatusCode(),
            (string) $response->getBody(),
        );
    }

    /**
     * Build the absolute URL by concatenation rather than Guzzle's `base_uri`.
     *
     * Guzzle merges `base_uri` per RFC 3986 reference resolution, under which a
     * request path starting with `/` is *absolute* and replaces the base's path
     * entirely. Every OpenAPI path here starts with `/`, so a `base_uri` of
     * `https://host/apiv2` would send `/admin/tickets` to `https://host/admin/tickets`
     * — the admin web UI, which redirects unauthenticated callers to a login page.
     * The symptom was a 56 KB HTML blob with nothing to indicate the API was never
     * reached.
     */
    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }

    /**
     * @return array<string, string>
     */
    private function headers(AuthContext $auth): array
    {
        return [
            'Accept' => 'application/json',
            ...$auth->upstreamHeaders(),
            // Short-circuits the API's IP-based session limiter. Those limits assume
            // a browser client; every MCP call arrives from this server's fixed
            // egress, so the customer's own IP is never the one calling.
            'X-API-APP' => '1',
            // Correlates a tool call with the API's own logs and with Cloudflare
            // ray IDs when ops has to reconstruct what a model actually did.
            'X-Request-Id' => \sprintf('mcp-%s-%s', bin2hex(random_bytes(4)), date('Hi')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(int $statusCode, string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);

        if ($statusCode >= 400) {
            $message = 'API returned HTTP '.$statusCode;
            if (\is_array($decoded)) {
                $detail = $decoded['error'] ?? $decoded['message'] ?? null;
                if (\is_string($detail)) {
                    $message .= ': '.$detail;
                }
            }

            return ['error' => $message, 'status' => $statusCode];
        }

        if (null === $decoded) {
            return ['raw' => $rawBody];
        }

        // `structuredContent` had to be a JSON object before MCP 2026-07-28, so a
        // top-level array was unemittable. ~89 endpoints across the two specs
        // return one. Both servers speak both eras, so the wrapper stays — and
        // OpenApiParser declares the wrapped shape in `outputSchema` to match.
        if (\is_array($decoded) && array_is_list($decoded)) {
            return ['items' => $decoded];
        }

        return \is_array($decoded) ? $decoded : ['value' => $decoded];
    }
}
