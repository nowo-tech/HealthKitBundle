<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Fixtures;

use Nowo\HealthKitBundle\Controller\HealthController;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Matcher with a few fixed routes that decodes the path like the compiled matcher and records the method it saw.
 */
final class RecordingUrlMatcher implements UrlMatcherInterface
{
    public ?string $seenMethod = null;
    private RequestContext $context;

    public function __construct()
    {
        $this->context = new RequestContext(method: 'POST');
    }

    public function setContext(RequestContext $context): void
    {
        $this->context = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->context;
    }

    /**
     * @return array<string, mixed>
     */
    public function match(string $pathinfo): array
    {
        $this->seenMethod = $this->context->getMethod();

        return match (rawurldecode($pathinfo)) {
            '/health/ready' => ['_route' => 'nowo_health_kit_ready', '_controller' => HealthController::class . '::ready'],
            '/health/live'  => ['_route' => 'nowo_health_kit_live', '_controller' => HealthController::class . '::live'],
            '/app/ready'    => ['_route' => 'app_ready', '_controller' => [HealthController::class, 'ready']],
            '/other'        => ['_route' => 'other', '_controller' => 'App\Controller::other'],
            default         => throw new ResourceNotFoundException('No routes found.'),
        };
    }
}
