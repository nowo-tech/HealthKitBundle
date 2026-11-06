<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Integration\Kernel;

use Nowo\HealthKitBundle\Controller\HealthController;
use PHPUnit\Framework\Attributes\Test;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * `live.public` / `ready.public` false in a real kernel: unknown callers get the same 404 as an unknown URL.
 */
final class NonPublicEndpointsTest extends KernelTestCase
{
    private const TOKEN = 'details-token-0123456789';

    protected function tearDown(): void
    {
        // framework.trusted_proxies sets process-wide static state; reset it for the other tests.
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        parent::tearDown();
    }

    /**
     * @param array<string, string> $server
     */
    private function send(HealthKitTestKernel $kernel, string $method, string $uri, array $server = []): Response
    {
        $request  = Request::create($uri, $method, [], [], [], ['REMOTE_ADDR' => '203.0.113.9', ...$server]);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * The hidden endpoint answers like a path without a route: same exception class and message shape (so the same
     * error page in production), same status and headers, and nothing that hints at the endpoint (no Allow header,
     * no X-Robots-Tag, no JSON body).
     */
    private function assertLikeUnknownUrl(Response $unknown, Response $response, string $method, string $uri): void
    {
        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame(404, $response->getStatusCode());
        self::assertSame($unknown->headers->get('Content-Type'), $response->headers->get('Content-Type'));
        self::assertSame($unknown->headers->get('Cache-Control'), $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('Allow'));
        self::assertFalse($response->headers->has('X-Robots-Tag'));
        if ($method !== 'HEAD') {
            self::assertStringContainsString('NotFoundHttpException', (string) $unknown->getContent());
            self::assertStringContainsString('NotFoundHttpException', (string) $response->getContent());
            self::assertStringContainsString('No route found for "' . $method . ' http://localhost' . $uri . '"', (string) $response->getContent());
            self::assertStringNotContainsString('"status"', (string) $response->getContent());
        }
    }

    private function testContainer(HealthKitTestKernel $kernel): ContainerInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);

        return $container;
    }

    #[Test]
    public function readyIsNotFoundForUnknownCallersAndAnsweredToAllowedOnes(): void
    {
        $kernel = $this->bootKernel(['ready' => ['public' => false], 'details' => ['token' => self::TOKEN]]);

        foreach (['GET', 'HEAD', 'POST', 'PUT', 'OPTIONS'] as $method) {
            $unknown = $this->send($kernel, $method, '/no-such-endpoint');
            // Internet caller, and loopback through the host proxy (X-Forwarded-For / Forwarded / X-Real-IP).
            foreach ([[], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '127.0.0.1'], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_FORWARDED' => 'for=127.0.0.1'], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '127.0.0.1'], ['HTTP_AUTHORIZATION' => 'Bearer wrong-token-wrong-token']] as $server) {
                $this->assertLikeUnknownUrl($unknown, $this->send($kernel, $method, '/health/ready', $server), $method, '/health/ready');
            }
        }
        $cache = $this->testContainer($kernel)->get('cache.app');
        self::assertInstanceOf(CacheItemPoolInterface::class, $cache);
        self::assertFalse($cache->hasItem(HealthController::CACHE_KEY));

        $loopback = $this->send($kernel, 'GET', '/health/ready', ['REMOTE_ADDR' => '127.0.0.1']);
        self::assertSame(200, $loopback->getStatusCode());
        self::assertSame('ok', $this->json($loopback)['status']);
        self::assertSame(200, $this->send($kernel, 'GET', '/health/ready', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN])->getStatusCode());
        self::assertFalse($cache->hasItem(HealthController::CACHE_KEY));

        // Allowed callers keep the router's 405 on other methods.
        $post = $this->send($kernel, 'POST', '/health/ready', ['REMOTE_ADDR' => '127.0.0.1']);
        self::assertSame(405, $post->getStatusCode());
        self::assertSame('GET, HEAD', $post->headers->get('Allow'));

        // live stays public and keeps its 405.
        self::assertSame(['status' => 'ok'], $this->json($this->send($kernel, 'GET', '/health/live')));
        self::assertSame(405, $this->send($kernel, 'POST', '/health/live')->getStatusCode());
    }

    #[Test]
    public function routerNormalisationDoesNotRevealTheHiddenEndpoint(): void
    {
        $kernel = $this->bootKernel(['ready' => ['public' => false], 'live' => ['public' => false], 'details' => ['token' => self::TOKEN]]);

        // Trailing slash (GET / HEAD): the redirectable matcher would answer 301 to /health/ready (audit GS-L1).
        foreach (['GET', 'HEAD'] as $method) {
            $unknown = $this->send($kernel, $method, '/no-such-endpoint/');
            foreach (['/health/ready/', '/health/ready//', '/health/live/'] as $uri) {
                $this->assertLikeUnknownUrl($unknown, $this->send($kernel, $method, $uri), $method, $uri);
            }
        }
        $redirect = $this->send($kernel, 'GET', '/health/ready/', ['REMOTE_ADDR' => '127.0.0.1']);
        self::assertSame(301, $redirect->getStatusCode());
        self::assertSame('http://localhost/health/ready', $redirect->headers->get('Location'));

        // Percent-encoded path with another method: the router decodes it and would answer 405 (audit GS-L2).
        foreach ([['POST', '/health/%72eady'], ['DELETE', '/health/re%61dy'], ['PUT', '/health/%6Cive']] as [$method, $uri]) {
            $this->assertLikeUnknownUrl($this->send($kernel, $method, '/no-such-endpoint'), $this->send($kernel, $method, $uri), $method, $uri);
        }
        $allowed = $this->send($kernel, 'POST', '/health/%72eady', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN]);
        self::assertSame(405, $allowed->getStatusCode());
        self::assertSame('GET, HEAD', $allowed->headers->get('Allow'));
        self::assertSame(200, $this->send($kernel, 'GET', '/health/%72eady', ['REMOTE_ADDR' => '127.0.0.1'])->getStatusCode());

        // Referer is echoed in the message like the router does; format suffixes have no route at all.
        $referred = $this->send($kernel, 'GET', '/health/ready', ['HTTP_REFERER' => 'https://elsewhere.test/']);
        $this->assertLikeUnknownUrl($this->send($kernel, 'GET', '/no-such-endpoint', ['HTTP_REFERER' => 'https://elsewhere.test/']), $referred, 'GET', '/health/ready');
        self::assertStringContainsString('No route found for "GET http://localhost/health/ready" (from "https://elsewhere.test/")', (string) $referred->getContent());
        foreach (['/health/ready.json', '/health/live.json'] as $uri) {
            $this->assertLikeUnknownUrl($this->send($kernel, 'GET', '/no-such-endpoint'), $this->send($kernel, 'GET', $uri), 'GET', $uri);
        }
    }

    #[Test]
    public function hostRoutesToTheControllerAreHiddenToo(): void
    {
        $kernel = $this->bootKernel(['ready' => ['public' => false]], [], static function (RoutingConfigurator $routes): void {
            $routes->import('@NowoHealthKitBundle/config/routes/health.yaml');
            $routes->add('app_ready', '/internal/ready')->controller(HealthController::class . '::ready')->methods(['GET', 'HEAD']);
        });

        $this->assertLikeUnknownUrl($this->send($kernel, 'GET', '/no-such-endpoint'), $this->send($kernel, 'GET', '/internal/ready'), 'GET', '/internal/ready');
        $this->assertLikeUnknownUrl($this->send($kernel, 'POST', '/no-such-endpoint'), $this->send($kernel, 'POST', '/internal/ready'), 'POST', '/internal/ready');
        self::assertSame(200, $this->send($kernel, 'GET', '/internal/ready', ['REMOTE_ADDR' => '127.0.0.1'])->getStatusCode());
    }

    #[Test]
    public function liveIsNotFoundForUnknownCallersWhenNotPublic(): void
    {
        $kernel  = $this->bootKernel(['live' => ['public' => false]]);
        $unknown = $this->send($kernel, 'GET', '/no-such-endpoint');

        $this->assertLikeUnknownUrl($unknown, $this->send($kernel, 'GET', '/health/live'), 'GET', '/health/live');
        $this->assertLikeUnknownUrl($this->send($kernel, 'POST', '/no-such-endpoint'), $this->send($kernel, 'POST', '/health/live'), 'POST', '/health/live');
        self::assertSame(200, $this->send($kernel, 'GET', '/health/live', ['REMOTE_ADDR' => '::1'])->getStatusCode());
        self::assertSame(['status' => 'ok'], $this->json($this->send($kernel, 'GET', '/health/ready')));
    }

    #[Test]
    public function trustedForwardedIpOnlyHonoursTrustedProxies(): void
    {
        $kernel = $this->bootKernel(
            ['ready' => ['public' => false], 'details' => ['trust_forwarded_ip' => true, 'allowed_ips' => ['10.0.0.0/8']]],
            ['framework' => ['trusted_proxies' => '127.0.0.1', 'trusted_headers' => ['x-forwarded-for']]],
        );

        // Through the trusted proxy, the forwarded client decides.
        self::assertSame(200, $this->send($kernel, 'GET', '/health/ready', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '10.1.2.3'])->getStatusCode());
        self::assertSame(404, $this->send($kernel, 'GET', '/health/ready', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9'])->getStatusCode());
        // A direct, untrusted peer cannot claim an allowed address.
        self::assertSame(404, $this->send($kernel, 'GET', '/health/ready', ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '10.1.2.3'])->getStatusCode());
        // Spoofed hop prepended by the client: the trusted proxy appends the real peer, which decides.
        self::assertSame(404, $this->send($kernel, 'GET', '/health/ready', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '10.1.2.3, 203.0.113.9'])->getStatusCode());
    }

    #[Test]
    public function publicTrueIsTheDefaultByteForByte(): void
    {
        $default  = $this->bootKernel(['ready' => ['public_cache_ttl' => 0]]);
        $explicit = $this->bootKernel(['ready' => ['public_cache_ttl' => 0, 'public' => true], 'live' => ['public' => true]]);

        foreach (['/health/live', '/health/ready'] as $uri) {
            foreach ([[], ['REMOTE_ADDR' => '127.0.0.1']] as $server) {
                $a = $this->send($default, 'GET', $uri, $server);
                $b = $this->send($explicit, 'GET', $uri, $server);
                self::assertSame($a->getStatusCode(), $b->getStatusCode());
                self::assertSame(preg_replace('/"duration_ms":[\d.]+/', '', (string) $a->getContent()), preg_replace('/"duration_ms":[\d.]+/', '', (string) $b->getContent()));
                self::assertSame($a->headers->get('Cache-Control'), $b->headers->get('Cache-Control'));
            }
            self::assertSame(405, $this->send($explicit, 'POST', $uri)->getStatusCode());
        }
        self::assertFalse($this->testContainer($explicit)->has('nowo_health_kit.hidden_endpoint_listener'));
        $hidden = $this->bootKernel(['ready' => ['public' => false]]);
        self::assertTrue($this->testContainer($hidden)->has('nowo_health_kit.hidden_endpoint_listener'));
    }
}
