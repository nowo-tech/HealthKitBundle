<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

/**
 * Port: aggregates the metric families of every metrics provider for one scrape.
 *
 * The default implementation is {@see MetricsCollector}; override the alias of this interface to replace it.
 */
interface MetricsCollectorInterface
{
    /**
     * Collects every family.
     *
     * @return list<MetricFamily> merged families, followed by the provider status family
     */
    public function collect(): array;
}
