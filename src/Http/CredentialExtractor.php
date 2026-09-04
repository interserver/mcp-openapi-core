<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Pulls the caller's credential out of a request.
 *
 * Three accepted forms, in priority order: an OAuth bearer token, an
 * `X-API-KEY`, a `sessionid`. The first is the only one Claude uses; the other
 * two exist because they already work for the REST API and integrators use them.
 *
 * A note that costs an afternoon if missed: under PHP-FPM the `Authorization`
 * header does not reach PHP unless the vhost sets `CGIPassAuth On` (and, on some
 * stacks, re-exports it with `SetEnvIfNoCase`). {@see bearerToken()} therefore
 * also looks at the CGI-mangled `REDIRECT_HTTP_AUTHORIZATION`, but that is a
 * fallback, not a substitute for configuring the vhost.
 */
final class CredentialExtractor
{
    public static function bearerToken(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');

        if ('' === $header) {
            $server = $request->getServerParams();
            $header = $server['HTTP_AUTHORIZATION']
                ?? $server['REDIRECT_HTTP_AUTHORIZATION']
                ?? '';
        }

        if (1 === preg_match('/^Bearer\s+(.+)$/i', trim((string) $header), $m)) {
            return trim($m[1]);
        }

        return null;
    }

    public static function apiKey(ServerRequestInterface $request): ?string
    {
        $key = trim($request->getHeaderLine('X-API-KEY'));

        return '' !== $key ? $key : null;
    }

    public static function sessionId(ServerRequestInterface $request): ?string
    {
        $id = trim($request->getHeaderLine('sessionid'));

        return '' !== $id ? $id : null;
    }

    public static function hasAnyCredential(ServerRequestInterface $request): bool
    {
        return null !== self::bearerToken($request)
            || null !== self::apiKey($request)
            || null !== self::sessionId($request);
    }
}
