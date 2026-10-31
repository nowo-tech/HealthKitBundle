<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Http;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * Decides whether a health request may see details (probe names, durations, messages, runtime data).
 *
 * Callers get details when one of these holds; everybody else gets the status only:
 * 1. `details.public` is true (meant for dev/test only);
 * 2. the peer address is inside `details.allowed_ips` (IPs or CIDRs; default loopback only);
 * 3. the request carries `Authorization: Bearer <token>` (constant-time compare), the token being `details.token` or,
 *    when configured, the value returned per request by `details.token_provider`.
 *
 * The peer address is `REMOTE_ADDR` (the TCP peer), not `X-Forwarded-For`, unless `details.trust_forwarded_ip` is
 * enabled: a reverse proxy client must not be able to claim a loopback address.
 *
 * Proxied requests (audit BX L1): with `trust_forwarded_ip` off, a request carrying `Forwarded`, `X-Forwarded-For` or
 * `X-Real-IP` came through a proxy, so `REMOTE_ADDR` is the proxy (often loopback on the same host/pod), not the
 * client. The IP allowlist is then ignored; only the token can grant details.
 *
 * Token providers are only called when the request carries a bearer token (audit BX L3).
 */
final readonly class DetailsAccessPolicy
{
    /** @var list<string> */
    public const FORWARDING_HEADERS = ['Forwarded', 'X-Forwarded-For', 'X-Real-IP'];

    /**
     * @param list<string> $allowedIps IPs / CIDRs allowed to see details
     * @param string|null $token bearer token granting details (null or shorter than 16 chars: disabled)
     * @param bool $public show details to everybody (dev/test only)
     * @param bool $trustForwardedIp use {@see Request::getClientIp()} (trusted proxies) instead of REMOTE_ADDR
     * @param DetailsTokenProviderInterface|null $tokenProvider runtime token source, resolved per request (replaces `$token`)
     * @param LoggerInterface|null $logger logger for token provider failures (class names only)
     */
    public function __construct(
        private array $allowedIps = ['127.0.0.1', '::1'],
        private ?string $token = null,
        private bool $public = false,
        private bool $trustForwardedIp = false,
        private ?DetailsTokenProviderInterface $tokenProvider = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Whether the request may see details.
     *
     * @param Request $request incoming request
     *
     * @return bool true when details are allowed
     */
    public function allows(Request $request): bool
    {
        if ($this->public) {
            return true;
        }

        if ($this->allowedIps !== [] && ($this->trustForwardedIp || !self::isProxied($request))) {
            $ip = $this->trustForwardedIp ? $request->getClientIp() : $request->server->get('REMOTE_ADDR');
            if (is_string($ip) && $ip !== '' && IpUtils::checkIp($ip, $this->allowedIps)) {
                return true;
            }
        }

        if (BearerToken::extract($request) === null) {
            return false;
        }

        return BearerToken::matches($request, BearerToken::resolve($this->token, $this->tokenProvider, $this->logger));
    }

    /**
     * Whether the request carries proxy forwarding headers.
     *
     * @param Request $request incoming request
     *
     * @return bool true when `Forwarded`, `X-Forwarded-For` or `X-Real-IP` is present
     */
    public static function isProxied(Request $request): bool
    {
        foreach (self::FORWARDING_HEADERS as $header) {
            if ($request->headers->has($header)) {
                return true;
            }
        }

        return false;
    }
}
