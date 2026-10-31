<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Health;

use Nowo\HealthKitBundle\Probe\HealthStatus;

use function round;

/**
 * Aggregated result of the probes of one group.
 *
 * The overall status is the worst probe status: a failing critical probe makes it `error` (HTTP 503), a failing
 * non-critical probe or a degraded one makes it `degraded` (HTTP 200).
 */
final readonly class HealthReport
{
    /**
     * @param string $group group that was checked (`live` or `ready`)
     * @param HealthStatus $status overall outcome
     * @param list<ProbeReport> $probes per-probe outcomes, in registration order
     * @param float $durationMs total time spent, in milliseconds
     */
    public function __construct(
        public string $group,
        public HealthStatus $status,
        public array $probes,
        public float $durationMs,
    ) {
    }

    /**
     * Whether the report should answer HTTP 200 (ok or degraded).
     *
     * @return bool false only when the status is error
     */
    public function isHealthy(): bool
    {
        return $this->status !== HealthStatus::Error;
    }

    /**
     * Detailed JSON representation (probe names, durations, messages and data).
     *
     * @return array{status: string, duration_ms: float, checks: array<string, array<string, mixed>>}
     */
    public function toDetailedArray(): array
    {
        $checks = [];
        foreach ($this->probes as $probe) {
            $checks[$probe->name] = $probe->toArray();
        }

        return [
            'status'      => $this->status->value,
            'duration_ms' => round($this->durationMs, 3),
            'checks'      => $checks,
        ];
    }
}
