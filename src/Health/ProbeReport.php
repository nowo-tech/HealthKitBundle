<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Health;

use Nowo\HealthKitBundle\Probe\HealthStatus;

/**
 * Outcome of one probe run inside a {@see HealthReport}.
 */
final readonly class ProbeReport
{
    /**
     * @param string $name probe name
     * @param HealthStatus $status probe outcome (after timeout and criticality rules)
     * @param bool $critical whether the probe is critical
     * @param float $durationMs time spent, in milliseconds
     * @param string|null $message secret-free explanation (details only)
     * @param array<string, bool|float|int|string|null> $data small scalar facts (details only)
     */
    public function __construct(
        public string $name,
        public HealthStatus $status,
        public bool $critical,
        public float $durationMs,
        public ?string $message = null,
        public array $data = [],
    ) {
    }

    /**
     * Detailed JSON representation.
     *
     * @return array{status: string, critical: bool, duration_ms: float, message?: string, data?: array<string, bool|float|int|string|null>}
     */
    public function toArray(): array
    {
        $out = [
            'status'      => $this->status->value,
            'critical'    => $this->critical,
            'duration_ms' => $this->durationMs,
        ];
        if ($this->message !== null) {
            $out['message'] = $this->message;
        }
        if ($this->data !== []) {
            $out['data'] = $this->data;
        }

        return $out;
    }
}
