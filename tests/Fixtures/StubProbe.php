<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use Closure;
use Nowo\HealthKitBundle\Probe\HealthProbeInterface;
use Nowo\HealthKitBundle\Probe\ProbeResult;

/**
 * Probe returning a fixed result (or running a callback), recording the timeouts it received.
 */
final class StubProbe implements HealthProbeInterface
{
    /** @var list<float> */
    public array $timeouts = [];

    /**
     * @param Closure(float): ProbeResult|ProbeResult $result result or callback
     */
    public function __construct(private readonly ProbeResult|Closure $result)
    {
    }

    public function check(float $timeout): ProbeResult
    {
        $this->timeouts[] = $timeout;

        return $this->result instanceof Closure ? ($this->result)($timeout) : $this->result;
    }
}
