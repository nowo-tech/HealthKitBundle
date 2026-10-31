<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Controller;

use Nowo\HealthKitBundle\Controller\MetricsController;
use Nowo\HealthKitBundle\Metrics\MetricsCollector;
use Nowo\HealthKitBundle\Metrics\PrometheusTextFormatter;
use Nowo\HealthKitBundle\Tests\Fixtures\CounterProvider;
use Nowo\HealthKitBundle\Tests\Fixtures\MutableTokenProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function in_array;

#[CoversClass(MetricsController::class)]
final class MetricsControllerTest extends TestCase
{
    private const TOKEN = 'scrape-REDACTED-TEST-token';

    /**
     * @param list<string> $roles
     */
    private function controller(bool $enabled = true, ?string $token = self::TOKEN, array $roles = [], ?AuthorizationCheckerInterface $auth = null): MetricsController
    {
        return new MetricsController(new MetricsCollector([new CounterProvider()]), new PrometheusTextFormatter(), $enabled, $token, $roles, $auth);
    }

    /**
     * @param list<string> $granted
     */
    private function auth(array $granted): AuthorizationCheckerInterface
    {
        $auth = $this->createStub(AuthorizationCheckerInterface::class);
        $auth->method('isGranted')->willReturnCallback(static fn (mixed $role): bool => in_array($role, $granted, true));

        return $auth;
    }

    #[Test]
    public function tokenGrantsTheScrape(): void
    {
        $response = $this->controller()(Request::create('/metrics', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(PrometheusTextFormatter::CONTENT_TYPE, $response->headers->get('Content-Type'));
        self::assertStringContainsString('app_ingest_ack_total 42', (string) $response->getContent());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function failsClosed(): void
    {
        self::assertSame(404, $this->controller(false)(Request::create('/metrics'))->getStatusCode());
        self::assertSame(503, $this->controller(true, null)(Request::create('/metrics'))->getStatusCode());
        self::assertSame(503, $this->controller(true, 'short')(Request::create('/metrics', server: ['HTTP_AUTHORIZATION' => 'Bearer short']))->getStatusCode());

        $denied = $this->controller()(Request::create('/metrics?token=' . self::TOKEN));
        self::assertSame(401, $denied->getStatusCode());
        self::assertSame('Bearer realm="metrics"', $denied->headers->get('WWW-Authenticate'));
        self::assertSame("unauthorized\n", $denied->getContent());
    }

    #[Test]
    public function rolesGrantTheScrapeOnlyWhenConfiguredAndGranted(): void
    {
        $request = Request::create('/metrics');

        self::assertSame(200, $this->controller(true, null, ['ROLE_OPS'], $this->auth(['ROLE_OPS']))($request)->getStatusCode());
        self::assertSame(401, $this->controller(true, null, ['ROLE_OPS', 'ROLE_ADMIN'], $this->auth(['ROLE_USER']))($request)->getStatusCode());
        self::assertSame(401, $this->controller(true, null, ['ROLE_OPS'])($request)->getStatusCode());
        self::assertSame(401, $this->controller(true, self::TOKEN, [], $this->auth(['ROLE_OPS']))($request)->getStatusCode());
    }

    #[Test]
    public function tokenProviderIsResolvedOnEveryScrapeAndFailsClosed(): void
    {
        $provider   = new MutableTokenProvider(self::TOKEN);
        $controller = new MetricsController(new MetricsCollector([new CounterProvider()]), new PrometheusTextFormatter(), true, null, [], null, $provider);
        $request    = Request::create('/metrics', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN]);

        // [token returned by the provider, provider throws] → expected status, one scrape each.
        $steps = [
            [self::TOKEN, false],
            ['rotated-token-REDACTED-TEST', false],
            ['short', false],
            [null, false],
            [self::TOKEN, true],
        ];
        $statuses = [];
        $bodies   = '';
        foreach ($steps as [$token, $fail]) {
            $provider->token = $token;
            $provider->fail  = $fail;
            $response        = $controller($request);
            $statuses[]      = $response->getStatusCode();
            $bodies .= $response->getContent();
        }

        self::assertSame([200, 401, 503, 503, 503], $statuses);
        self::assertStringNotContainsString(self::TOKEN, $bodies);
        self::assertSame(5, $provider->calls);

        $withRole = new MetricsController(new MetricsCollector([new CounterProvider()]), new PrometheusTextFormatter(), true, null, ['ROLE_OPS'], $this->auth(['ROLE_OPS']), new MutableTokenProvider());
        self::assertSame(200, $withRole(Request::create('/metrics'))->getStatusCode());
    }
}
