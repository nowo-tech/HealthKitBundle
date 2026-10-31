<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

/**
 * Supplies metric families to the `/metrics` endpoint.
 *
 * Services implementing it are autoconfigured with the `nowo_health_kit.metrics_provider` tag. A provider is called
 * once per scrape; it must be cheap (one query or counter read per family, no N+1) and keep no per-request state.
 * An exception only drops that provider's families for the scrape (`health_kit_metrics_provider_up` becomes 0).
 */
interface MetricsProviderInterface
{
    /**
     * Collects the provider's metric families.
     *
     * @return iterable<MetricFamily> metric families
     */
    public function collect(): iterable;
}
