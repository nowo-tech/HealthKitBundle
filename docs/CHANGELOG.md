# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

First release candidate (1.0.0), extracted and generalised from the Symfony Beacon health and metrics code.

### Security

- Security audit BX (Pass (good) / Low) remediation:
  - L1: with `details.trust_forwarded_ip` off, requests carrying `Forwarded`, `X-Forwarded-For` or `X-Real-IP` no longer get details through `details.allowed_ips` (a same-host reverse proxy made every client look like loopback); only the token grants them. Warning added to CONFIGURATION, SECURITY, USAGE and the recipe.
  - L2: anonymous readiness answers are shared through a PSR-6 pool (`ready.cache_pool`, default `cache.app`) so PHP-FPM / classic mode no longer run the probes on every anonymous request; the MySQL Doctrine probe sends a `MAX_EXECUTION_TIME` hint; `HealthChecker::checkAll()` (probe metrics) honours the group budget; DBAL / Messenger timeout configuration documented.
  - L3: token providers are only called when the request carries an `Authorization: Bearer` header (details and `/metrics`); with a provider, anonymous `/metrics` requests get 401.
  - L4: the Redis probe disconnects Predis clients with `disconnect()` (phpredis / Relay with `close()`), a failing close never masks the outcome, and owned clients are closed on every path (the Predis DSN probe used to always fail).
  - L5: `ErrorSanitizer` redacts URL userinfo up to the last `@`, scheme-less `user:pass@host`, `Bearer`/`Basic` credentials and `key=value` / `key: value` / JSON / PHP-array pairs with secret-looking keys; host probe messages and data are redacted too.
  - I1/I2: Prometheus name regexes anchored with `D`; help texts and label values scrubbed to valid UTF-8 with `\r` normalised; duplicate label sets rejected per family and dropped when merging providers. I4: token provider failures logged (class names only). I9: demo port bound to `127.0.0.1`.

### Added

- `ready.cache_pool` (default `cache.app`): PSR-6 pool for the anonymous readiness answer. New runtime requirements `psr/cache` and `symfony/polyfill-mbstring`.
- `metrics.token_provider` and `details.token_provider`: service ids of a `MetricsTokenProviderInterface` / `DetailsTokenProviderInterface` (`getToken(): ?string`, common `Http\TokenProviderInterface`) resolved on every request, for tokens editable at runtime (e.g. stored in settings). Mutually exclusive with `metrics.token` / `details.token` (configuration error when both are set). Same fail-closed rules: a null, short (< 16 chars) or throwing provider counts as no token; header-only, constant-time comparison, never logged. `BearerToken::resolve()` helper.
- `/health/live` and `/health/ready` (`HealthController`): JSON, `GET`/`HEAD`, stateless routes, `Cache-Control: no-store, private`, `X-Robots-Tag: noindex`; HTTP 200 for `ok`/`degraded`, 503 for `error`.
- Opt-in routes: `@NowoHealthKitBundle/config/routes/health.yaml` and `metrics.yaml`; controllers are registered with `autoconfigure: false` so `routing.controllers` never exposes them (REQ-SF-004 rule 7, covered by a kernel test).
- Details policy (`DetailsAccessPolicy`): status-only for public callers; probe names, durations, messages and data for `details.allowed_ips` (IPs/CIDRs, matched on `REMOTE_ADDR`, loopback by default), an `Authorization: Bearer` token (constant-time, ≥ 16 chars, never logged, query string ignored) or `details.public` (dev/test).
- Probe contract: `HealthProbeInterface`, `ProbeResult`, `HealthStatus` (`ok`/`degraded`/`error`), `#[AsHealthProbe(name, groups, critical, timeout)]`, autoconfiguration and the `nowo_health_kit.probe` tag (validated names, groups and timeouts; probes run in name order).
- `HealthChecker`: per-probe timeouts (overruns reported as errors), group budget, non-critical probes only degrade, exceptions turned into sanitized messages (DSN credentials and `password=`/`token=` redacted), PSR-3 logging without secrets, PSR-20 clock.
- Built-in probes: `doctrine.<connection>` (dummy select per DBAL connection, compiler pass), `redis` (PING through a client service or a DSN with connect/read timeouts, connection closed after each check), `messenger.<transport>` (queue depth vs `warn_at`/`fail_at`), `frankenphp` (runtime mode, worker flag, worker number, kernel reset; optional `require_worker`).
- Public readiness memo per worker (`ready.public_cache_ttl`, default 5 s).
- `/metrics` (`MetricsController`): disabled by default (404), 503 without token or roles, bearer token (constant-time) or `metrics.roles` (empty list grants nobody), 401 with `WWW-Authenticate` otherwise; `text/plain; version=0.0.4`.
- Metrics API: `MetricsProviderInterface` (autoconfigured, tag `nowo_health_kit.metrics_provider`), `MetricFamily`, `MetricSample`, `MetricType`, `MetricsCollector` (merges families, isolates failing providers, `health_kit_metrics_provider_up`), `PrometheusTextFormatter` (validated names, escaped help and label values, NaN/±Inf), `ProbeMetricsProvider` (`health_probe_status`, `health_probe_duration_seconds`, `health_probe_data`).
- Strict configuration tree with safe defaults (`nowo_health_kit`), Flex recipe, docs (installation, configuration, usage, security, architecture, worker audit, demo), Spec Kit baseline, demo `demo/symfony8` (FrankenPHP worker mode, MySQL, Redis, Doctrine Messenger queue) with Playwright e2e and WebP screenshots, CI matrix PHP 8.2–8.5 × Symfony 7.4 / 8.1 with a 100% coverage gate.
