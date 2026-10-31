<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Probe;

/**
 * Outcome of one probe, and of a whole health report (the worst probe outcome wins).
 *
 * The string values are the public JSON vocabulary of `/health/live` and `/health/ready`.
 */
enum HealthStatus: string
{
    case Ok       = 'ok';
    case Degraded = 'degraded';
    case Error    = 'error';

    /**
     * Severity rank used to combine outcomes (higher is worse).
     *
     * @return int 0 for ok, 1 for degraded, 2 for error
     */
    public function severity(): int
    {
        return match ($this) {
            self::Ok       => 0,
            self::Degraded => 1,
            self::Error    => 2,
        };
    }

    /**
     * Returns the worse of the two outcomes.
     *
     * @param self $other outcome to compare with
     *
     * @return self the outcome with the highest severity
     */
    public function worst(self $other): self
    {
        return $other->severity() > $this->severity() ? $other : $this;
    }

    /**
     * Prometheus gauge value for this outcome (1 ok, 0.5 degraded, 0 error).
     *
     * @return float gauge value
     */
    public function gaugeValue(): float
    {
        return match ($this) {
            self::Ok       => 1.0,
            self::Degraded => 0.5,
            self::Error    => 0.0,
        };
    }
}
