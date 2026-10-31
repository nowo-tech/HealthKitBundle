<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Runtime;

use Nowo\HealthKitBundle\Runtime\FrankenPhpRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

use const PHP_SAPI;

#[CoversClass(FrankenPhpRuntime::class)]
final class FrankenPhpRuntimeTest extends TestCase
{
    /**
     * @param array<string, mixed> $server
     *
     * @return array<string, mixed>
     */
    private function snapshot(array $server): array
    {
        $stack = new RequestStack([new Request(server: $server)]);

        return (new FrankenPhpRuntime($stack, 'frankenphp'))->snapshot();
    }

    #[Test]
    public function workerModeSignals(): void
    {
        self::assertSame([
            'sapi'              => 'frankenphp',
            'frankenphp_mode'   => 'worker',
            'frankenphp_worker' => true,
            'reset_kernel'      => true,
            'app_runtime_mode'  => 'web=1&worker=1',
            'worker_num'        => 4,
        ], $this->snapshot([
            'FRANKENPHP_MODE'         => ' Worker ',
            'FRANKENPHP_WORKER'       => '1',
            'FRANKENPHP_RESET_KERNEL' => 'yes',
            'APP_RUNTIME_MODE'        => 'web=1&worker=1',
            'FRANKENPHP_WORKER_NUM'   => '4',
        ]));
    }

    #[Test]
    public function unknownOrMissingValuesFallBackToClassic(): void
    {
        $snapshot = $this->snapshot([
            'FRANKENPHP_MODE'       => 'turbo',
            'FRANKENPHP_WORKER'     => ['array'],
            'APP_RUNTIME_MODE'      => '',
            'FRANKENPHP_WORKER_NUM' => '4',
        ]);

        self::assertSame('classic', $snapshot['frankenphp_mode']);
        self::assertFalse($snapshot['frankenphp_worker']);
        self::assertFalse($snapshot['reset_kernel']);
        self::assertNull($snapshot['app_runtime_mode']);
        self::assertNull($snapshot['worker_num']);
    }

    #[Test]
    public function nonNumericWorkerNumIsIgnored(): void
    {
        self::assertNull($this->snapshot(['FRANKENPHP_MODE' => 'worker', 'FRANKENPHP_WORKER_NUM' => 'x'])['worker_num']);
    }

    #[Test]
    public function fallsBackToTheProcessEnvironment(): void
    {
        $env      = static fn (string $key): string|false => $key === 'FRANKENPHP_MODE' ? 'worker' : false;
        $snapshot = (new FrankenPhpRuntime(null, PHP_SAPI, $env))->snapshot();

        self::assertSame('worker', $snapshot['frankenphp_mode']);
        self::assertSame(PHP_SAPI, $snapshot['sapi']);
        self::assertSame('classic', (new FrankenPhpRuntime(new RequestStack()))->snapshot()['frankenphp_mode']);
    }
}
