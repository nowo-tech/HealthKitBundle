<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Probe;

use Doctrine\DBAL\Connection;

/**
 * Pings one Doctrine DBAL connection with the platform dummy select (`SELECT 1`).
 *
 * One instance is registered per configured connection (`doctrine.<name>`). DBAL has no portable per-query timeout:
 * bound the connect time in the connection DSN/options (`connect_timeout`, `PDO::ATTR_TIMEOUT`); the checker still
 * reports the probe as failed when it overruns its timeout.
 */
final readonly class DoctrineConnectionProbe implements HealthProbeInterface
{
    /**
     * @param Connection $connection DBAL connection to ping
     */
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Runs one dummy select on the connection.
     *
     * @param float $timeout seconds allowed (enforced by the checker; configure the DSN connect timeout)
     *
     * @return ProbeResult ok when the query answers
     */
    public function check(float $timeout): ProbeResult
    {
        $this->connection->executeQuery($this->connection->getDatabasePlatform()->getDummySelectSQL())->fetchOne();

        return ProbeResult::ok();
    }
}
