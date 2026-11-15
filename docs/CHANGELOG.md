# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- `ErrorSanitizer::redact()` and `redactPersonalData()` bound the regex input; the key/value pattern is quadratic, so 100 KB of text took about 17 s (audit QH-D5). Longer text is redacted on a 2,512-byte window and only the redacted result is cut to 2,000 bytes (back to the last whitespace, or past the trailing token when there is none, on UTF-8 boundaries), so a credential next to the cut keeps the terminator its pattern needs (URL `@`, closing JSON quote) and is not shown unredacted or in part (audit QH-D6), except for the case under Known issues (QH-D7). Every caller (`sanitize()`, `redactData()`, probe messages and the health warning log, the recycle reason) is covered.

- `runtime.worker_recycle` hardening (audit QH): `WorkerRecycleException` has a constant message (the reason is logged once, at `log_level`, never carried by the exception that Symfony re-logs); the reason is redacted for emails and IP addresses as well as credentials (`ErrorSanitizer::redactPersonalData()`); the state-pool item expires after 30 days; broad `exception_classes` (`Throwable`, `Exception`, `RuntimeException`, `LogicException`, `HttpExceptionInterface`, DBAL `DriverException`...) are refused unless `allow_broad_exception_classes: true`; worker mode needs `worker=` with a value other than 0; a missing `state_pool` service fails the container build with an `InvalidConfigurationException`; broad-class guard also covers `HttpExceptionInterface` / DBAL `ConstraintViolationException` subclasses; IP addresses followed by `:port` or preceded by `:` are redacted. Docs: listeners below -8192 do not run on a recycled request, and the recycle is an uncaught exception (exit 255, CRITICAL log) to filter in Sentry/BeaconBundle.

- `runtime` section: opt-in FrankenPHP worker guards, everything off by default (no change for existing hosts). Requested by Symfony Beacon (re-audit 2026-11-11, item 6) to replace its `src/Shared/Runtime`.
  - `runtime.worker_recycle`: a request that fails on a lost critical connection (`redis: true` covers `RedisException`, `RedisClusterException`, `Relay\Exception`, `Predis\Connection\ConnectionException`; plus `exception_classes`, matched with `instanceof` along the previous chain) marks the worker. On `kernel.terminate` (priority -8192, after the response is sent) `WorkerRecycleListener` throws `WorkerRecycleException`, which Symfony's `FrankenPhpWorkerRunner` rethrows to end the worker script, so FrankenPHP restarts it with fresh connections. It only throws when `APP_RUNTIME_MODE` has `worker=` with a value other than 0, so it is a no-op under PHP-FPM, classic mode, CLI and tests. Port `Runtime\WorkerRecyclerInterface::requestRecycle()` for host code that catches the failure itself. Logged on the `health` channel (`log_level`, default `critical`; exception class or redacted reason only).
  - `runtime.worker_recycle.state_pool`: optional PSR-6 pool recording recycles; `/metrics` gains `health_kit_worker_recycle_enabled`, `health_kit_worker_recycle_persistent`, `health_kit_worker_recycles_total` and `health_kit_worker_recycle_last_timestamp_seconds`; optional `runtime.worker_recycle` probe (`probe.enabled`, non-critical, `ready` group) degraded for `recent_window` seconds (default 300) after a recycle.
  - `runtime.dev_collector_reset`: clears the profiler form collector's per-form data (`dataByForm`, `dataByView`, `formsByView`) on `kernel.terminate` (priority -4096) and `kernel.reset`, so it no longer grows in a kept-alive dev worker.

### Known issues

- Audit QH-D7 (Low): in probe messages and string probe data longer than 2,000 bytes with no whitespace in the kept part, a credential longer than about 512 bytes that contains `;`, `,`, `&`, `|` or a quote, starts before the cut and has its terminator past the 2,512-byte window, can show its user name and the start of its password to details callers and in the health warning log. The code fix is planned for 1.3.x: redact the kept text again with a synthetic terminator and cut back before the first byte that changes.
- Audit QH-I6 (Info): text of 2,000 bytes or less is not truncated, and redaction can make it slightly longer (`a://b@` becomes `a://***@`).

### Tests

- Kept-alive kernel test for the worker recycle (1,200 requests, mark never leaks, memory flat) and a 300-form collector reset test; `docs/FRANKENPHP-WORKER-AUDIT.md` updated (REQ-RUNTIME-002).

### Changed

- Probe messages and string probe data longer than 2,000 bytes are now truncated, for every probe, and end with a visible `…[truncated]` marker (audit QH-I5). Status, durations, numeric data and `/metrics` are unchanged.
- docs: demo screenshots regenerated on the UiKit base style
- demo: opt into UiKit 2.51 `nowo_ui_kit.base_stylesheet`, use `nowo-ui-header__nav` for the masthead nav, screenshots regenerated with the themed shell

## [1.2.0] - 2026-11-13

### Added

- Ports (REQ-ARCH-001 rule 1): `HealthCheckerInterface` and `MetricsCollectorInterface`, implemented by `HealthChecker` and `MetricsCollector` and aliased in the container; the controllers and `ProbeMetricsProvider` depend on them, so a host can replace either by overriding the alias. The old class ids still resolve (additive).
- `docs/ARCHITECTURE.md`: public ports and a "Standard Symfony and DDD hosts" section.

### Changed

- Demo uses the UiKit base style (REQ-DEMO-014)

### Tests

- Kept-alive kernel test (1,200 requests, two callers, memory bound) and refreshed `docs/FRANKENPHP-WORKER-AUDIT.md` (REQ-RUNTIME-002).

## [1.1.0] - 2026-11-06

`ready.public` / `live.public` (default true): when false, callers not allowed by the details rules get a 404 indistinguishable from an unknown URL, and no probe runs.

### Added

- `ready.public` and `live.public` (bool, default `true` = unchanged behaviour). With `false`, a caller that the
  details rules do not allow (`details.allowed_ips` with the forwarding-header rule, `details.token` /
  `details.token_provider`, `details.public`) gets the same 404 as an unknown URL (`NotFoundHttpException`, router
  message shape): no probe runs and the anonymous readiness memo / `ready.cache_pool` are neither read nor written for
  them. Allowed callers get the detailed report. `Http\HiddenEndpoint` builds the 404 (outer and previous messages as
  the router's); `EventListener\HiddenEndpointListener` (registered only when an endpoint is non-public) throws it at
  `kernel.request` priority 31 for requests routed to a hidden endpoint (bundle route or `HealthController` action,
  trailing-slash 301 redirect included) and turns the router's 405 into it after replaying the path as a GET (so
  `%`-encoded paths are covered). Requested by Symfony Beacon (readiness kept off the internet).

### Changed

- `HealthController::__construct()` takes two trailing optional arguments `$livePublic` and `$readyPublic` (default
  `true`); `/health/live` now evaluates the details policy before running the `live` probes (same responses).

## [1.0.0] - 2026-10-31

First release: liveness/readiness endpoints with pluggable probes (Doctrine, Redis, Messenger, FrankenPHP), allowlist/token-gated details, token-guarded Prometheus /metrics, runtime token providers, extracted and generalised from the Symfony Beacon health and metrics code. See [UPGRADING](https://github.com/nowo-tech/HealthKitBundle/blob/main/docs/UPGRADING.md#to-100).

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
