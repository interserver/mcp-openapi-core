<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Server;

use InterServer\Mcp\Core\Auth\AuthContext;
use InterServer\Mcp\Core\OpenApi\ToolDefinition;
use Mcp\Server\ClientGateway;
use Mcp\Server\Handler\ToolHandlerInterface;

/**
 * Executes one OpenAPI-derived tool.
 *
 * `ToolHandlerInterface` is the reason this is a class rather than a closure:
 * `execute()` receives the **raw argument bag**, whereas a closure registered
 * through `Builder::addTool()` has its arguments mapped onto named parameters by
 * reflection. Tool parameters here come from an OpenAPI document and can be named
 * anything at all, so name-based mapping is not an option.
 */
final class OpenApiToolHandler implements ToolHandlerInterface
{
    public function __construct(
        private readonly UpstreamClient $upstream,
        private readonly ToolDefinition $tool,
        private readonly AuthContext $auth,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function execute(array $arguments, ClientGateway $gateway): mixed
    {
        return $this->upstream->call($this->tool, $arguments, $this->auth);
    }
}
