<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Runtime;

use RuntimeException;

/**
 * Thrown from `kernel.terminate`, after the response has been sent, to end the FrankenPHP worker script.
 *
 * Symfony's `FrankenPhpWorkerRunner` rethrows what escapes `terminate()` once `frankenphp_handle_request()` returns,
 * which ends the worker script; FrankenPHP then starts a fresh worker. See {@see WorkerRecycleListener}.
 */
final class WorkerRecycleException extends RuntimeException
{
}
