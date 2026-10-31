<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * PSR-3 logger keeping records in memory for assertions.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
