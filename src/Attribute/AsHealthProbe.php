<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Attribute;

use Attribute;
use Nowo\HealthKitBundle\Probe\HealthProbeInterface;

/**
 * Registers a {@see HealthProbeInterface} service with its probe settings.
 *
 * Example: `#[AsHealthProbe(name: 'payments_api', groups: ['ready'], critical: false, timeout: 1.5)]`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsHealthProbe
{
    /**
     * @param string $name unique probe name shown in details and metrics (`[a-z0-9_.-]+`)
     * @param list<string> $groups endpoints running the probe: `live` and/or `ready`
     * @param bool $critical when false, a failure only degrades the report (HTTP 200) instead of failing it (HTTP 503)
     * @param float|null $timeout seconds the probe may spend; null uses `nowo_health_kit.default_timeout`
     */
    public function __construct(
        public string $name,
        public array $groups = ['ready'],
        public bool $critical = true,
        public ?float $timeout = null,
    ) {
    }
}
