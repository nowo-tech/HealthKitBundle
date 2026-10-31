<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Health;

use Closure;
use Nowo\HealthKitBundle\Health\ErrorSanitizer;
use Nowo\HealthKitBundle\Health\HealthChecker;
use Nowo\HealthKitBundle\Health\HealthReport;
use Nowo\HealthKitBundle\Health\ProbeReport;
use Nowo\HealthKitBundle\Health\RegisteredProbe;
use Nowo\HealthKitBundle\Probe\HealthStatus;
use Nowo\HealthKitBundle\Probe\ProbeResult;
use Nowo\HealthKitBundle\Tests\Fixtures\RecordingLogger;
use Nowo\HealthKitBundle\Tests\Fixtures\StubProbe;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

use const JSON_THROW_ON_ERROR;

#[CoversClass(HealthChecker::class)]
#[CoversClass(RegisteredProbe::class)]
#[CoversClass(ProbeReport::class)]
#[CoversClass(HealthReport::class)]
final class HealthCheckerTest extends TestCase
{
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-10-31 12:00:00');
    }

    /**
     * @param list<string> $groups
     */
    private function probe(string $name, ProbeResult|Closure $result, array $groups = ['ready'], bool $critical = true, float $timeout = 1.0): RegisteredProbe
    {
        return new RegisteredProbe($name, new StubProbe($result), $groups, $critical, $timeout);
    }

    #[Test]
    public function aggregatesTheWorstStatusOfTheGroup(): void
    {
        $checker = new HealthChecker([
            $this->probe('db', ProbeResult::ok()),
            $this->probe('queue', ProbeResult::degraded('busy', ['messages' => 12])),
            $this->probe('runtime', ProbeResult::error('ignored: other group'), ['live']),
        ], $this->clock);

        $report = $checker->check('ready');

        self::assertSame('ready', $report->group);
        self::assertSame(HealthStatus::Degraded, $report->status);
        self::assertTrue($report->isHealthy());
        self::assertSame(['db', 'queue'], array_map(static fn (ProbeReport $p): string => $p->name, $report->probes));
        self::assertSame([
            'status'      => 'degraded',
            'duration_ms' => 0.0,
            'checks'      => [
                'db'    => ['status' => 'ok', 'critical' => true, 'duration_ms' => 0.0],
                'queue' => ['status' => 'degraded', 'critical' => true, 'duration_ms' => 0.0, 'message' => 'busy', 'data' => ['messages' => 12]],
            ],
        ], $report->toDetailedArray());
    }

    #[Test]
    public function criticalFailureFailsAndNonCriticalOnlyDegrades(): void
    {
        $critical = new HealthChecker([$this->probe('db', ProbeResult::error('down'))], $this->clock);
        self::assertSame(HealthStatus::Error, $critical->check('ready')->status);
        self::assertFalse($critical->check('ready')->isHealthy());

        $optional = new HealthChecker([$this->probe('api', ProbeResult::error('down'), critical: false)], $this->clock);
        $report   = $optional->check('ready');
        self::assertSame(HealthStatus::Degraded, $report->status);
        self::assertSame(HealthStatus::Degraded, $report->probes[0]->status);
        self::assertFalse($report->probes[0]->critical);
    }

    #[Test]
    public function exceptionsBecomeSanitizedErrorsAndAreLoggedWithoutSecrets(): void
    {
        $logger  = $this->logger();
        $checker = new HealthChecker([
            $this->probe('db', static fn (): ProbeResult => throw new RuntimeException('Connection to mysql://app:s3cret@db:3306 refused')),
        ], $this->clock, 5.0, new ErrorSanitizer(), $logger);

        $report = $checker->check('ready');

        self::assertSame(HealthStatus::Error, $report->status);
        self::assertSame('RuntimeException: Connection to mysql://***@db:3306 refused', $report->probes[0]->message);
        self::assertCount(1, $logger->records);
        self::assertStringNotContainsString('s3cret', json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertSame('db', $logger->records[0]['context']['probe']);
    }

    #[Test]
    public function probesReceiveTheirTimeoutAndOverrunsAreErrors(): void
    {
        $stub    = new StubProbe(fn (float $t): ProbeResult => $this->sleepThen(0.5, ProbeResult::ok()));
        $checker = new HealthChecker([new RegisteredProbe('slow', $stub, ['ready'], true, 0.25)], $this->clock);

        $report = $checker->check('ready');

        self::assertSame([0.25], $stub->timeouts);
        self::assertSame(HealthStatus::Error, $report->status);
        self::assertSame('Timed out after 0.500s (timeout 0.250s).', $report->probes[0]->message);
        self::assertSame(500.0, $report->probes[0]->durationMs);
        self::assertSame(500.0, $report->durationMs);
    }

    #[Test]
    public function budgetExhaustionSkipsRemainingProbes(): void
    {
        $logger  = $this->logger();
        $checker = new HealthChecker([
            $this->probe('first', fn (): ProbeResult => $this->sleepThen(0.9, ProbeResult::ok())),
            $this->probe('second', ProbeResult::ok()),
            $this->probe('third', ProbeResult::ok(), critical: false),
        ], $this->clock, 0.5, new ErrorSanitizer(), $logger);

        $report = $checker->check('ready');

        self::assertSame(HealthStatus::Ok, $report->probes[0]->status);
        self::assertSame(HealthStatus::Error, $report->probes[1]->status);
        self::assertSame('Not run: the 0.500s health budget was exhausted.', $report->probes[1]->message);
        self::assertSame(HealthStatus::Degraded, $report->probes[2]->status);
        self::assertSame(HealthStatus::Error, $report->status);
        self::assertCount(2, $logger->records);
    }

    #[Test]
    public function checkAllRunsEveryGroup(): void
    {
        $checker = new HealthChecker([
            $this->probe('db', ProbeResult::ok()),
            $this->probe('runtime', ProbeResult::ok(), ['live']),
        ], $this->clock);

        self::assertSame(['db', 'runtime'], array_map(static fn (ProbeReport $p): string => $p->name, $checker->checkAll()));
    }

    #[Test]
    public function emptyGroupIsOk(): void
    {
        $report = (new HealthChecker([], $this->clock))->check('live');

        self::assertSame(HealthStatus::Ok, $report->status);
        self::assertSame([], $report->probes);
    }

    private function sleepThen(float $seconds, ProbeResult $result): ProbeResult
    {
        $this->clock->sleep($seconds);

        return $result;
    }

    private function logger(): RecordingLogger
    {
        return new RecordingLogger();
    }

    #[Test]
    public function checkAllRespectsTheBudget(): void
    {
        $checker = new HealthChecker([
            $this->probe('a', fn (): ProbeResult => $this->sleepThen(0.9, ProbeResult::ok())),
            $this->probe('b', ProbeResult::ok(), ['live']),
        ], $this->clock, 0.5);

        $reports = $checker->checkAll();

        self::assertSame(HealthStatus::Ok, $reports[0]->status);
        self::assertSame(HealthStatus::Error, $reports[1]->status);
    }

    #[Test]
    public function probeMessagesAndDataAreRedacted(): void
    {
        $checker = new HealthChecker([
            $this->probe('api', ProbeResult::degraded('slow, token=abc123 via https://u:p@api.example', ['api_key' => 'k-123', 'endpoint' => 'redis://:pw@cache:6379', 'depth' => 3])),
        ], $this->clock);

        $report = $checker->check('ready')->probes[0];

        self::assertSame('slow, token=*** via https://***@api.example', $report->message);
        self::assertSame(['api_key' => '***', 'endpoint' => 'redis://***@cache:6379', 'depth' => 3], $report->data);
    }
}
