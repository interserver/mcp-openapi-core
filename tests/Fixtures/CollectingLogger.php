<?php

declare(strict_types=1);

namespace InterServer\Mcp\Core\Tests\Fixtures;

use Psr\Log\AbstractLogger;

/**
 * Collects log records so tests can assert on what *is* and — more importantly
 * for a credential path — what is *not* written.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Every record flattened into one searchable string, context included.
     */
    public function render(): string
    {
        return implode("\n", array_map(
            static fn (array $r): string => $r['message'].' '.json_encode($r['context']),
            $this->records,
        ));
    }
}
