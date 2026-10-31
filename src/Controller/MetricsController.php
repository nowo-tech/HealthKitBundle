<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Controller;

use Nowo\HealthKitBundle\Http\BearerToken;
use Nowo\HealthKitBundle\Metrics\MetricsCollector;
use Nowo\HealthKitBundle\Metrics\MetricsTokenProviderInterface;
use Nowo\HealthKitBundle\Metrics\PrometheusTextFormatter;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Prometheus scrape endpoint (`/metrics`) aggregating every tagged metrics provider.
 *
 * Routes are opt-in: import `@NowoHealthKitBundle/config/routes/metrics.yaml`. Access (fail closed):
 * - 404 when `metrics.enabled` is false;
 * - 503 when neither a usable token (≥ 16 chars) nor any role is configured (with a token provider, only answered
 *   to requests carrying a bearer header — the provider is never called without one; anonymous requests get 401);
 * - 200 with `Authorization: Bearer <token>` (`metrics.token`, or `metrics.token_provider` resolved per scrape;
 *   constant-time compare; `?token=` is ignored), or for a session
 *   granted one of `metrics.roles` (an empty roles list grants nobody);
 * - 401 with `WWW-Authenticate: Bearer` otherwise.
 */
final readonly class MetricsController
{
    /**
     * @param MetricsCollector $collector provider aggregator
     * @param PrometheusTextFormatter $formatter exposition formatter
     * @param bool $enabled `metrics.enabled`
     * @param string|null $token `metrics.token`
     * @param list<string> $roles `metrics.roles` (empty: no role grants access)
     * @param AuthorizationCheckerInterface|null $authorization security checker (absent without SecurityBundle)
     * @param MetricsTokenProviderInterface|null $tokenProvider runtime token source, resolved per scrape (replaces `$token`)
     * @param LoggerInterface|null $logger logger for token provider failures (class names only)
     */
    public function __construct(
        private MetricsCollector $collector,
        private PrometheusTextFormatter $formatter,
        private bool $enabled = false,
        private ?string $token = null,
        private array $roles = [],
        private ?AuthorizationCheckerInterface $authorization = null,
        private ?MetricsTokenProviderInterface $tokenProvider = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Serves the scrape.
     *
     * @param Request $request incoming request
     *
     * @return Response Prometheus text, or a plain-text 401 / 404 / 503
     */
    #[Route('/metrics', name: 'nowo_health_kit_metrics', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->enabled) {
            return $this->plain("not found\n", Response::HTTP_NOT_FOUND);
        }
        // Audit BX L3: without a bearer header a token provider is never called; such requests can only be granted by
        // a role, so the answer is 401 (not the 503 "not configured", which would need the provider lookup).
        if ($this->tokenProvider instanceof MetricsTokenProviderInterface && BearerToken::extract($request) === null) {
            return $this->grantedByRole() ? $this->scrape() : $this->unauthorized();
        }

        $token = BearerToken::resolve($this->token, $this->tokenProvider, $this->logger);
        if (!BearerToken::isConfigured($token) && $this->roles === []) {
            return $this->plain("metrics access not configured\n", Response::HTTP_SERVICE_UNAVAILABLE);
        }
        if (!BearerToken::matches($request, $token) && !$this->grantedByRole()) {
            return $this->unauthorized();
        }

        return $this->scrape();
    }

    /**
     * Renders the scrape.
     *
     * @return Response Prometheus text 0.0.4
     */
    private function scrape(): Response
    {
        return $this->plain($this->formatter->format($this->collector->collect()), Response::HTTP_OK, ['Content-Type' => PrometheusTextFormatter::CONTENT_TYPE]);
    }

    /**
     * 401 answer with a Bearer challenge.
     *
     * @return Response plain-text 401
     */
    private function unauthorized(): Response
    {
        return $this->plain("unauthorized\n", Response::HTTP_UNAUTHORIZED, ['WWW-Authenticate' => 'Bearer realm="metrics"']);
    }

    /**
     * Whether the current session holds one of the configured roles.
     *
     * @return bool false when no role is configured or SecurityBundle is absent
     */
    private function grantedByRole(): bool
    {
        if (!$this->authorization instanceof AuthorizationCheckerInterface) {
            return false;
        }
        foreach ($this->roles as $role) {
            if ($this->authorization->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds a non-cacheable plain-text response.
     *
     * @param string $body body
     * @param int $status HTTP status
     * @param array<string, string> $headers extra headers (override the plain-text Content-Type)
     *
     * @return Response response with `Cache-Control: no-store, private`
     */
    private function plain(string $body, int $status, array $headers = []): Response
    {
        $response = new Response($body, $status, [...['Content-Type' => 'text/plain; charset=utf-8'], ...$headers]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
