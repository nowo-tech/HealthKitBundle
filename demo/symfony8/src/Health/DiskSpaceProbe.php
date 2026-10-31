<?php

declare(strict_types=1);

namespace App\Health;

use Nowo\HealthKitBundle\Attribute\AsHealthProbe;
use Nowo\HealthKitBundle\Probe\HealthProbeInterface;
use Nowo\HealthKitBundle\Probe\ProbeResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Host-defined probe: free disk space of the var/ directory. Non-critical, so a full disk degrades readiness
 * (HTTP 200, "degraded") instead of taking the instance out of the load balancer.
 */
#[AsHealthProbe(name: 'disk', groups: ['ready'], critical: false, timeout: 0.5)]
final readonly class DiskSpaceProbe implements HealthProbeInterface
{
    private const MIN_FREE_MB = 512;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var')]
        private string $path,
    ) {
    }

    public function check(float $timeout): ProbeResult
    {
        $free = disk_free_space($this->path);
        if (false === $free) {
            return ProbeResult::degraded('Free space unknown.');
        }
        $freeMb = (int) floor($free / 1048576);
        $data   = ['free_mb' => $freeMb, 'min_free_mb' => self::MIN_FREE_MB];

        return $freeMb < self::MIN_FREE_MB
            ? ProbeResult::error(sprintf('Only %d MB free.', $freeMb), $data)
            : ProbeResult::ok($data);
    }
}
