<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

use Nowo\HealthKitBundle\Runtime\WorkerRecycleState;

/**
 * Exports the worker recycle record (`runtime.worker_recycle`) to `/metrics`.
 *
 * - `health_kit_worker_recycle_enabled`: 1 (the provider is registered only when the guard is on);
 * - `health_kit_worker_recycle_persistent`: 1 when a `state_pool` records recycles, else 0;
 * - `health_kit_worker_recycles_total`: recycles recorded in the pool;
 * - `health_kit_worker_recycle_last_timestamp_seconds`: Unix time of the last recycle (only once one happened).
 */
final readonly class WorkerRecycleMetricsProvider implements MetricsProviderInterface
{
    /**
     * @param WorkerRecycleState $state recycle record
     */
    public function __construct(
        private WorkerRecycleState $state,
    ) {
    }

    /**
     * Builds the families.
     *
     * @return list<MetricFamily> recycle families
     */
    public function collect(): array
    {
        $snapshot = $this->state->snapshot();
        $families = [
            MetricFamily::gauge('health_kit_worker_recycle_enabled', 'Whether the FrankenPHP worker recycle guard is enabled', 1.0),
            MetricFamily::gauge('health_kit_worker_recycle_persistent', 'Whether worker recycles are recorded in a shared pool', $this->state->isPersistent() ? 1.0 : 0.0),
            MetricFamily::counter('health_kit_worker_recycles_total', 'FrankenPHP workers recycled after a lost critical connection', (float) $snapshot['recycles_total']),
        ];
        if ($snapshot['last_recycle_at'] !== null) {
            $families[] = MetricFamily::gauge('health_kit_worker_recycle_last_timestamp_seconds', 'Unix time of the last FrankenPHP worker recycle', (float) $snapshot['last_recycle_at']);
        }

        return $families;
    }
}
