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
    /** @var string constant message: the reason is logged once by the listener, never carried by the exception (Symfony re-logs it) */
    public const MESSAGE = 'The FrankenPHP worker is recycled to get fresh connections (not an application error).';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
