<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Runtime;

use Nowo\HealthKitBundle\Health\ErrorSanitizer;
use Nowo\HealthKitBundle\Runtime\WorkerRecycleException;
use Nowo\HealthKitBundle\Runtime\WorkerRecycleListener;
use Nowo\HealthKitBundle\Runtime\WorkerRecycleState;
use Nowo\HealthKitBundle\Tests\Fixtures\LostConnectionException;
use Nowo\HealthKitBundle\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Throwable;

use function mb_strlen;
use function str_repeat;

#[CoversClass(WorkerRecycleListener::class)]
#[CoversClass(WorkerRecycleException::class)]
final class WorkerRecycleListenerTest extends TestCase
{
    private ArrayAdapter $pool;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->pool   = new ArrayAdapter();
        $this->logger = new RecordingLogger();
    }

    private function listener(string $level = LogLevel::CRITICAL, bool $withLogger = true): WorkerRecycleListener
    {
        $this->logger = new RecordingLogger();

        return new WorkerRecycleListener(
            [LostConnectionException::class, 'Some\\Missing\\ExceptionClass'],
            new WorkerRecycleState($this->pool, new MockClock('2026-11-15 10:00:00')),
            new ErrorSanitizer(),
            $withLogger ? $this->logger : null,
            $level,
        );
    }

    /**
     * @return list<array{0: mixed, 1: string, 2: array<mixed>}>
     */
    private function logs(): array
    {
        $out = [];
        foreach ($this->logger->records as $record) {
            $out[] = [$record['level'], $record['message'], $record['context']];
        }

        return $out;
    }

    private function exception(Throwable $error, int $type = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), new Request(), $type, $error);
    }

    private function terminate(?string $mode): TerminateEvent
    {
        $request = new Request(server: $mode === null ? [] : ['APP_RUNTIME_MODE' => $mode]);

        return new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response());
    }

    #[Test]
    public function aTriggerInThePreviousChainRecyclesTheWorkerOnceAfterTheResponse(): void
    {
        $listener = $this->listener();
        $listener->onKernelException($this->exception(new RuntimeException('session failed', 0, new LostConnectionException('server went away'))));
        self::assertTrue($listener->isRecyclePending());

        try {
            $listener->onKernelTerminate($this->terminate('web=1&worker=1'));
            self::fail('the worker is recycled');
        } catch (WorkerRecycleException $e) {
            self::assertSame(WorkerRecycleException::MESSAGE, $e->getMessage());
            self::assertStringNotContainsString(LostConnectionException::class, $e->getMessage());
            self::assertStringNotContainsString('went away', $e->getMessage());
        }

        self::assertFalse($listener->isRecyclePending());
        self::assertSame([[LogLevel::CRITICAL, 'Critical connection lost: recycling the FrankenPHP worker so the next request reconnects.', ['reason' => LostConnectionException::class, 'recorded' => true]]], $this->logs());
        self::assertSame(1, (new WorkerRecycleState($this->pool, new MockClock()))->snapshot()['recycles_total']);

        // The next request of a fresh worker is not affected.
        $listener->onKernelTerminate($this->terminate('web=1&worker=1'));
        self::assertCount(1, $this->logs());
    }

    #[Test]
    public function kernelResetModeAlsoRecycles(): void
    {
        $listener = $this->listener(LogLevel::ERROR, false);
        $listener->onKernelException($this->exception(new LostConnectionException('x'), HttpKernelInterface::SUB_REQUEST));

        $this->expectException(WorkerRecycleException::class);
        $listener->onKernelTerminate($this->terminate('web=1&worker=2'));
    }

    #[Test]
    public function otherFailuresNeverMarkTheWorker(): void
    {
        $listener = $this->listener();
        $listener->onKernelException($this->exception(new RuntimeException('not a connection', 0, new RuntimeException('still not'))));
        self::assertFalse($listener->isRecyclePending());
        $listener->onKernelTerminate($this->terminate('web=1&worker=1'));
        self::assertSame([], $this->logs());
    }

    #[Test]
    public function outsideWorkerModeNothingIsThrownAndTheMarkIsCleared(): void
    {
        $listener = $this->listener();
        foreach ([null, 'web=1', '', 'web=1&worker=0', 'web=1&worker=', 'web=1&worker=00', 'web=1&worker[]=1'] as $mode) {
            $listener->onKernelException($this->exception(new LostConnectionException('x')));
            $listener->onKernelTerminate($this->terminate($mode));
            self::assertFalse($listener->isRecyclePending());
        }
        $request = new Request(server: ['APP_RUNTIME_MODE' => ['worker=1']]);
        $listener->requestRecycle('array mode');
        $listener->onKernelTerminate(new TerminateEvent($this->createStub(HttpKernelInterface::class), $request, new Response()));

        self::assertCount(8, $this->logs());
        self::assertSame(LogLevel::DEBUG, $this->logs()[0][0]);
        self::assertSame(0, (new WorkerRecycleState($this->pool, new MockClock()))->snapshot()['recycles_total']);
    }

    #[Test]
    public function theMarkIsPerRequest(): void
    {
        $listener = $this->listener();
        $kernel   = $this->createStub(HttpKernelInterface::class);

        $listener->requestRecycle('stale');
        $listener->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertTrue($listener->isRecyclePending(), 'a sub-request keeps the mark of its main request');
        $listener->onKernelRequest(new RequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertFalse($listener->isRecyclePending(), 'a new main request starts clean');

        $listener->requestRecycle('stale');
        $listener->reset();
        self::assertFalse($listener->isRecyclePending(), 'kernel.reset clears the mark');
        $listener->onKernelTerminate($this->terminate('web=1&worker=1'));
    }

    #[Test]
    public function hostReasonsAreCleanedRedactedAndCapped(): void
    {
        $listener = $this->listener();
        $listener->requestRecycle("quota client\nlost redis://user:pa" . 'ss-REDACTED-TEST@redis:6379 ' . str_repeat('x', 300));

        try {
            $listener->onKernelTerminate($this->terminate('web=1&worker=1'));
            self::fail('recycled');
        } catch (WorkerRecycleException) {
        }
        $reason = $this->logs()[0][2]['reason'];
        self::assertIsString($reason);
        self::assertStringStartsWith('quota client lost ', $reason);
        self::assertStringNotContainsString('ss-REDACTED-TEST', $reason);
        self::assertSame(200, mb_strlen($reason));

        $listener->requestRecycle("  \n ");
        try {
            $listener->onKernelTerminate($this->terminate('web=1&worker=1'));
            self::fail('recycled');
        } catch (WorkerRecycleException) {
        }
        self::assertSame('unspecified', $this->logs()[1][2]['reason']);
    }

    #[Test]
    public function hostReasonsLoseEmailsAndIpAddresses(): void
    {
        $listener = $this->listener();
        $listener->requestRecycle('user jane.doe@example.com from 10.1.2.3 and 2001:db8::1 at 10:20:30 lost redis');

        try {
            $listener->onKernelTerminate($this->terminate('web=1&worker=1'));
            self::fail('recycled');
        } catch (WorkerRecycleException $e) {
            self::assertSame(WorkerRecycleException::MESSAGE, $e->getMessage());
        }
        self::assertCount(1, $this->logs(), 'the reason is logged once');
        self::assertSame('user ***@*** from *** and *** at 10:20:30 lost redis', $this->logs()[0][2]['reason']);
        self::assertSame('user ***@*** from *** and *** at 10:20:30 lost redis', (new WorkerRecycleState($this->pool, new MockClock()))->snapshot()['last_reason']);
    }

    #[Test]
    public function aHugeHostReasonIsBoundedBeforeRedaction(): void
    {
        $listener = $this->listener();
        $listener->requestRecycle(str_repeat('password=x ', 10000));

        $start = microtime(true);
        try {
            $listener->onKernelTerminate($this->terminate('web=1&worker=1'));
            self::fail('recycled');
        } catch (WorkerRecycleException) {
        }
        self::assertLessThan(1.0, microtime(true) - $start);
        self::assertLessThanOrEqual(200, mb_strlen((string) $this->logs()[0][2]['reason']));
        self::assertStringNotContainsString('=x', (string) $this->logs()[0][2]['reason']);
    }
}
