<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Http;

use Psr\Log\LoggerInterface;
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

        $provided = self::extract($request);

        return $provided !== null && hash_equals((string) $expected, $provided);
    }

    /**
     * Extracts the bearer token from the `Authorization` header (never from the query string).
     *
     * Callers use it to skip token providers entirely when the request carries no bearer token, so anonymous
     * traffic never triggers a provider lookup.
     *
     * @param Request $request incoming request
     *
     * @return string|null the non-empty token, or null when the header is missing, empty or not a Bearer scheme
     */
    public static function extract(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (!is_string($header) || strncasecmp($header, 'Bearer ', 7) !== 0) {
            return null;
        }
        $provided = trim(substr($header, 7));

        return $provided === '' ? null : $provided;
    }

    /**
     * Resolves the effective token: the provider's value when a provider is set, else the static token.
     *
     * A provider that throws is treated as "no token" (fail closed). The failure is logged as a warning with the
     * provider and exception class names only — never the message, which could contain the token.
     *
     * @param string|null $token static token (`*.token`)
     * @param TokenProviderInterface|null $provider runtime provider (`*.token_provider`)
     * @param LoggerInterface|null $logger logger for provider failures
     *
     * @return string|null the token for this request
     */
    public static function resolve(?string $token, ?TokenProviderInterface $provider, ?LoggerInterface $logger = null): ?string
    {
        if (!$provider instanceof TokenProviderInterface) {
            return $token;
        }

        try {
            return $provider->getToken();
        } catch (Throwable $e) {
            $logger?->warning('Health kit token provider "{provider}" failed ({error_class}); access denied.', [
                'provider'    => $provider::class,
                'error_class' => $e::class,
            ]);

            return null;
        }
    }
}
