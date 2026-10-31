<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle;

use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Liveness and readiness endpoints with pluggable probes (Doctrine, Redis, Messenger queue depth, FrankenPHP) and a token-guarded Prometheus /metrics endpoint for Symfony apps.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoHealthKitBundle extends AbstractBundle
{
}
