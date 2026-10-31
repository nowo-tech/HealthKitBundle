<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use Nowo\HealthKitBundle\Probe\HealthProbeInterface;
use Nowo\HealthKitBundle\Probe\ProbeResult;

/**
 * Custom probe without attribute: autoconfigured by interface, named "auto_named".
 */
final class AutoNamedProbe implements HealthProbeInterface
{
    public function check(float $timeout): ProbeResult
    {
        return ProbeResult::ok(['enabled' => true]);
    }
}
