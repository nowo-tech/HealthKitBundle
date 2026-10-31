<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Runtime;

use Closure;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use function ctype_digit;
use function getenv;
use function in_array;
use function is_scalar;
use function is_string;
use function strtolower;
use function trim;

use const PHP_SAPI;

/**
 * Reads non-secret FrankenPHP / Symfony Runtime signals.
 *
 * Values come from the current request server bag (FrankenPHP sets `FRANKENPHP_WORKER` per request) and fall back
 * to the process environment, so no superglobal is read and nothing is cached between requests.
 */
final readonly class FrankenPhpRuntime
{
    /**
     * @param RequestStack|null $requestStack request stack (null outside HTTP, e.g. in CLI metrics collection)
     * @param string $sapi PHP SAPI name (injectable for tests)
     * @param Closure(string): (false|string)|null $environment process environment reader (defaults to getenv())
     */
    public function __construct(
        private ?RequestStack $requestStack = null,
        private string $sapi = PHP_SAPI,
        private ?Closure $environment = null,
    ) {
    }

    /**
     * Returns the runtime snapshot.
     *
     * @return array{sapi: string, frankenphp_mode: string, frankenphp_worker: bool, reset_kernel: bool, app_runtime_mode: string|null, worker_num: int|null}
     */
    public function snapshot(): array
    {
        $modeRaw = strtolower(trim($this->read('FRANKENPHP_MODE') ?? 'classic'));
        $mode    = in_array($modeRaw, ['classic', 'worker'], true) ? $modeRaw : 'classic';

        $worker = in_array(strtolower(trim($this->read('FRANKENPHP_WORKER') ?? '')), ['1', 'true'], true);
        $reset  = in_array(strtolower(trim($this->read('FRANKENPHP_RESET_KERNEL') ?? '')), ['1', 'true', 'yes', 'on'], true);

        $runtimeMode = $this->read('APP_RUNTIME_MODE');
        $workerNum   = $this->read('FRANKENPHP_WORKER_NUM');

        return [
            'sapi'              => $this->sapi,
            'frankenphp_mode'   => $mode,
            'frankenphp_worker' => $worker,
            'reset_kernel'      => $reset,
            'app_runtime_mode'  => $runtimeMode !== null && $runtimeMode !== '' ? $runtimeMode : null,
            'worker_num'        => $mode === 'worker' && $workerNum !== null && ctype_digit($workerNum) ? (int) $workerNum : null,
        ];
    }

    /**
     * Reads one variable from the current request server bag, then from the process environment.
     *
     * @param string $key variable name
     *
     * @return string|null the value, or null when unset
     */
    private function read(string $key): ?string
    {
        $request = $this->requestStack?->getMainRequest();
        if ($request instanceof Request && $request->server->has($key)) {
            $value = $request->server->get($key);

            return is_scalar($value) ? (string) $value : null;
        }

        $value = $this->environment instanceof Closure ? ($this->environment)($key) : getenv($key);

        return is_string($value) ? $value : null;
    }
}
