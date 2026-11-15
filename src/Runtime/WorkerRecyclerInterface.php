<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Runtime;

/**
 * Port: asks the current FrankenPHP worker to end after the response of the current request has been sent.
 *
 * Implemented by {@see WorkerRecycleListener} (`runtime.worker_recycle`). Host code that catches a broken connection
 * itself (and therefore never lets it reach `kernel.exception`) calls {@see requestRecycle()} so the worker restarts
 * with fresh connections. Outside FrankenPHP worker mode the request is dropped at the end of the request.
 */
interface WorkerRecyclerInterface
{
    /**
     * Marks the current worker for recycling once the current response has been sent.
     *
     * @param string $reason short, non-secret reason (e.g. an exception class name); logged and recorded
     */
    public function requestRecycle(string $reason): void;

    /**
     * Whether the current request has marked the worker for recycling.
     *
     * @return bool true once a trigger was seen during the current request
     */
    public function isRecyclePending(): bool;
}
