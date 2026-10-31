<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Controller;

use Nowo\HealthKitBundle\Health\HealthChecker;
use Nowo\HealthKitBundle\Http\DetailsAccessPolicy;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness (`/health/live`) and readiness (`/health/ready`) endpoints for orchestrators and load balancers.
 *
 * Routes are opt-in: import `@NowoHealthKitBundle/config/routes/health.yaml` (the controller is not autoconfigured,
 * so `routing.controllers` never exposes it on its own). Public callers get `{"status": …}` only; callers allowed by
 * {@see DetailsAccessPolicy} also get probe names, durations, messages and data. Every response is `no-store`.
 *
 * Public readiness is memoised per PHP worker for `ready.public_cache_ttl` seconds so anonymous traffic cannot hammer
 * the database or Redis; the memo holds a status and a timestamp only (no request or user data).
 */
final class HealthController
{
    /** @var array{at: float, healthy: bool, status: string}|null */
    private ?array $publicReady = null;

    /**
     * @param HealthChecker $checker probe runner
     * @param DetailsAccessPolicy $policy details access policy
     * @param ClockInterface $clock clock (public readiness memo)
     * @param string $version application version shown by `/health/live` (empty: hidden)
     * @param bool $exposeClientIp include the caller IP (trusted proxies applied) in `/health/live`
     * @param float $publicCacheTtl seconds the public readiness answer is reused (0 disables the memo)
     */
    public function __construct(
        private readonly HealthChecker $checker,
        private readonly DetailsAccessPolicy $policy,
        private readonly ClockInterface $clock,
        private readonly string $version = '',
        private readonly bool $exposeClientIp = false,
        private readonly float $publicCacheTtl = 5.0,
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

        $now = (float) $this->clock->now()->format('U.u');
        if ($this->publicReady === null || $this->publicCacheTtl <= 0.0 || $now - $this->publicReady['at'] >= $this->publicCacheTtl) {
            $report = $this->checker->check('ready');
            // @igor-ignore - Per-worker memo of the anonymous readiness answer (status + timestamp, no request or user data).
            $this->publicReady = ['at' => $now, 'healthy' => $report->isHealthy(), 'status' => $report->status->value];
        }

        return $this->respond(['status' => $this->publicReady['status']], $this->publicReady['healthy']);
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
