# Health Kit Bundle Demo (Symfony 8)

FrankenPHP (worker mode) demo of `nowo-tech/health-kit-bundle` with MySQL, Redis and a Doctrine Messenger queue.

## Usage

```bash
make up            # builds, waits for MySQL, creates the queue table, seeds 12 messages
make seed-queue    # reset the async queue to 12 waiting messages
make test          # PHPUnit (functional smoke of the imported routes)
make test-e2e      # Playwright e2e (needs the stack running)
make down
```

Open `http://localhost:8197`.

## What it shows

- `/` — the bundle endpoints called through sub-requests: readiness table (token caller), public answer, liveness
  details, Prometheus scrape and the 401 without token.
- `/health/live`, `/health/ready` — status only from your browser (Docker bridge address, not loopback); add
  `Authorization: Bearer demo-details-token-change-me` for details.
- `/metrics` — `Authorization: Bearer demo-metrics-token-change-me`.

Probes: `doctrine.default` (MySQL), `redis`, `messenger.async` (degraded: 12 messages ≥ `warn_at` 10), `frankenphp`
(live + ready) and the host-defined `disk` probe (`src/Health/DiskSpaceProbe.php`). Host metrics:
`src/Metrics/DemoMetricsProvider.php` (build info + Redis page-view counter).

## Included dev stack

- Symfony Web Profiler and debug mode (`APP_DEBUG=1` in `.env.example`)
- `nowo-tech/twig-inspector-bundle`, `nowo-tech/hot-reload-bundle`
