<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Probe;

use Nowo\HealthKitBundle\Runtime\FrankenPhpRuntime;

/**
 * Exposes the FrankenPHP / Symfony Runtime mode (classic or worker, worker number, kernel reset) as probe data.
 *
 * Always ok unless `require_worker` is enabled and the process is not running in worker mode.
 */
final readonly class FrankenPhpProbe implements HealthProbeInterface
{
    /**
     * @param FrankenPhpRuntime $runtime runtime signal reader
     * @param bool $requireWorker fail when the current request is not served by a FrankenPHP worker
     */
    public function __construct(
        private FrankenPhpRuntime $runtime,
        private bool $requireWorker = false,
    ) {
    }

    /**
     * Reads the runtime signals.
     *
     * @param float $timeout unused (no I/O)
     *
     * @return ProbeResult ok with the runtime snapshot as data
     */
    public function check(float $timeout): ProbeResult
    {
        $snapshot = $this->runtime->snapshot();
        if ($this->requireWorker && !$snapshot['frankenphp_worker']) {
            return ProbeResult::error('Not running in FrankenPHP worker mode.', $snapshot);
        }

        return ProbeResult::ok($snapshot);
    }
}
