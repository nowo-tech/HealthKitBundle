<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Integration\Kernel;

use Nowo\HealthKitBundle\Controller\HealthController;
use Nowo\HealthKitBundle\Controller\MetricsController;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

/**
 * REQ-SF-004 rule 7: bundle routes are opt-in. A host whose routing is only `routing.controllers` (Symfony >= 7.3
 * default config/routes.yaml) must not expose any bundle route; this fails if the controllers become autoconfigured.
 */
final class RoutesOptInTest extends KernelTestCase
{
    #[Test]
    public function routingControllersAloneExposesNoBundleRoute(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers needs Symfony >= 7.3.');
        }

        $kernel = $this->bootKernel([], [], static function (RoutingConfigurator $routes): void {
            $routes->import('routing.controllers');
        });

        $router = $kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            self::assertStringStartsNotWith('nowo_health_kit', $name);
            self::assertStringNotContainsString(HealthController::class, (string) $route->getDefault('_controller'));
            self::assertStringNotContainsString(MetricsController::class, (string) $route->getDefault('_controller'));
        }
        self::assertSame(404, $this->request($kernel, '/health/live')->getStatusCode());
    }

    #[Test]
    public function documentedImportExposesTheRoutes(): void
    {
        $kernel = $this->bootKernel([]);
        $router = $kernel->getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        $routes = $router->getRouteCollection();

        self::assertSame('/health/live', $routes->get('nowo_health_kit_live')?->getPath());
        self::assertSame('/health/ready', $routes->get('nowo_health_kit_ready')?->getPath());
        self::assertSame('/metrics', $routes->get('nowo_health_kit_metrics')?->getPath());
        self::assertTrue($routes->get('nowo_health_kit_live')->getDefault('_stateless'));
    }
}
