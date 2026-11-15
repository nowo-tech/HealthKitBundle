<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Runtime;

use Nowo\HealthKitBundle\Health\ErrorSanitizer;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function is_scalar;
use function mb_substr;
use function preg_replace;
use function str_contains;
use function trim;

/**
 * Recycles the FrankenPHP worker after a request that hit a lost critical connection (`runtime.worker_recycle`).
 *
 * When the shared Redis (or another critical backend) is recreated, a kept-alive worker keeps its dead connections
 * (session handler, cache pools, rate-limit clients) and every request it serves fails until the container restarts,
 * while the healthcheck can stay green. This listener:
 * 1. `kernel.exception` (priority 2048): walks the throwable and its previous chain; an instance of a trigger class
 *    (Redis client exceptions by default, plus `exception_classes`) marks the worker. Host code can mark it too
 *    through {@see WorkerRecyclerInterface::requestRecycle()}.
 * 2. `kernel.terminate` (priority -8192, after the response has been sent and after the other terminate listeners):
 *    when marked and the request is served by Symfony's FrankenPHP worker runner (`APP_RUNTIME_MODE` contains
 *    `worker=`), records the recycle, logs it and throws {@see WorkerRecycleException}. The runner rethrows it once
 *    `frankenphp_handle_request()` returns, the worker script ends and FrankenPHP starts a fresh worker with new
 *    connections. The response is never cut: nothing happens before `kernel.terminate`.
 *
 * Outside worker mode (PHP-FPM, classic FrankenPHP, CLI, tests) nothing is thrown: those processes do not outlive the
 * request. The mark is per request: cleared at the main `kernel.request`, at `kernel.terminate` and by `kernel.reset`.
 */
final class WorkerRecycleListener implements WorkerRecyclerInterface, ResetInterface
{
    /** @var int longest reason kept */
    private const MAX_REASON_LENGTH = 200;

    /** @var string|null reason of the pending recycle (current request only) */
    private ?string $pendingReason = null;

    /**
     * @param list<string> $triggerClasses throwable classes or interfaces that mark the worker (matched with instanceof)
     * @param WorkerRecycleState $state recycle record (metrics and probe)
     * @param ErrorSanitizer $sanitizer redacts secrets from host-supplied reasons
     * @param LoggerInterface|null $logger logger (channel `health`)
     * @param string $logLevel PSR-3 level of the recycle log entry
     */
    public function __construct(
        private readonly array $triggerClasses,
        private readonly WorkerRecycleState $state,
        private readonly ErrorSanitizer $sanitizer,
        private readonly ?LoggerInterface $logger = null,
        private readonly string $logLevel = LogLevel::CRITICAL,
    ) {
    }

    /**
     * Marks the worker for recycling after the current response.
     *
     * @param string $reason short, non-secret reason (redacted and capped at 200 characters)
     */
    public function requestRecycle(string $reason): void
    {
        $clean               = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $reason));
        $clean               = mb_substr($this->sanitizer->redact($clean), 0, self::MAX_REASON_LENGTH);
        $this->pendingReason = $clean === '' ? 'unspecified' : $clean;
    }

    /**
     * Whether the current request marked the worker.
     *
     * @return bool true when a recycle is pending
     */
    public function isRecyclePending(): bool
    {
        return $this->pendingReason !== null;
    }

    /**
     * Clears a mark left over by a request that never reached `kernel.terminate`.
     *
     * @param RequestEvent $event kernel request event
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->pendingReason = null;
        }
    }

    /**
     * Marks the worker when the throwable (or one of its previous throwables) is a trigger class.
     *
     * @param ExceptionEvent $event kernel exception event
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        for ($error = $event->getThrowable(); $error instanceof Throwable; $error = $error->getPrevious()) {
            foreach ($this->triggerClasses as $class) {
                if ($error instanceof $class) {
                    $this->requestRecycle($error::class);

                    return;
                }
            }
        }
    }

    /**
     * Ends the worker after the response when marked and served by the FrankenPHP worker runner.
     *
     * @param TerminateEvent $event kernel terminate event (the response is already sent)
     *
     * @throws WorkerRecycleException when the worker must be recycled
     */
    public function onKernelTerminate(TerminateEvent $event): void
    {
        $reason              = $this->pendingReason;
        $this->pendingReason = null;
        if ($reason === null) {
            return;
        }

        $mode = $event->getRequest()->server->get('APP_RUNTIME_MODE', '');
        if (!is_scalar($mode) || !str_contains((string) $mode, 'worker=')) {
            $this->logger?->debug('Critical connection failure outside FrankenPHP worker mode: no worker to recycle.', ['reason' => $reason]);

            return;
        }

        $recorded = $this->state->record($reason);
        $this->logger?->log($this->logLevel, 'Critical connection lost: recycling the FrankenPHP worker so the next request reconnects.', [
            'reason'   => $reason,
            'recorded' => $recorded,
        ]);

        throw new WorkerRecycleException('Critical connection lost (' . $reason . '); the FrankenPHP worker restarts with fresh connections.');
    }

    /**
     * Clears the mark (`kernel.reset`).
     */
    public function reset(): void
    {
        $this->pendingReason = null;
    }
}
