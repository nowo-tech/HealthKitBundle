<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Http;

/**
 * Runtime source of the health details bearer token (`nowo_health_kit.details.token_provider`).
 *
 * Use it instead of `details.token` when the token is editable at runtime; it is resolved per request, only when
 * the caller is not already allowed by `details.public` or `details.allowed_ips`.
 */
interface DetailsTokenProviderInterface extends TokenProviderInterface
{
}
