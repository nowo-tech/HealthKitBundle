<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Health;

use Nowo\HealthKitBundle\Probe\HealthStatus;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

use function round;
use function sprintf;

/**
 * Runs the probes of a group within a time budget and aggregates their outcomes.
 *
 * - Each probe receives its own timeout and must bound its I/O with it; a probe that still overruns is reported as
 *   an error (`timed out`), whatever it returned.
 * - Once the group budget is spent, the remaining probes are not started and are reported as errors.
 * - An exception from a probe becomes an error result with a sanitized message, logged without secrets.
 * - A non-critical probe can only degrade the report.
 *
 * Stateless between calls (FrankenPHP worker safe): every call builds a fresh report.
 */
final readonly class HealthChecker
{
    private LoggerInterface $logger;

    /**
     * @param iterable<RegisteredProbe> $probes registered probes (compiler pass, registration order)
     * @param ClockInterface $clock clock used to measure durations
     * @param float $budget seconds a whole group may spend
     * @param ErrorSanitizer $sanitizer exception message sanitizer
     * @param LoggerInterface|null $logger logger for probe failures (no secrets logged)
     */
    public function __construct(
        private iterable $probes,
        private ClockInterface $clock,
        private float $budget = 5.0,
        private ErrorSanitizer $sanitizer = new ErrorSanitizer(),
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Runs every probe of the group.
     *
     * @param string $group `live` or `ready`
     *
     * @return HealthReport aggregated outcome
     */
    public function check(string $group): HealthReport
    {
        $start   = $this->now();
        $status  = HealthStatus::Ok;
        $reports = [];

        foreach ($this->probes as $probe) {
            if (!$probe->inGroup($group)) {
                continue;
            }
            $report = $this->now() - $start >= $this->budget
                ? $this->skipped($probe)
                : $this->run($probe);
            $reports[] = $report;
            $status    = $status->worst($report->status);
        }

        return new HealthReport($group, $status, $reports, ($this->now() - $start) * 1000.0);
    }

    /**
     * Runs every registered probe once, whatever its group (used by the probe metrics provider).
     *
     * @return list<ProbeReport> per-probe outcomes
     */
    public function checkAll(): array
    {
        $reports = [];
        foreach ($this->probes as $probe) {
            $reports[] = $this->run($probe);
        }

        return $reports;
    }

    /**
     * Runs one probe and applies the timeout and criticality rules.
     *
     * @param RegisteredProbe $probe probe to run
     *
     * @return ProbeReport outcome
     */
    private function run(RegisteredProbe $probe): ProbeReport
    {
        $start = $this->now();
        try {
            $result  = $probe->probe->check($probe->timeout);
            $status  = $result->status;
            $message = $result->message;
            $data    = $result->data;
        } catch (Throwable $e) {
            $status  = HealthStatus::Error;
            $message = $this->sanitizer->sanitize($e);
            $data    = [];
        }
        $elapsed = $this->now() - $start;

        if ($elapsed > $probe->timeout) {
            $status  = HealthStatus::Error;
            $message = sprintf('Timed out after %.3fs (timeout %.3fs).', $elapsed, $probe->timeout);
        }

        if ($status !== HealthStatus::Ok) {
            $this->logger->warning('Health probe "{probe}" reported {status}.', [
                'probe'  => $probe->name,
                'status' => $status->value,
                'reason' => $message === null ? null : $this->sanitizer->redact($message),
            ]);
        }

        if ($status === HealthStatus::Error && !$probe->critical) {
            $status = HealthStatus::Degraded;
        }

        return new ProbeReport($probe->name, $status, $probe->critical, round($elapsed * 1000.0, 3), $message, $data);
    }

    /**
     * Report for a probe that was not started because the group budget was spent.
     *
     * @param RegisteredProbe $probe probe that was skipped
     *
     * @return ProbeReport error (or degraded when not critical) outcome
     */
    private function skipped(RegisteredProbe $probe): ProbeReport
    {
        $this->logger->warning('Health probe "{probe}" skipped: budget exhausted.', ['probe' => $probe->name]);

        return new ProbeReport(
            $probe->name,
            $probe->critical ? HealthStatus::Error : HealthStatus::Degraded,
            $probe->critical,
            0.0,
            sprintf('Not run: the %.3fs health budget was exhausted.', $this->budget),
        );
    }

    /**
     * Current time in seconds (float, microsecond precision).
     *
     * @return float seconds since the epoch
     */
    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
