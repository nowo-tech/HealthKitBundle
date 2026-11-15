<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use RuntimeException;

/**
 * Stands for a host's "connection lost" exception (configured in `runtime.worker_recycle.exception_classes`).
 */
final class LostConnectionException extends RuntimeException
{
}
