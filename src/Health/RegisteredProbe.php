<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Health;

use Nowo\HealthKitBundle\Probe\HealthProbeInterface;

use function in_array;

/**
 * A probe service plus the settings it was registered with (built by the probe compiler pass).
 */
final readonly class RegisteredProbe
{
    /**
     * @param string $name unique probe name
     * @param HealthProbeInterface $probe probe service
     * @param list<string> $groups groups (`live`, `ready`) the probe belongs to
     * @param bool $critical whether a failure fails the report (HTTP 503) or only degrades it
     * @param float $timeout seconds the probe may spend
     */
    public function __construct(
        public string $name,
        public HealthProbeInterface $probe,
        public array $groups,
        public bool $critical,
        public float $timeout,
    ) {
    }

    /**
     * Whether the probe runs for the given group.
     *
     * @param string $group `live` or `ready`
     *
     * @return bool true when the probe belongs to the group
     */
    public function inGroup(string $group): bool
    {
        return in_array($group, $this->groups, true);
    }
}
