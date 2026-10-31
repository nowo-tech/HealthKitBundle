<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\HealthKitBundle\NowoHealthKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function sys_get_temp_dir;

/**
 * Minimal kernel for integration tests; configuration and routes are injected per test.
 */
final class HealthKitTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param Closure(ContainerConfigurator): void $services services/extension configuration
     * @param Closure(RoutingConfigurator): void $routes routes
     */
    public function __construct(
        private readonly string $id,
        private readonly Closure $services,
        private readonly Closure $routes,
        private readonly bool $withDoctrine = false,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->withDoctrine) {
            yield new DoctrineBundle();
        }
        yield new NowoHealthKitBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/nowo-health-kit-tests/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/nowo-health-kit-tests/' . $this->id . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'test-secret',
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
        ]);
        $container->services()->set('logger', NullLogger::class);
        ($this->services)($container);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        ($this->routes)($routes);
    }
}
