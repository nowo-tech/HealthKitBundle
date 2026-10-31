<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Integration\Kernel;

use Closure;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function count;
use function sys_get_temp_dir;

use const JSON_THROW_ON_ERROR;

/**
 * Boots a fresh {@see HealthKitTestKernel} per test (cache directory wiped first).
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<HealthKitTestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(sys_get_temp_dir() . '/nowo-health-kit-tests');
    }

    /**
     * @param array<string, mixed> $config nowo_health_kit configuration
     * @param array<string, mixed> $extra other extension configs (extension => config)
     * @param Closure(RoutingConfigurator): void|null $routes routes (default: import health + metrics)
     * @param array<string, string> $services extra service id => class (autoconfigured)
     */
    protected function bootKernel(array $config, array $extra = [], ?Closure $routes = null, array $services = [], bool $withDoctrine = false): HealthKitTestKernel
    {
        $id     = static::class . '_' . $this->name() . '_' . count($this->kernels);
        $id     = (string) preg_replace('/\W+/', '_', $id);
        $kernel = new HealthKitTestKernel(
            $id,
            static function ($container) use ($config, $extra, $services): void {
                $container->extension('nowo_health_kit', $config);
                foreach ($extra as $name => $values) {
                    $container->extension($name, $values);
                }
                $defaults = $container->services()->defaults()->autowire()->autoconfigure();
                foreach ($services as $serviceId => $class) {
                    $defaults->set($serviceId, $class);
                }
            },
            $routes ?? static function (RoutingConfigurator $routes): void {
                $routes->import('@NowoHealthKitBundle/config/routes/health.yaml');
                $routes->import('@NowoHealthKitBundle/config/routes/metrics.yaml');
            },
            $withDoctrine,
        );
        $kernel->boot();
        $this->kernels[] = $kernel;

        return $kernel;
    }

    /**
     * @param array<string, string> $server extra server parameters
     */
    protected function request(HealthKitTestKernel $kernel, string $uri, array $server = []): Response
    {
        $request  = Request::create($uri, 'GET', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', ...$server]);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
