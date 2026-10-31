<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Metrics;

use Nowo\HealthKitBundle\Http\TokenProviderInterface;

/**
 * Runtime source of the `/metrics` bearer token (`nowo_health_kit.metrics.token_provider`).
 *
 * Use it instead of `metrics.token` when the token is editable at runtime; it is resolved on every scrape.
 */
interface MetricsTokenProviderInterface extends TokenProviderInterface
{
}
