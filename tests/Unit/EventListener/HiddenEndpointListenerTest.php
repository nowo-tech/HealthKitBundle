<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\EventListener;

use Nowo\HealthKitBundle\Controller\HealthController;
use Nowo\HealthKitBundle\EventListener\HiddenEndpointListener;
use Nowo\HealthKitBundle\Http\DetailsAccessPolicy;
use Nowo\HealthKitBundle\Tests\Fixtures\RecordingUrlMatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;
use Throwable;

#[CoversClass(HiddenEndpointListener::class)]
final class HiddenEndpointListenerTest extends TestCase
{
    private function matcher(): RecordingUrlMatcher
    {
        return new RecordingUrlMatcher();
    }

    /**
     * @param array<string, string> $server
     */
    private function exceptionEvent(Throwable $throwable, string $path, array $server = []): ExceptionEvent
    {
        $request = Request::create($path, 'POST', server: ['REMOTE_ADDR' => '203.0.113.9', ...$server]);

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, string> $server
     */
    private function requestEvent(array $attributes, array $server = []): RequestEvent
    {
        $request = Request::create('/health/ready/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9', ...$server]);
        $request->attributes->add($attributes);

        return new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
    }

    private function listener(?UrlMatcherInterface $matcher): HiddenEndpointListener
    {
        return new HiddenEndpointListener(new DetailsAccessPolicy(['127.0.0.1'], 'details-token-0123456789'), $matcher, ['ready']);
    }

    #[Test]
    public function requestsRoutedToAHiddenEndpointAreNotFoundForUnknownCallers(): void
    {
        $hidden = [
            // Trailing-slash redirect: _route kept, _controller is the RedirectController.
            ['_route' => 'nowo_health_kit_ready', '_controller' => 'Symfony\\Bundle\\FrameworkBundle\\Controller\\RedirectController::urlRedirectAction'],
            // Host route to the controller, string and array forms.
            ['_route' => 'app_ready', '_controller' => HealthController::class . '::ready'],
            ['_route' => 'app_ready', '_controller' => [HealthController::class, 'ready']],
        ];
        foreach ($hidden as $attributes) {
            try {
                $this->listener(null)->onKernelRequest($this->requestEvent($attributes));
                self::fail('Expected a 404.');
            } catch (NotFoundHttpException $e) {
                self::assertSame('No route found for "GET http://localhost/health/ready/"', $e->getMessage());
            }
        }

        // Allowed callers, public endpoints and other routes pass.
        $passing = [
            [['_route' => 'nowo_health_kit_ready'], ['REMOTE_ADDR' => '127.0.0.1']],
            [['_route' => 'nowo_health_kit_ready'], ['HTTP_AUTHORIZATION' => 'Bearer details-token-0123456789']],
            [['_route' => 'nowo_health_kit_live', '_controller' => HealthController::class . '::live'], []],
            [['_route' => 'other', '_controller' => ['not-a-pair']], []],
            [[], []],
        ];
        foreach ($passing as [$attributes, $server]) {
            $event = $this->requestEvent($attributes, $server);
            $this->listener(null)->onKernelRequest($event);
            self::assertFalse($event->hasResponse());
        }
    }

    #[Test]
    public function methodNotAllowedOnAHiddenEndpointBecomesNotFoundWithTheRouterDecoding(): void
    {
        $matcher = $this->matcher();
        foreach (['/health/ready', '/health/%72eady', '/app/ready'] as $path) {
            $event = $this->exceptionEvent(new MethodNotAllowedHttpException(['GET', 'HEAD']), $path);
            $this->listener($matcher)->onKernelException($event);

            $throwable = $event->getThrowable();
            self::assertInstanceOf(NotFoundHttpException::class, $throwable, $path);
            self::assertSame('No route found for "POST http://localhost' . $path . '"', $throwable->getMessage());
        }
        // The replay ran as GET and the context method was restored.
        self::assertSame('GET', $matcher->seenMethod);
        self::assertSame('POST', $matcher->getContext()->getMethod());
    }

    #[Test]
    public function otherCasesAreLeftUntouched(): void
    {
        $notAllowed = new MethodNotAllowedHttpException(['GET', 'HEAD']);

        // Allowed callers (loopback without proxy headers, bearer token) keep the 405.
        foreach ([['REMOTE_ADDR' => '127.0.0.1'], ['HTTP_AUTHORIZATION' => 'Bearer details-token-0123456789']] as $server) {
            $event = $this->exceptionEvent($notAllowed, '/health/ready', $server);
            $this->listener($this->matcher())->onKernelException($event);
            self::assertSame($notAllowed, $event->getThrowable());
        }

        // A public endpoint, another route, no route at all, another exception, or no router.
        $matcher = $this->matcher();
        $cases   = [
            [$this->listener($matcher), $this->exceptionEvent($notAllowed, '/health/live')],
            [$this->listener($matcher), $this->exceptionEvent($notAllowed, '/other')],
            [$this->listener($matcher), $this->exceptionEvent($notAllowed, '/elsewhere')],
            [$this->listener($matcher), $this->exceptionEvent(new RuntimeException('boom'), '/health/ready')],
            [$this->listener(null), $this->exceptionEvent($notAllowed, '/health/ready')],
        ];
        foreach ($cases as [$listener, $event]) {
            $before = $event->getThrowable();
            $listener->onKernelException($event);
            self::assertSame($before, $event->getThrowable());
        }
        self::assertSame('POST', $matcher->getContext()->getMethod());
    }
}
