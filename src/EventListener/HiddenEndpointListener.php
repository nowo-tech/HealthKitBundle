<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\EventListener;

use Nowo\HealthKitBundle\Http\DetailsAccessPolicy;
use Nowo\HealthKitBundle\Http\HiddenEndpoint;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Throwable;

/**
 * Turns the router's 405 on a non-public health endpoint into the same 404 as any unknown URL.
 *
 * `/health/live` and `/health/ready` only accept GET and HEAD, so a `POST /health/ready` is answered by the router
 * with 405 (`Allow: GET, HEAD`) before the controller runs, which would reveal that the endpoint exists. For the
 * routes whose `public` option is false, a caller the details rules do not allow gets the router-like 404 of
 * {@see HiddenEndpoint} instead; allowed callers keep the 405. Registered only when `live.public` or `ready.public`
 * is false.
 */
final readonly class HiddenEndpointListener
{
    /**
     * @param DetailsAccessPolicy $policy details access policy (the same rules as the controller)
     * @param UrlGeneratorInterface|null $urlGenerator router (null: no route to compare, nothing is changed)
     * @param list<string> $hiddenRoutes names of the non-public health routes
     */
    public function __construct(
        private DetailsAccessPolicy $policy,
        private ?UrlGeneratorInterface $urlGenerator,
        private array $hiddenRoutes,
    ) {
    }

    /**
     * Replaces a 405 on a hidden route by a 404 for callers the details rules do not allow.
     *
     * @param ExceptionEvent $event kernel exception event
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof MethodNotAllowedHttpException || !$this->urlGenerator instanceof UrlGeneratorInterface) {
            return;
        }

        $request = $event->getRequest();
        $path    = $request->getBaseUrl() . $request->getPathInfo();
        foreach ($this->hiddenRoutes as $route) {
            try {
                $routePath = $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_PATH);
            } catch (Throwable) {
                continue;
            }
            if ($routePath !== $path) {
                continue;
            }
            if (!$this->policy->allows($request)) {
                $event->setThrowable(HiddenEndpoint::notFound($request));
            }

            return;
        }
    }
}
