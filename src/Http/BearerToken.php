<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Http;

use Symfony\Component\HttpFoundation\Request;
use Throwable;

use function hash_equals;
use function is_string;
use function strlen;
use function strncasecmp;
use function substr;
use function trim;

/**
 * Bearer token check shared by the health details and the metrics endpoint.
 *
 * - Only the `Authorization: Bearer <token>` header is read; query strings are ignored (they leak into access logs,
 *   proxies and Referer headers).
 * - The comparison is constant-time ({@see hash_equals()}).
 * - A configured token shorter than {@see self::MIN_LENGTH} characters is treated as not configured (fail closed).
 * - The token is never logged or echoed.
 */
final class BearerToken
{
    public const MIN_LENGTH = 16;

    /**
     * Whether the configured token is usable.
     *
     * @param string|null $expected configured token
     *
     * @return bool true when it is at least {@see self::MIN_LENGTH} characters long
     */
    public static function isConfigured(?string $expected): bool
    {
        return $expected !== null && strlen($expected) >= self::MIN_LENGTH;
    }

    /**
     * Whether the request carries the expected bearer token.
     *
     * @param Request $request incoming request
     * @param string|null $expected configured token (unusable tokens never match)
     *
     * @return bool true on a constant-time match
     */
    public static function matches(Request $request, ?string $expected): bool
    {
        if (!self::isConfigured($expected)) {
            return false;
        }

        $header = $request->headers->get('Authorization');
        if (!is_string($header) || strncasecmp($header, 'Bearer ', 7) !== 0) {
            return false;
        }
        $provided = trim(substr($header, 7));

        return $provided !== '' && hash_equals((string) $expected, $provided);
    }

    /**
     * Resolves the effective token: the provider's value when a provider is set, else the static token.
     *
     * A provider that throws is treated as "no token" (fail closed); the exception is swallowed without logging
     * anything that could contain the token.
     *
     * @param string|null $token static token (`*.token`)
     * @param TokenProviderInterface|null $provider runtime provider (`*.token_provider`)
     *
     * @return string|null the token for this request
     */
    public static function resolve(?string $token, ?TokenProviderInterface $provider): ?string
    {
        if (!$provider instanceof TokenProviderInterface) {
            return $token;
        }

        try {
            return $provider->getToken();
        } catch (Throwable) {
            return null;
        }
    }
}
