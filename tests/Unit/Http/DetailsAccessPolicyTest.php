<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Http;

use Nowo\HealthKitBundle\Http\DetailsAccessPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

#[CoversClass(DetailsAccessPolicy::class)]
final class DetailsAccessPolicyTest extends TestCase
{
    private const TOKEN = 'details-REDACTED-TEST-token';

    /**
     * @param array<string, string> $server
     */
    private function request(string $ip, array $server = []): Request
    {
        return Request::create('/health/ready', server: ['REMOTE_ADDR' => $ip, ...$server]);
    }

    #[Test]
    public function defaultsAllowLoopbackOnly(): void
    {
        $policy = new DetailsAccessPolicy();

        self::assertTrue($policy->allows($this->request('127.0.0.1')));
        self::assertTrue($policy->allows($this->request('::1')));
        self::assertFalse($policy->allows($this->request('203.0.113.9')));
        self::assertFalse($policy->allows($this->request('')));
    }

    #[Test]
    public function cidrAllowlistAndToken(): void
    {
        $policy = new DetailsAccessPolicy(['10.0.0.0/8'], self::TOKEN);

        self::assertTrue($policy->allows($this->request('10.20.30.40')));
        self::assertFalse($policy->allows($this->request('127.0.0.1')));
        self::assertTrue($policy->allows($this->request('203.0.113.9', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN])));
        self::assertFalse((new DetailsAccessPolicy([], self::TOKEN))->allows($this->request('127.0.0.1')));
    }

    #[Test]
    public function forwardedHeadersAreIgnoredUnlessTrusted(): void
    {
        Request::setTrustedProxies(['192.0.2.1'], Request::HEADER_X_FORWARDED_FOR);
        try {
            $spoofed = $this->request('192.0.2.1', ['HTTP_X_FORWARDED_FOR' => '127.0.0.1']);
            self::assertFalse((new DetailsAccessPolicy())->allows($spoofed));
            self::assertTrue((new DetailsAccessPolicy(trustForwardedIp: true))->allows($spoofed));
        } finally {
            Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
        }
    }

    #[Test]
    public function publicModeAllowsEverybody(): void
    {
        self::assertTrue((new DetailsAccessPolicy([], null, true))->allows($this->request('203.0.113.9')));
    }
}
