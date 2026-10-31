<?php

declare(strict_types=1);

namespace App\Controller;

use App\Metrics\DemoCounter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Demo page: calls the real bundle endpoints through sub-requests (as an anonymous caller, as a token holder and
 * as a Prometheus scraper) and shows their raw output side by side.
 */
final class DemoController extends AbstractController
{
    private const PUBLIC_IP = '203.0.113.10';

    public function __construct(
        private readonly HttpKernelInterface $kernel,
        private readonly DemoCounter $counter,
        #[Autowire('%env(HEALTH_DETAILS_TOKEN)%')]
        private readonly string $detailsToken,
        #[Autowire('%env(METRICS_TOKEN)%')]
        private readonly string $metricsToken,
    ) {
    }

    #[Route('/', name: 'demo_home', methods: ['GET'])]
    public function home(): Response
    {
        $this->counter->increment();

        $public   = $this->call('/health/ready', ['REMOTE_ADDR' => self::PUBLIC_IP]);
        $detailed = $this->call('/health/ready', ['REMOTE_ADDR' => self::PUBLIC_IP, 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->detailsToken]);
        $live     = $this->call('/health/live', ['REMOTE_ADDR' => self::PUBLIC_IP, 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->detailsToken]);
        $metrics  = $this->call('/metrics', ['REMOTE_ADDR' => self::PUBLIC_IP, 'HTTP_AUTHORIZATION' => 'Bearer ' . $this->metricsToken]);
        $denied   = $this->call('/metrics', ['REMOTE_ADDR' => self::PUBLIC_IP]);

        return $this->render('demo/home.html.twig', [
            'public'   => $public,
            'detailed' => $detailed,
            'live'     => $live,
            'metrics'  => $metrics,
            'denied'   => $denied,
        ]);
    }

    /**
     * @param array<string, string> $server
     *
     * @return array{status: int, type: string, body: string, decoded: mixed}
     */
    private function call(string $path, array $server): array
    {
        $response = $this->kernel->handle(Request::create($path, 'GET', server: $server), HttpKernelInterface::SUB_REQUEST, false);
        $body     = (string) $response->getContent();
        $decoded  = json_decode($body, true);

        return [
            'status'  => $response->getStatusCode(),
            'type'    => (string) $response->headers->get('Content-Type'),
            'body'    => null === $decoded ? $body : (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'decoded' => $decoded,
        ];
    }
}
