<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Probe;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

use function max;
use function sprintf;

/**
 * Pings one Doctrine DBAL connection with the platform dummy select (`SELECT 1`).
 *
 * One instance is registered per configured connection (`doctrine.<name>`). On MySQL the select carries a
 * `MAX_EXECUTION_TIME(<timeout ms>)` optimizer hint (ignored as a comment by servers that do not support it). DBAL has
 * no portable statement or socket timeout: bound connect and read time in the connection options (`PDO::ATTR_TIMEOUT`,
 * `PDO::MYSQL_ATTR_READ_TIMEOUT`, PostgreSQL `connect_timeout` / `options='-c statement_timeout=…'`); the checker
 * still reports an overrun as an error.
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
     * @param float $timeout seconds allowed (MySQL statement hint; connect/read timeouts come from the connection options)
     *
     * @return ProbeResult ok when the query answers
     */
    public function check(float $timeout): ProbeResult
    {
        $platform = $this->connection->getDatabasePlatform();
        $sql      = $platform instanceof AbstractMySQLPlatform
            ? sprintf('SELECT /*+ MAX_EXECUTION_TIME(%d) */ 1', max(1, (int) ($timeout * 1000)))
            : $platform->getDummySelectSQL();

        $this->connection->executeQuery($sql)->fetchOne();

        return ProbeResult::ok();
    }
}
