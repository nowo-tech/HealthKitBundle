<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

use Nowo\HealthKitBundle\Health\HealthChecker;

use function is_bool;
use function is_float;
use function is_int;
use function round;

/**
 * Exports every registered health probe as Prometheus gauges.
 *
 * - `health_probe_status{probe}`: 1 ok, 0.5 degraded, 0 error;
 * - `health_probe_duration_seconds{probe}`;
 * - `health_probe_data{probe,key}`: numeric and boolean probe data (e.g. Messenger `messages`, FrankenPHP `worker_num`).
 *
 * Every probe runs once per scrape, whatever its group.
 */
final readonly class ProbeMetricsProvider implements MetricsProviderInterface
{
    /**
     * @param HealthChecker $checker health checker
     */
    public function __construct(
        private HealthChecker $checker,
    ) {
    }

    /**
     * Runs the probes and builds the gauges.
     *
     * @return list<MetricFamily> status, duration and data families
     */
    public function collect(): array
    {
        $status   = [];
        $duration = [];
        $data     = [];
        foreach ($this->checker->checkAll() as $report) {
            $status[]   = new MetricSample($report->status->gaugeValue(), ['probe' => $report->name]);
            $duration[] = new MetricSample(round($report->durationMs / 1000.0, 6), ['probe' => $report->name]);
            foreach ($report->data as $key => $value) {
                if (is_int($value) || is_float($value) || is_bool($value)) {
                    $data[] = new MetricSample((float) $value, ['probe' => $report->name, 'key' => $key]);
                }
            }
        }

        return [
            new MetricFamily('health_probe_status', MetricType::Gauge, 'Health probe outcome (1 ok, 0.5 degraded, 0 error)', $status),
            new MetricFamily('health_probe_duration_seconds', MetricType::Gauge, 'Time the health probe took, in seconds', $duration),
            new MetricFamily('health_probe_data', MetricType::Gauge, 'Numeric facts reported by health probes (queue depth, worker number...)', $data),
        ];
    }
}
