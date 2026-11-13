<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use Nowo\HealthKitBundle\Metrics\MetricFamily;
use Nowo\HealthKitBundle\Metrics\MetricsCollectorInterface;

/**
 * Host replacement of the collector port.
 */
final class HostMetricsCollector implements MetricsCollectorInterface
{
    public function collect(): array
    {
        return [MetricFamily::gauge('host_collector_up', 'Host collector', 1)];
    }
}
