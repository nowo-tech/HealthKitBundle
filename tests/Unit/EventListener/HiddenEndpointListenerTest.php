<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\EventListener;

use Nowo\HealthKitBundle\EventListener\HiddenEndpointListener;
use Nowo\HealthKitBundle\Http\DetailsAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Throwable;

#[CoversClass(HiddenEndpointListener::class)]
final class HiddenEndpointListenerTest extends TestCase
{
    private function generator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
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
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return match ($name) {
                    'nowo_health_kit_live'  => '/health/live',
                    'nowo_health_kit_ready' => '/health/ready',
                    default                 => throw new RuntimeException('Route not imported.'),
                };
            }
        };
    }

    /**
     * @param array<string, string> $server
     */
    private function event(Throwable $throwable, string $path, array $server = []): ExceptionEvent
    {
        $request = Request::create($path, 'POST', server: ['REMOTE_ADDR' => '203.0.113.9', ...$server]);

        return new ExceptionEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
    }

    /**
     * @param list<string> $routes
     */
    private function listener(?UrlGeneratorInterface $generator, array $routes = ['missing_route', 'nowo_health_kit_ready']): HiddenEndpointListener
    {
        return new HiddenEndpointListener(new DetailsAccessPolicy(['127.0.0.1'], 'details-token-0123456789'), $generator, $routes);
    }

    #[Test]
    public function methodNotAllowedOnAHiddenRouteBecomesNotFoundForUnknownCallers(): void
    {
        $event = $this->event(new MethodNotAllowedHttpException(['GET', 'HEAD']), '/health/ready');
        $this->listener($this->generator())->onKernelException($event);

        $throwable = $event->getThrowable();
        self::assertInstanceOf(NotFoundHttpException::class, $throwable);
        self::assertSame('No route found for "POST http://localhost/health/ready"', $throwable->getMessage());
    }

    #[Test]
    public function otherCasesAreLeftUntouched(): void
    {
        $notAllowed = new MethodNotAllowedHttpException(['GET', 'HEAD']);

        // Allowed callers (loopback without proxy headers, bearer token) keep the 405.
        foreach ([['REMOTE_ADDR' => '127.0.0.1'], ['HTTP_AUTHORIZATION' => 'Bearer details-token-0123456789']] as $server) {
            $event = $this->event($notAllowed, '/health/ready', $server);
            $this->listener($this->generator())->onKernelException($event);
            self::assertSame($notAllowed, $event->getThrowable());
        }

        // A public route, another path, another exception, or no router.
        $cases = [
            [$this->listener($this->generator()), $this->event($notAllowed, '/health/live')],
            [$this->listener($this->generator()), $this->event($notAllowed, '/elsewhere')],
            [$this->listener(null), $this->event($notAllowed, '/health/ready')],
        ];
        $other   = new RuntimeException('boom');
        $cases[] = [$this->listener($this->generator()), $this->event($other, '/health/ready')];
        foreach ($cases as [$listener, $event]) {
            $before = $event->getThrowable();
            $listener->onKernelException($event);
            self::assertSame($before, $event->getThrowable());
        }
    }
}
