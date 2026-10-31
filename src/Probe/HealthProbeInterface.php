<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Probe;

use Nowo\HealthKitBundle\Attribute\AsHealthProbe;

/**
 * A dependency check run by `/health/live` or `/health/ready` (and by the probe metrics provider).
 *
 * Implementations are services tagged `nowo_health_kit.probe` (use {@see AsHealthProbe}
 * or rely on autoconfiguration). They MUST bound every network call by `$timeout` seconds, keep no per-request state
 * between calls (FrankenPHP worker mode) and may throw: the checker turns an exception into an error result.
 */
interface HealthProbeInterface
{
    /**
     * Runs the check.
     *
     * @param float $timeout seconds this probe may spend; apply it to connect/read timeouts of the I/O it performs
     *
     * @return ProbeResult outcome with optional secret-free message and scalar data
     */
    public function check(float $timeout): ProbeResult;
}
