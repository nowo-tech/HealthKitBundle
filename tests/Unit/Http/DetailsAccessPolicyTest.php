<?php

declare(strict_types=1);

namespace Nowo\HealthKitBundle\Tests\Unit\Http;

use Nowo\HealthKitBundle\Http\DetailsAccessPolicy;
use Nowo\HealthKitBundle\Tests\Fixtures\MutableTokenProvider;
use Nowo\HealthKitBundle\Tests\Fixtures\RecordingLogger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use const JSON_THROW_ON_ERROR;

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

    #[Test]
    public function tokenProviderIsResolvedPerRequest(): void
    {
        $provider = new MutableTokenProvider(self::TOKEN);
        $policy   = new DetailsAccessPolicy([], null, false, false, $provider);
        $request  = $this->request('203.0.113.9', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN]);

        self::assertTrue($policy->allows($request));
        $provider->token = 'rotated-token-REDACTED-TEST';
        self::assertFalse($policy->allows($request));
        $provider->token = null;
        self::assertFalse($policy->allows($request));
        self::assertSame(3, $provider->calls);

        // Allowlisted callers never hit the provider.
        self::assertTrue((new DetailsAccessPolicy(['127.0.0.1'], null, false, false, $provider))->allows($this->request('127.0.0.1')));
        self::assertSame(3, $provider->calls);
    }

    #[Test]
    public function proxiedRequestsCannotUseTheIpAllowlist(): void
    {
        $policy = new DetailsAccessPolicy(['127.0.0.1', '::1'], self::TOKEN);

        foreach (['HTTP_X_FORWARDED_FOR' => '203.0.113.7', 'HTTP_FORWARDED' => 'for=203.0.113.7', 'HTTP_X_REAL_IP' => '203.0.113.7'] as $header => $value) {
            self::assertFalse($policy->allows($this->request('127.0.0.1', [$header => $value])), $header);
            self::assertTrue($policy->allows($this->request('127.0.0.1', [$header => $value, 'HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN])), $header);
        }
        self::assertTrue($policy->allows($this->request('127.0.0.1')));
        self::assertFalse(DetailsAccessPolicy::isProxied($this->request('127.0.0.1')));
    }

    #[Test]
    public function tokenProviderIsNotCalledWithoutBearerHeader(): void
    {
        $provider = new MutableTokenProvider(self::TOKEN);
        $policy   = new DetailsAccessPolicy([], null, false, false, $provider);

        for ($i = 0; $i < 5; ++$i) {
            self::assertFalse($policy->allows($this->request('203.0.113.9')));
            self::assertFalse($policy->allows($this->request('203.0.113.9', ['HTTP_AUTHORIZATION' => 'Basic abc'])));
        }
        self::assertSame(0, $provider->calls);
    }

    #[Test]
    public function throwingProviderIsLoggedWithoutTheToken(): void
    {
        $logger = new RecordingLogger();
        $policy = new DetailsAccessPolicy([], null, false, false, new MutableTokenProvider(self::TOKEN, true), $logger);

        self::assertFalse($policy->allows($this->request('203.0.113.9', ['HTTP_AUTHORIZATION' => 'Bearer ' . self::TOKEN])));
        self::assertCount(1, $logger->records);
        self::assertSame(MutableTokenProvider::class, $logger->records[0]['context']['provider']);
        self::assertStringNotContainsString(self::TOKEN, json_encode($logger->records, JSON_THROW_ON_ERROR));
    }
}
