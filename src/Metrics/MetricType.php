<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

/**
 * Prometheus metric family types supported by {@see PrometheusTextFormatter}.
 */
enum MetricType: string
{
    case Counter = 'counter';
    case Gauge   = 'gauge';
    case Untyped = 'untyped';
}
