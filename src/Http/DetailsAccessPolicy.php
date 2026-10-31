<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Http;

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
 */
final readonly class DetailsAccessPolicy
{
    /**
     * @param list<string> $allowedIps IPs / CIDRs allowed to see details
     * @param string|null $token bearer token granting details (null or shorter than 16 chars: disabled)
     * @param bool $public show details to everybody (dev/test only)
     * @param bool $trustForwardedIp use {@see Request::getClientIp()} (trusted proxies) instead of REMOTE_ADDR
     * @param DetailsTokenProviderInterface|null $tokenProvider runtime token source, resolved per request (replaces `$token`)
     */
    public function __construct(
        private array $allowedIps = ['127.0.0.1', '::1'],
        private ?string $token = null,
        private bool $public = false,
        private bool $trustForwardedIp = false,
        private ?DetailsTokenProviderInterface $tokenProvider = null,
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

        $ip = $this->trustForwardedIp ? $request->getClientIp() : $request->server->get('REMOTE_ADDR');
        if (is_string($ip) && $ip !== '' && $this->allowedIps !== [] && IpUtils::checkIp($ip, $this->allowedIps)) {
            return true;
        }

        return BearerToken::matches($request, BearerToken::resolve($this->token, $this->tokenProvider));
    }
}
