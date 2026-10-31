# Demo with FrankenPHP

This bundle includes a runnable demo with FrankenPHP in:

- `demo/symfony8` — Symfony **8.1** on FrankenPHP **PHP 8.5** (`dunglas/frankenphp:1-php8.5-alpine`)

**PHP version policy (Symfony 8 demos):** use the newest PHP minor published in official FrankenPHP images when the demo’s `require.php` and Symfony 8 allow it (today: **8.5**). Bump the Dockerfile tag when FrankenPHP adds a newer PHP and constraints still match.

The demo uses:

- Caddy on HTTP `:80` inside the container
- **`Caddyfile`**: **worker** mode (`php_server { worker ... }`) for throughput
- **`Caddyfile.dev`**: classic `php_server` (**no worker**) — hot-reload friendly

Runtime selection is via **`FRANKENPHP_MODE`** (`classic` | `worker`), not `APP_ENV`. Compose still sets **`APP_ENV=dev`** / **`APP_DEBUG=1`** and mounts **`docker/php-dev.ini`**.

## Table of contents

- [Quick start](#quick-start)
- [Switching classic vs worker (`FRANKENPHP_MODE`)](#switching-classic-vs-worker-frankenphp_mode)
- [Worker mode and kernel reset](#worker-mode-and-kernel-reset)
- [Demo services and data](#demo-services-and-data)
- [Timeout hierarchy](#timeout-hierarchy)
- [Development stack in demos](#development-stack-in-demos)
- [Troubleshooting](#troubleshooting)
- [Demo smoke (REQ-TEST-011)](#demo-smoke-req-test-011)
- [Playwright e2e and screenshots (REQ-DEMO-013)](#playwright-e2e-and-screenshots-req-demo-013)

## Quick start

From the bundle root:

```bash
make -C demo up-symfony8
```

Then open:

- Symfony 8.1: `http://localhost:8197`

## Switching classic vs worker (`FRANKENPHP_MODE`)

| Value | Behaviour |
| --- | --- |
| **`worker`** (default) | Use the worker Caddyfile |
| **`classic`** | Entrypoint copies `Caddyfile.dev` (plain `php_server`) |

Set in `.env` / `.env.example`. Compose passes `FRANKENPHP_MODE=${FRANKENPHP_MODE:-worker}` into the PHP service. After changing `.env`, run `docker compose up -d` (or `make up`) so the container is **recreated** — a plain `restart` does not reload env. No image rebuild is required.

## Worker mode and kernel reset

This package is audited as safe when FrankenPHP keeps the Symfony kernel warm **without** resetting it between requests: services are immutable except the per-worker memo of the anonymous readiness status. Full checklist: [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). In worker mode the `frankenphp` probe reports `"frankenphp_worker": true` — check it with `curl -s http://127.0.0.1/health/live` inside the container (loopback → details).

## Demo services and data

| Service | Image | Purpose |
| --- | --- | --- |
| `php` | `dunglas/frankenphp:1-php8.5-alpine` (+ `pdo_mysql`, `redis`) | Symfony 8.1 app on port `${PORT:-8197}`; Docker healthcheck `curl /health/live` |
| `database` | `mysql:8.4` (no host port, REQ-DEMO-006) | `doctrine.default` probe and the Doctrine Messenger `async` transport |
| `redis` | `redis:7-alpine` (no host port) | `redis` probe and the demo page-view counter |

`make up` waits for MySQL, creates the Messenger table and runs `app:demo:seed-queue --count=12`: twelve messages wait on
`async` (no worker consumes them), so `messenger.async` is `degraded` (`warn_at: 10`) and readiness answers
`degraded` with HTTP 200. `make seed-queue` resets it. The demo page (`/`) calls the real endpoints through
sub-requests as a public caller, a token caller and a scraper. Demo tokens are placeholders from `.env.example`.

## Timeout hierarchy

REQ-RUNTIME-001: the innermost deadline fires first.

| Layer | Demo value |
| --- | --- |
| Redis probe connect/read timeout | 0.5 s (`probes.redis.timeout`) |
| DBAL connect timeout | 2 s (`PDO::ATTR_TIMEOUT` in `config/packages/doctrine.yaml`) |
| Health group budget | 5 s (`nowo_health_kit.budget`, default) |
| PHP `max_execution_time` / `max_input_time` | 20 s (Caddyfile `php_ini`) |
| Caddy `servers.timeouts.write` | 30 s |
| FrankenPHP `max_wait_time` | 10 s (wait for a free PHP thread) |

## Development stack in demos

The demo includes:

- **Symfony Debug** (`symfony/debug-bundle`)
- **Symfony Web Profiler** (`symfony/web-profiler-bundle`)
- **Doctrine DBAL + Messenger** (MySQL) and **ext-redis** for the probes
- **Nowo Twig Inspector** (`nowo-tech/twig-inspector-bundle`) and **Nowo Hot Reload** (`nowo-tech/hot-reload-bundle`) — required together on FrankenPHP demos (dev/test only; Caddyfile Mercure + `hot_reload`, plus `worker { watch }` in worker mode). Do not enable Hot Reload in production.
- **`APP_DEBUG=1`** in `.env.example`

Example `config/bundles.php`:

```php
<?php

declare(strict_types=1);

return [
    Symfony\Bundle\FrameworkBundle\FrameworkBundle::class     => ['all' => true],
    Symfony\Bundle\TwigBundle\TwigBundle::class               => ['all' => true],
    Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class      => ['all' => true],
    Symfony\Bundle\DebugBundle\DebugBundle::class             => ['dev' => true, 'test' => true],
    Symfony\Bundle\WebProfilerBundle\WebProfilerBundle::class => ['dev' => true, 'test' => true],
    Nowo\TwigInspectorBundle\NowoTwigInspectorBundle::class   => ['dev' => true, 'test' => true],
    Nowo\HealthKitBundle\NowoHealthKitBundle::class   => ['all' => true],
];
```

## Troubleshooting

- If app does not respond, run `make -C demo/symfony8 logs`.
- If routes/config changed, run `make -C demo/symfony8 cache-clear`.
- If dependencies are outdated, run `make -C demo/symfony8 update-bundle`.
- `doctrine.default` in error right after `make up`: MySQL is still initialising; `make -C demo/symfony8 setup` waits for it.
- `redis` in error: check `docker compose ps` — the probe answers within 0.5 s even when Redis is down.

## Demo smoke (REQ-TEST-011)

Boot the FrankenPHP demo, expect **HTTP 200** on the published port, then tear down:

```bash
make demo-smoke
# or: make -C demo demo-smoke
```

Smoke URL: `http://127.0.0.1:8197` (Symfony 8.1 demo). This target is also part of `make -C demo release-check` / root `make release-check`.

## Playwright e2e and screenshots (REQ-DEMO-013)

With the demo running:

```bash
make test-e2e            # or: make -C demo/symfony8 test-e2e
make demo-screenshots    # refreshes docs/images/demo/*.webp
```

Both run in the official Playwright image (`mcr.microsoft.com/playwright`, host network, one worker) and reseed the
queue first. The specs live in `demo/symfony8/e2e/tests/`: `demo.spec.ts` (overview table, public vs token answers,
fail-closed `/metrics`) and `screenshots.spec.ts` (two WebP captures with masthead, bundle output and the web debug
toolbar; PNGs are converted with `sharp` and deleted).
