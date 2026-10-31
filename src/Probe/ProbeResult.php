<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Probe;

/**
 * Result returned by a {@see HealthProbeInterface}.
 *
 * `message` and `data` are only shown to callers allowed to see details (allowlisted IP or token); keep them free of
 * secrets (no DSN, password or token). `data` holds small scalar facts such as a queue depth or a runtime mode.
 */
final readonly class ProbeResult
{
    /**
     * @param HealthStatus $status probe outcome
     * @param string|null $message short, secret-free explanation (details only)
     * @param array<string, bool|float|int|string|null> $data small scalar facts (details only; numeric ones are exported as metrics)
     */
    public function __construct(
        public HealthStatus $status,
        public ?string $message = null,
        public array $data = [],
    ) {
    }

    /**
     * Successful result.
     *
     * @param array<string, bool|float|int|string|null> $data small scalar facts
     * @param string|null $message optional explanation
     *
     * @return self ok result
     */
    public static function ok(array $data = [], ?string $message = null): self
    {
        return new self(HealthStatus::Ok, $message, $data);
    }

    /**
     * Degraded result (the dependency works but needs attention, e.g. a queue above its warning threshold).
     *
     * @param string $message secret-free explanation
     * @param array<string, bool|float|int|string|null> $data small scalar facts
     *
     * @return self degraded result
     */
    public static function degraded(string $message, array $data = []): self
    {
        return new self(HealthStatus::Degraded, $message, $data);
    }

    /**
     * Failed result.
     *
     * @param string $message secret-free explanation
     * @param array<string, bool|float|int|string|null> $data small scalar facts
     *
     * @return self error result
     */
    public static function error(string $message, array $data = []): self
    {
        return new self(HealthStatus::Error, $message, $data);
    }
}
