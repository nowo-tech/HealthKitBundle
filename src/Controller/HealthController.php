<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Controller;

use Nowo\HealthKitBundle\Health\HealthChecker;
use Nowo\HealthKitBundle\Http\DetailsAccessPolicy;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

use function is_array;
use function is_bool;
use function is_float;
use function is_string;

/**
 * Liveness (`/health/live`) and readiness (`/health/ready`) endpoints for orchestrators and load balancers.
 *
 * Routes are opt-in: import `@NowoHealthKitBundle/config/routes/health.yaml` (the controller is not autoconfigured,
 * so `routing.controllers` never exposes it on its own). Public callers get `{"status": …}` only; callers allowed by
 * {@see DetailsAccessPolicy} also get probe names, durations, messages and data. Every response is `no-store`.
 *
 * Public readiness is reused for `ready.public_cache_ttl` seconds so anonymous traffic cannot force a probe run per
 * request: a per-worker memo (FrankenPHP worker mode) backed by a PSR-6 pool (`ready.cache_pool`, default `cache.app`)
 * shared by every PHP process, so PHP-FPM and classic mode benefit too (audit BX L2). Both hold a status, a healthy
 * flag and a timestamp only (no request or user data). Without a pool, FPM/classic processes run the probes on every
 * anonymous request — rate-limit `/health/*` at the proxy then.
 */
final class HealthController
{
    public const CACHE_KEY = 'nowo_health_kit.public_ready';

    /** @var array{at: float, healthy: bool, status: string}|null */
    private ?array $publicReady = null;

    /**
     * @param HealthChecker $checker probe runner
     * @param DetailsAccessPolicy $policy details access policy
     * @param ClockInterface $clock clock (public readiness memo)
     * @param string $version application version shown by `/health/live` (empty: hidden)
     * @param bool $exposeClientIp include the caller IP (trusted proxies applied) in `/health/live`
     * @param float $publicCacheTtl seconds the public readiness answer is reused (0 disables memo and pool)
     * @param CacheItemPoolInterface|null $publicCache shared pool for the public readiness answer (null: worker memo only)
     */
    public function __construct(
        private readonly HealthChecker $checker,
        private readonly DetailsAccessPolicy $policy,
        private readonly ClockInterface $clock,
        private readonly string $version = '',
        private readonly bool $exposeClientIp = false,
        private readonly float $publicCacheTtl = 5.0,
        private readonly ?CacheItemPoolInterface $publicCache = null,
    ) {
    }

    /**
     * Liveness: the PHP process serves HTTP; runs only the `live` group (none by default except FrankenPHP).
     *
     * @param Request $request incoming request
     *
     * @return JsonResponse 200, or 503 when a critical live probe fails
     */
    #[Route('/health/live', name: 'nowo_health_kit_live', methods: ['GET', 'HEAD'], stateless: true)]
    public function live(Request $request): JsonResponse
    {
        $report  = $this->checker->check('live');
        $payload = $this->policy->allows($request) ? $report->toDetailedArray() : ['status' => $report->status->value];
        if ($this->version !== '') {
            $payload['version'] = $this->version;
        }
        if ($this->exposeClientIp) {
            $payload['client_ip'] = $request->getClientIp();
        }

        return $this->respond($payload, $report->isHealthy());
    }

    /**
     * Readiness: dependencies (database, Redis, queues…) are usable; runs the `ready` group.
     *
     * @param Request $request incoming request
     *
     * @return JsonResponse 200 when ok or degraded, 503 when a critical probe fails
     */
    #[Route('/health/ready', name: 'nowo_health_kit_ready', methods: ['GET', 'HEAD'], stateless: true)]
    public function ready(Request $request): JsonResponse
    {
        if ($this->policy->allows($request)) {
            $report = $this->checker->check('ready');

            return $this->respond($report->toDetailedArray(), $report->isHealthy());
        }

        $now    = (float) $this->clock->now()->format('U.u');
        $answer = $this->cachedPublicReady($now);
        if ($answer === null) {
            $report = $this->checker->check('ready');
            $answer = ['at' => $now, 'healthy' => $report->isHealthy(), 'status' => $report->status->value];
            $this->storePublicReady($answer);
        }

        return $this->respond(['status' => $answer['status']], $answer['healthy']);
    }

    /**
     * Fresh public readiness answer from the worker memo or the shared pool.
     *
     * @param float $now current time (seconds)
     *
     * @return array{at: float, healthy: bool, status: string}|null the answer, or null when none is fresh
     */
    private function cachedPublicReady(float $now): ?array
    {
        if ($this->publicCacheTtl <= 0.0) {
            return null;
        }
        if ($this->publicReady !== null && $now - $this->publicReady['at'] < $this->publicCacheTtl) {
            return $this->publicReady;
        }
        if (!$this->publicCache instanceof CacheItemPoolInterface) {
            return null;
        }

        try {
            $value = $this->publicCache->getItem(self::CACHE_KEY)->get();
        } catch (Throwable) {
            return null;
        }
        if (!is_array($value) || !is_float($value['at'] ?? null) || !is_bool($value['healthy'] ?? null) || !is_string($value['status'] ?? null)
            || $now - $value['at'] >= $this->publicCacheTtl || $now < $value['at']) {
            return null;
        }
        $answer = ['at' => $value['at'], 'healthy' => $value['healthy'], 'status' => $value['status']];
        // @igor-ignore - Per-worker memo of the anonymous readiness answer (status + timestamp, no request or user data).
        $this->publicReady = $answer;

        return $answer;
    }

    /**
     * Stores the public readiness answer in the worker memo and the shared pool.
     *
     * @param array{at: float, healthy: bool, status: string} $answer answer to store
     */
    private function storePublicReady(array $answer): void
    {
        if ($this->publicCacheTtl <= 0.0) {
            return;
        }
        // @igor-ignore - Per-worker memo of the anonymous readiness answer (status + timestamp, no request or user data).
        $this->publicReady = $answer;
        if (!$this->publicCache instanceof CacheItemPoolInterface) {
            return;
        }

        try {
            $item = $this->publicCache->getItem(self::CACHE_KEY);
            $item->set($answer);
            $item->expiresAfter(max(1, (int) ceil($this->publicCacheTtl)));
            $this->publicCache->save($item);
        } catch (Throwable) {
            // A broken pool only costs the shared memo; the answer itself is unaffected.
        }
    }

    /**
     * Builds a non-cacheable JSON response.
     *
     * @param array<string, mixed> $payload body
     * @param bool $healthy 200 when true, 503 otherwise
     *
     * @return JsonResponse response with `Cache-Control: no-store, private`
     */
    private function respond(array $payload, bool $healthy): JsonResponse
    {
        $response = new JsonResponse($payload, $healthy ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }
}
