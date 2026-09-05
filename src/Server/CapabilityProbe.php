<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Server;

use Mcp\Server;
use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\InMemoryTransport;
use Mcp\Server\Transport\StatelessAwareTransportInterface;

/**
 * Asks a built server what it can do, by making the same call a client would.
 *
 * This exists for one reason. The server card and the live `server/discover`
 * result must never disagree, and in the implementation this package replaces they
 * did: the static card advertised `tools: {listChanged: false}` and nothing else,
 * while the SDK's own capability detection reported `logging`, `completions`,
 * `prompts`, `resources` (with `subscribe`) and `tools`. A client reading the card
 * and a client calling the server got two different answers about the same server.
 *
 * The fix is not to keep two lists in step. It is to have only one: the card is
 * rendered from the result of a real `server/discover` dispatched through the real
 * server, so the two cannot drift even in principle. The SDK does not expose the
 * capability set on {@see Server} — it is computed inside the builder — and reaching
 * into it by reflection would reintroduce exactly the coupling this avoids.
 *
 * `server/discover` belongs to the modern (SEP-2575) lifecycle, so the probe has to
 * reach the *stateless* dispatcher. Feeding the message through the handshake
 * protocol instead answers `-32600 A valid session id is REQUIRED for non-initialize
 * requests`, because that era wants an `initialize` first. {@see Server::run()} hands
 * a stateless-aware transport both dispatchers and lets it choose; this transport
 * simply keeps the stateless one and calls it directly.
 *
 * Runs entirely in memory: no socket, no HTTP, no session.
 */
final class CapabilityProbe
{
    private const VERSION = '2026-07-28';

    /**
     * The discovery result, as a client would receive it.
     *
     * @return array<string, mixed> the `result` member, or an empty array if the
     *                              server did not answer — which a caller must treat
     *                              as "cannot describe this server", never as "this
     *                              server has no capabilities"
     */
    public static function discover(Server $server): array
    {
        $transport = new StatelessProbeTransport();
        $server->run($transport);

        $protocol = $transport->stateless();
        if (null === $protocol) {
            return [];
        }

        $body = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'server-card-probe',
            'method' => 'server/discover',
            'params' => [
                '_meta' => [
                    // Both members are required by the modern revision; omitting
                    // either gets -32602 instead of a result.
                    'io.modelcontextprotocol/protocolVersion' => self::VERSION,
                    'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $result = $protocol->handle($body, [
            'MCP-Protocol-Version' => self::VERSION,
            // The transport normally supplies this from the real request, and the
            // dispatcher rejects a modern message without it (-32020).
            'Mcp-Method' => 'server/discover',
        ]);

        $decoded = json_decode($result->toJson(), true);

        return \is_array($decoded) && \is_array($decoded['result'] ?? null)
            ? $decoded['result']
            : [];
    }
}

/**
 * A transport that exists only to be handed the stateless dispatcher.
 *
 * {@see Server::run()} is the only place that hands it out, and it does so only to
 * a {@see StatelessAwareTransportInterface}. `listen()` returns immediately because
 * there is nothing to listen to — the probe calls the dispatcher itself once it has
 * it, rather than pushing frames through the handshake path.
 *
 * @internal
 */
final class StatelessProbeTransport extends InMemoryTransport implements StatelessAwareTransportInterface
{
    private ?StatelessProtocol $stateless = null;

    public function connectStateless(StatelessProtocol $protocol): void
    {
        $this->stateless = $protocol;
    }

    public function stateless(): ?StatelessProtocol
    {
        return $this->stateless;
    }

    public function listen(): mixed
    {
        return null;
    }
}
