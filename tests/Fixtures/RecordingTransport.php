<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Fixtures;

use Mcp\Server\Stateless\StatelessProtocol;
use Mcp\Server\Transport\InMemoryTransport;
use Mcp\Server\Transport\StatelessAwareTransportInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Drives a server with raw JSON-RPC frames and keeps everything it answers.
 *
 * The SDK's own {@see InMemoryTransport} exists to *feed* a server, not to
 * observe one: `send()` discards the payload, and `listen()` never drains the
 * queue. Both gaps matter here, because the two eras deliver results differently:
 *
 *  - the **modern era** is stateless, so `Protocol::sendResponse()` writes
 *    straight to `send()`;
 *  - the **handshake era** has a session, so the response is queued into it and
 *    the transport is expected to pull it with `getOutgoingMessages()` — which is
 *    exactly what `StreamableHttpTransport` does when it builds its JSON body.
 *
 * Capturing both is what lets a test replay a real `initialize` → `tools/list` →
 * `tools/call` sequence with no socket and no HTTP.
 *
 * The session deliberately survives the whole frame list rather than being torn
 * down per message, so a sequence behaves like one client connection.
 *
 * Era routing is **body-primary**, matching the specification and what
 * `StreamableHttpTransport` does: a frame whose `params._meta` names a modern
 * protocol version goes to the stateless dispatcher, everything else to the
 * handshake one. That is the decision this fixture has to reproduce faithfully,
 * because getting it wrong would make the dual-era tests pass for the wrong reason.
 */
final class RecordingTransport extends InMemoryTransport implements StatelessAwareTransportInterface
{
    private const VERSION_META = 'io.modelcontextprotocol/protocolVersion';

    private ?StatelessProtocol $stateless = null;

    /** @var list<string> */
    public array $sent = [];

    /** @var list<string> */
    private array $frames;

    /**
     * @param list<string> $messages
     */
    public function __construct(array $messages = [], ?\Psr\Log\LoggerInterface $logger = null)
    {
        parent::__construct($messages, $logger);
        $this->frames = $messages;
    }

    public function connectStateless(StatelessProtocol $protocol): void
    {
        $this->stateless = $protocol;
    }

    public function send(string $data, array $context): void
    {
        $this->sent[] = $data;

        if (isset($context['session_id']) && $context['session_id'] instanceof Uuid) {
            $this->sessionId = $context['session_id'];
        }
    }

    public function listen(): mixed
    {
        foreach ($this->frames as $frame) {
            if (null !== $this->stateless && self::isModern($frame)) {
                $result = $this->stateless->handle($frame, self::modernHeaders($frame));
                if (!$result->isEmpty()) {
                    $this->sent[] = $result->toJson();
                }

                continue;
            }

            $this->handleMessage($frame, $this->sessionId);
            $this->drain();
        }

        if (null !== $this->sessionId) {
            $this->handleSessionEnd($this->sessionId);
        }
        $this->sessionId = null;

        return null;
    }

    private static function isModern(string $frame): bool
    {
        $decoded = json_decode($frame, true);

        return \is_array($decoded) && isset($decoded['params']['_meta'][self::VERSION_META]);
    }

    /**
     * The headers a conformant modern client sends alongside the body.
     *
     * `MCP-Protocol-Version` mirrors `_meta` and is cross-checked — a
     * disagreement is `-32020` before either dispatcher sees the request — and
     * `Mcp-Method` is required on every request, `Mcp-Name` on the three methods
     * that name an element. Synthesising them from the frame keeps a test frame a
     * single source of truth while still exercising the real header path.
     *
     * @return array<string, string>
     */
    private static function modernHeaders(string $frame): array
    {
        $decoded = json_decode($frame, true);
        \assert(\is_array($decoded));

        $headers = [
            'MCP-Protocol-Version' => (string) $decoded['params']['_meta'][self::VERSION_META],
            'Mcp-Method' => (string) ($decoded['method'] ?? ''),
        ];

        if (\in_array($decoded['method'] ?? '', ['tools/call', 'prompts/get', 'resources/read'], true)
            && isset($decoded['params']['name'])) {
            $headers['Mcp-Name'] = (string) $decoded['params']['name'];
        }

        return $headers;
    }

    /**
     * Pull anything the protocol queued into the session onto {@see $sent}.
     */
    private function drain(): void
    {
        foreach ($this->getOutgoingMessages($this->sessionId) as $queued) {
            if (isset($queued['message']) && \is_string($queued['message'])) {
                $this->sent[] = $queued['message'];
            }
        }
    }

    /**
     * Decoded responses, in the order the server produced them.
     *
     * @return list<array<string, mixed>>
     */
    public function responses(): array
    {
        $out = [];
        foreach ($this->sent as $frame) {
            $decoded = json_decode($frame, true);
            if (\is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    /**
     * The response to one JSON-RPC id, or null if the server produced none.
     *
     * @return array<string, mixed>|null
     */
    public function responseTo(int|string $id): ?array
    {
        foreach ($this->responses() as $response) {
            if (($response['id'] ?? null) === $id) {
                return $response;
            }
        }

        return null;
    }
}
