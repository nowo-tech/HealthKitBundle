# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

First release candidate (1.0.0), extracted and generalised from the Symfony Beacon health and metrics code.

### Added

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
