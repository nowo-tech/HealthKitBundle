<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\EventListener;

use Nowo\HealthKitBundle\Controller\HealthController;
use Nowo\HealthKitBundle\Http\DetailsAccessPolicy;
use Nowo\HealthKitBundle\Http\HiddenEndpoint;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Throwable;

use function is_array;
use function is_string;

/**
 * Answers every router outcome on a non-public health endpoint with the same 404 as an unknown URL.
 *
 * Registered only when `live.public` or `ready.public` is false. For a caller the details rules do not allow:
 * - `kernel.request` (priority 31, right after the router at 32): when the matched route is a hidden endpoint — the
 *   bundle route name, or any route whose `_controller` is the hidden {@see HealthController} action — the request
 *   gets {@see HiddenEndpoint::notFound()} before the firewall, the controller or anything else runs. This also covers
 *   the trailing-slash redirect of the redirectable matcher (`/health/ready/` → 301), which keeps `_route`.
 * - `kernel.exception` (priority 16): a 405 (`POST /health/ready`, `Allow: GET, HEAD`) is replayed through the router
 *   as a GET on the same (still encoded) path, so the matcher applies its own decoding and normalisation; when that
 *   GET would reach a hidden endpoint the 405 becomes the 404.
 *
 * Allowed callers are never affected (they keep the 301 / 405 / answer). The controller keeps its own check as a
 * second line.
 */
final readonly class HiddenEndpointListener
{
    /** @var string bundle route name prefix (`nowo_health_kit_live`, `nowo_health_kit_ready`) */
    public const ROUTE_PREFIX = 'nowo_health_kit_';

    /**
     * @param DetailsAccessPolicy $policy details access policy (the same rules as the controller)
     * @param UrlMatcherInterface|null $matcher router used to replay 405s as GET (null: 405s are left as they are)
     * @param list<string> $hiddenEndpoints non-public endpoints (`live`, `ready`)
     */
    public function __construct(
        private DetailsAccessPolicy $policy,
        private ?UrlMatcherInterface $matcher,
        private array $hiddenEndpoints,
    ) {
    }

    /**
     * Rejects a request routed to a hidden endpoint (or to its trailing-slash redirect) for callers not allowed.
     *
     * @param RequestEvent $event kernel request event (after routing)
     *
     * @throws NotFoundHttpException the router-like 404
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($this->isHidden($request->attributes->all()) && !$this->policy->allows($request)) {
            throw HiddenEndpoint::notFound($request);
        }
    }

    /**
     * Replaces a 405 on a hidden endpoint by the 404 for callers the details rules do not allow.
     *
     * @param ExceptionEvent $event kernel exception event
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof MethodNotAllowedHttpException || !$this->matcher instanceof UrlMatcherInterface) {
            return;
        }

        $request = $event->getRequest();
        $context = $this->matcher->getContext();
        $method  = $context->getMethod();
        $context->setMethod('GET');
        try {
            $parameters = $this->matcher->match($request->getPathInfo());
        } catch (Throwable) {
            return;
        } finally {
            $context->setMethod($method);
        }

        if ($this->isHidden($parameters) && !$this->policy->allows($request)) {
            $event->setThrowable(HiddenEndpoint::notFound($request));
        }
    }

    /**
     * Whether routing parameters point at a hidden endpoint (by bundle route name or by controller).
     *
     * @param array<string, mixed> $parameters request attributes or matcher result
     *
     * @return bool true for a hidden endpoint
     */
    private function isHidden(array $parameters): bool
    {
        $route      = $parameters['_route'] ?? null;
        $controller = $parameters['_controller'] ?? null;
        if (is_array($controller) && isset($controller[0], $controller[1]) && is_string($controller[0]) && is_string($controller[1])) {
            $controller = $controller[0] . '::' . $controller[1];
        }
        foreach ($this->hiddenEndpoints as $endpoint) {
            if ($route === self::ROUTE_PREFIX . $endpoint || $controller === HealthController::class . '::' . $endpoint) {
                return true;
            }
        }

        return false;
    }
}
