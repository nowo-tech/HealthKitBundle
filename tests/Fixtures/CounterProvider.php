<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use Nowo\HealthKitBundle\Metrics\MetricFamily;
use Nowo\HealthKitBundle\Metrics\MetricsProviderInterface;

/**
 * Host-style metrics provider (like an ingest counter collector).
 */
final class CounterProvider implements MetricsProviderInterface
{
    public function collect(): iterable
    {
        yield MetricFamily::counter('app_ingest_ack_total', 'Accepted ingest requests', 42);
    }
}
