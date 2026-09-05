<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Server;

use InterServer\Mcp\Core\Auth\AuthContext;
use InterServer\Mcp\Core\Auth\ScopeToolFilter;
use InterServer\Mcp\Core\OpenApi\ToolCache;
use InterServer\Mcp\Core\Profile\Profile;
use Mcp\Capability\Registry\Loader\ExplicitElementLoader;
use Mcp\Capability\Registry\Loader\LoaderInterface;
use Mcp\Capability\RegistryInterface;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;

/**
 * Registers the profile's tool catalogue, scoped to the caller.
 *
 * Runs lazily: the SDK's registry invokes loaders on first read and retries on the
 * next read if one throws. Nothing is parsed, filtered or built on a request that
 * never reads the registry — a CORS preflight, for instance. (The origin repo's
 * client build paid a full OpenAPI parse on every preflight; that is the cost this
 * seam avoids.)
 *
 * Registration is delegated to the SDK's own {@see ExplicitElementLoader} rather
 * than calling `RegistryInterface::registerTool()` here. That class binds each
 * handler closure into `ReferenceHandler`'s scope, which is what makes the raw
 * argument bag reach {@see OpenApiToolHandler::execute()} instead of a
 * reflection-mapped parameter list. Doing that binding ourselves would work, but
 * it reaches into an undocumented internal of a pre-1.0 SDK; going through the
 * public loader gets the same behaviour from the supported path.
 */
final class OpenApiToolLoader implements LoaderInterface
{
    public function __construct(
        private readonly Profile $profile,
        private readonly ToolCache $cache,
        private readonly UpstreamClient $upstream,
        private readonly AuthContext $auth,
    ) {
    }

    public function load(RegistryInterface $registry): void
    {
        $entries = [];

        foreach ($this->tools() as $tool) {
            $entries[] = [
                'definition' => new Tool(
                    name: $tool->name,
                    title: $tool->title(),
                    inputSchema: $tool->inputSchema,
                    description: $tool->description,
                    annotations: new ToolAnnotations(
                        title: $tool->title(),
                        readOnlyHint: $tool->isReadOnly(),
                        destructiveHint: $tool->isDestructive(),
                        idempotentHint: (bool) ($tool->annotations['idempotentHint'] ?? false),
                        openWorldHint: (bool) ($tool->annotations['openWorldHint'] ?? true),
                    ),
                    outputSchema: $tool->outputSchema,
                ),
                'handler' => new OpenApiToolHandler($this->upstream, $tool, $this->auth),
            ];
        }

        (new ExplicitElementLoader($entries))->load($registry);
    }

    /**
     * The catalogue this caller may see: allowlisted, scope-filtered, sorted.
     *
     * @return list<\InterServer\Mcp\Core\OpenApi\ToolDefinition>
     */
    public function tools(): array
    {
        // The profile's classifier, not the parser's default. Every rule beyond
        // "DELETE is destructive" is per-application by design, and until this
        // argument existed the Profile carried a classifier that nothing read —
        // so an app's rules were silently discarded and the defaults applied.
        $tools = $this->cache->get(
            $this->profile->specSource,
            $this->profile->name,
            $this->profile->destructiveClassifier,
        );

        $allowlist = $this->profile->toolAllowlist;
        if (null !== $allowlist) {
            $allowed = array_flip($allowlist);
            $tools = array_values(array_filter($tools, static fn ($t): bool => isset($allowed[$t->name])));
        }

        if (null !== $this->profile->scopeMap) {
            $tools = ScopeToolFilter::filter($tools, $this->auth->scopes, $this->profile->scopeMap);
        }

        // MCP 2026-07-28 §3.3: tools/list SHOULD be deterministically ordered.
        // Spec/document order is not stable across spec edits, and an unstable
        // list costs both the client's list cache and the model's prompt cache.
        usort($tools, static fn ($a, $b): int => strcmp($a->name, $b->name));

        return $tools;
    }
}
