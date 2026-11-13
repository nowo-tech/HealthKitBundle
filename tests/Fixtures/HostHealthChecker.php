<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use Nowo\HealthKitBundle\Health\HealthCheckerInterface;
use Nowo\HealthKitBundle\Health\HealthReport;
use Nowo\HealthKitBundle\Health\ProbeReport;
use Nowo\HealthKitBundle\Probe\HealthStatus;

/**
 * Host replacement of the checker port: reports a degraded host-owned probe.
 */
final class HostHealthChecker implements HealthCheckerInterface
{
    public function check(string $group): HealthReport
    {
        return new HealthReport($group, HealthStatus::Degraded, $this->checkAll(), 1.0);
    }

    public function checkAll(): array
    {
        return [new ProbeReport('host_owned', HealthStatus::Degraded, false, 1.0, 'host checker')];
    }
}
