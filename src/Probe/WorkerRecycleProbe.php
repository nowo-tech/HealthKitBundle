<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Probe;

use Nowo\HealthKitBundle\Runtime\WorkerRecycleState;
use Psr\Clock\ClockInterface;

/**
 * `runtime.worker_recycle` probe: reports worker recycles recorded by `runtime.worker_recycle`.
 *
 * Degraded while the last recycle is more recent than `recent_window` seconds (a critical connection was lost
 * recently; the recycled worker reconnected), ok otherwise. Data: `recycles_total`, `last_recycle_age_seconds` and
 * `last_reason` (an exception class name or a host-supplied, redacted reason).
 */
final readonly class WorkerRecycleProbe implements HealthProbeInterface
{
    /**
     * @param WorkerRecycleState $state recycle record
     * @param ClockInterface $clock clock
     * @param int $recentWindow seconds during which the last recycle degrades the probe (0: never)
     */
    public function __construct(
        private WorkerRecycleState $state,
        private ClockInterface $clock,
        private int $recentWindow = 300,
    ) {
    }

    /**
     * Reads the recycle record.
     *
     * @param float $timeout unused (one cache read)
     *
     * @return ProbeResult degraded after a recent recycle, ok otherwise
     */
    public function check(float $timeout): ProbeResult
    {
        $snapshot = $this->state->snapshot();
        $age      = $snapshot['last_recycle_at'] === null ? null : $this->clock->now()->getTimestamp() - $snapshot['last_recycle_at'];
        $data     = [
            'recycles_total'           => $snapshot['recycles_total'],
            'last_recycle_age_seconds' => $age,
            'last_reason'              => $snapshot['last_reason'],
        ];

        if ($age !== null && $age < $this->recentWindow) {
            return ProbeResult::degraded('A FrankenPHP worker was recycled after a lost critical connection.', $data);
        }

        return ProbeResult::ok($data);
    }
}
