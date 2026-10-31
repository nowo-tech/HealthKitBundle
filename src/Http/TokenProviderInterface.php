<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Http;

/**
 * Supplies a bearer token at request time (e.g. read from editable application settings).
 *
 * Called once per request that needs the token; the bundle never caches the value across requests. Return null (or a
 * token shorter than {@see BearerToken::MIN_LENGTH} characters) when no token is configured: access then fails closed.
 * Implementations must not log the token and should keep their own lookups cheap (one cached settings read).
 */
interface TokenProviderInterface
{
    /**
     * Returns the current token.
     *
     * @return string|null the token, or null when none is configured
     */
    public function getToken(): ?string;
}
