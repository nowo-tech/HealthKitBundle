<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke test of the imported bundle routes (no database needed for liveness).
 */
final class HealthEndpointsTest extends WebTestCase
{
    public function testLivenessAnswersStatusOnlyToPublicCallers(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health/live', server: ['REMOTE_ADDR' => '203.0.113.10']);

        self::assertResponseIsSuccessful();
        $payload = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame('ok', $payload['status']);
        self::assertArrayNotHasKey('checks', $payload);
    }

    public function testMetricsRequireTheToken(): void
    {
        $client = self::createClient();
        $client->request('GET', '/metrics', server: ['REMOTE_ADDR' => '203.0.113.10']);

        self::assertResponseStatusCodeSame(401);
    }
}
