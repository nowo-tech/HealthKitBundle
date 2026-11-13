<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Health;

/**
 * Port: runs the registered health probes and aggregates their outcomes into bundle value objects.
 *
 * The default implementation is {@see HealthChecker}; override the alias of this interface to replace it.
 */
interface HealthCheckerInterface
{
    /**
     * Runs every probe of the group.
     *
     * @param string $group `live` or `ready`
     *
     * @return HealthReport aggregated outcome
     */
    public function check(string $group): HealthReport;

    /**
     * Runs every registered probe once, whatever its group.
     *
     * @return list<ProbeReport> per-probe outcomes
     */
    public function checkAll(): array;
}
