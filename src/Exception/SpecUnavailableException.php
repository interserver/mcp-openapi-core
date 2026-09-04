<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Exception;

/**
 * The OpenAPI document could not be retrieved.
 *
 * Deliberately fatal rather than degrading to an empty tool list: a server that
 * answers `tools/list` with `[]` looks healthy to a client and is indistinguishable
 * from one that legitimately exposes nothing.
 */
final class SpecUnavailableException extends \RuntimeException
{
}
