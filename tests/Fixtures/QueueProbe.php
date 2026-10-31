<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use Nowo\HealthKitBundle\Attribute\AsHealthProbe;
use Nowo\HealthKitBundle\Probe\HealthProbeInterface;
use Nowo\HealthKitBundle\Probe\ProbeResult;

/**
 * Host-style custom probe registered through #[AsHealthProbe] (non-critical, ready group).
 */
#[AsHealthProbe(name: 'payments_api', groups: ['ready'], critical: false, timeout: 1.5)]
final class QueueProbe implements HealthProbeInterface
{
    public function check(float $timeout): ProbeResult
    {
        return ProbeResult::error('Payments API unreachable.', ['latency_ms' => 12.5, 'region' => 'eu']);
    }
}
