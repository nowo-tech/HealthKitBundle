# Feature Specification: HealthKitBundle baseline (100% code coverage)

**Feature Branch**: `001-baseline`  
**Created**: 2026-10-31  
**Status**: Active  

**Package**: `nowo-tech/health-kit-bundle`  
**Configuration root**: `nowo_health_kit`  
**Code inventory**: [`code-inventory.md`](code-inventory.md)  
**Integrator spec**: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md)

---

## Summary

The bundle gives Symfony apps liveness (`/health/live`) and readiness (`/health/ready`) endpoints backed by tagged,
timed probes (Doctrine DBAL per connection, Redis, Messenger queue depth, FrankenPHP runtime, host probes), and a
token-guarded Prometheus `/metrics` endpoint aggregating tagged metrics providers. Routes are opt-in, public callers
only get a status, and every service is safe in FrankenPHP worker mode.

---

## User Scenarios

### US-1 — Orchestrator readiness (P1)

**Given** the host imported `config/routes/health.yaml` and enabled the Doctrine probe, **When** the database answers
`SELECT 1`, **Then** `GET /health/ready` answers 200 `{"status":"ok"}`; **When** the database is down, **Then** it
answers 503 `{"status":"error"}`.

### US-2 — Status only for the public (P1)

**Given** a caller outside `details.allowed_ips` without a token, **When** it calls `/health/ready`, **Then** the body
is exactly `{"status": …}`, the answer is memoised for `ready.public_cache_ttl` seconds, and `Cache-Control` contains
`no-store`.

### US-3 — Details for operators (P1)

**Given** a loopback caller, an allowlisted CIDR or `Authorization: Bearer <details.token>`, **When** it calls a health
endpoint, **Then** the body lists every probe with status, criticality, duration, message and data. A `?token=` query
string never grants details.

### US-4 — Degraded but serving (P2)

**Given** a Messenger transport above `warn_at` or a failing non-critical probe, **When** readiness runs, **Then** the
status is `degraded` with HTTP 200.

### US-5 — Slow dependency (P1)

**Given** a probe that exceeds its timeout or a group whose budget is spent, **When** readiness runs, **Then** the
overrunning or skipped critical probe is `error` and the answer is 503, without leaking exception secrets.

### US-6 — Prometheus scrape (P1)

**Given** `metrics.enabled: true` and a ≥ 16-char token, **When** a scraper sends the bearer token, **Then**
`/metrics` answers Prometheus text 0.0.4 with the host providers and the probe gauges; without token it answers 401,
with no token nor role configured 503, disabled 404.

### US-7 — Opt-in routes (P1)

**Given** a host whose routing is only `routing.controllers`, **When** the container is built, **Then** no bundle route
exists.

### US-8 — Custom probe (P2)

**Given** a service implementing `HealthProbeInterface` with `#[AsHealthProbe]`, **When** the container compiles,
**Then** it runs in its groups with its timeout and criticality; invalid names, groups, timeouts or duplicate names
fail the compilation.

---

## Functional Requirements

### Bundle and DI

- **FR-BUNDLE-001**: The bundle class registers `DoctrineProbePass` (priority 10) and `HealthProbePass` (priority 0), exposes the `nowo_health_kit` extension and reports the package root as its path.
- **FR-CFG-001**: Strict configuration tree with safe defaults (details for loopback, no tokens, only FrankenPHP probe, metrics disabled); invalid IPs/CIDRs, thresholds, groups, timeouts, Redis target and empty Messenger transports fail with the path in the message.
- **FR-CFG-002**: The extension registers the checker, policy, runtime reader, built-in Redis/Messenger/FrankenPHP probes, metrics services and both controllers with `autoconfigure: false` + `controller.service_arguments`.
- **FR-CFG-003**: Autoconfiguration: `HealthProbeInterface` → `nowo_health_kit.probe`; `#[AsHealthProbe]` → tag with attributes; `MetricsProviderInterface` → `nowo_health_kit.metrics_provider`.
- **FR-CFG-004**: `DoctrineProbePass` registers `doctrine.<name>` per DBAL connection (all or the configured list) and fails on unknown names or missing DoctrineBundle.
- **FR-CFG-005**: `HealthProbePass` validates probe tags, defaults name/groups/critical/timeout, rejects duplicates, sorts probes by name and wires the clock (`clock` or `NativeClock`).
- **FR-ROUTE-001**: `config/routes/health.yaml` and `config/routes/metrics.yaml` import the controller attribute routes; nothing else exposes them.

### HTTP

- **FR-HTTP-001**: `/health/live` (`nowo_health_kit_live`) runs the `live` group; adds `version` and `client_ip` when configured.
- **FR-HTTP-002**: `/health/ready` (`nowo_health_kit_ready`) runs the `ready` group; public answers are reused for `ready.public_cache_ttl` via a per-worker memo and the `ready.cache_pool` PSR-6 pool shared across processes (0 disables both; a failing pool is ignored).
- **FR-HTTP-003**: Health responses: JSON, GET/HEAD, stateless, `no-store, private`, `X-Robots-Tag: noindex`; 503 only on `error`.
- **FR-HTTP-004**: `DetailsAccessPolicy` grants details for `details.public`, `REMOTE_ADDR` (or `getClientIp()` with `trust_forwarded_ip`) in `allowed_ips` — never for requests carrying `Forwarded` / `X-Forwarded-For` / `X-Real-IP` when `trust_forwarded_ip` is off — or a matching bearer token.
- **FR-HTTP-005**: `BearerToken` reads only `Authorization: Bearer`, compares with `hash_equals()`, ignores tokens shorter than 16 chars.
- **FR-HTTP-007**: `details.token_provider` / `metrics.token_provider` (`DetailsTokenProviderInterface`, `MetricsTokenProviderInterface`, both `TokenProviderInterface`) are resolved per request by `BearerToken::resolve()` only when an `Authorization: Bearer` header is present (`BearerToken::extract()`), exclusive with the static token; null, short or throwing providers fail closed and failures are logged with class names only.
- **FR-HTTP-006**: `/metrics` (`nowo_health_kit_metrics`): 404 disabled, 503 unconfigured, 401 + `WWW-Authenticate` unauthorized, 200 text 0.0.4 with token or a granted role (empty roles grant nobody).

### Probes and runner

- **FR-PROBE-001**: `HealthProbeInterface::check(float $timeout): ProbeResult`; `ProbeResult` carries `HealthStatus`, an optional message and scalar data.
- **FR-PROBE-002**: `HealthStatus` (`ok`, `degraded`, `error`) with severity ordering and gauge values 1 / 0.5 / 0.
- **FR-PROBE-003**: Doctrine probe runs the platform dummy select (MySQL: with a `MAX_EXECUTION_TIME(<timeout ms>)` hint).
- **FR-PROBE-004**: Redis probe pings a client service or a DSN connection opened with connect/read timeouts and closed afterwards (Predis `disconnect()`, others `close()`, never masking the outcome); non-PONG answers are errors.
- **FR-PROBE-005**: Messenger probe reports `messages` and degrades/fails at `warn_at`/`fail_at`; non-countable transports are degraded.
- **FR-PROBE-006**: FrankenPHP probe reports the runtime snapshot and fails without a worker when `require_worker`.
- **FR-PROBE-007**: `FrankenPhpRuntime` reads the main request server bag, then the process environment; no superglobals.
- **FR-RUN-001**: `HealthChecker` runs probes of a group in name order, passes each its timeout, turns exceptions into sanitized errors, marks overruns as errors, skips probes once the budget is spent (also in `checkAll()`), downgrades non-critical errors to degraded, logs failures without secrets.
- **FR-RUN-002**: `HealthReport` / `ProbeReport` expose the overall status, HTTP health and the detailed array.
- **FR-RUN-003**: `ErrorSanitizer` redacts URL userinfo, scheme-less `user:pass@host`, Bearer/Basic credentials and secret-looking key/value pairs (`=`, `:`, `=>`, JSON), truncates exceptions to 300 chars, and redacts host probe messages and data.
- **FR-RUN-004**: `#[AsHealthProbe]` carries name, groups, critical and timeout.

### Metrics

- **FR-MET-001**: `MetricFamily` / `MetricSample` / `MetricType` validate metric and label names (anchored with `D`) and reject duplicate label sets.
- **FR-MET-002**: `PrometheusTextFormatter` renders text 0.0.4 with escaped help and label values, NaN / ±Inf, integral values without decimals; help and label values scrubbed to valid UTF-8 with `\r` normalised.
- **FR-MET-003**: `MetricsCollector` runs providers once, merges same-name/same-type families (dropping duplicate series), drops type clashes, isolates failing providers and adds `health_kit_metrics_provider_up`.
- **FR-MET-004**: `ProbeMetricsProvider` exports `health_probe_status`, `health_probe_duration_seconds` and numeric/boolean `health_probe_data`.
- **FR-MET-005**: `MetricsProviderInterface::collect(): iterable<MetricFamily>`.

---

## Success Criteria

- **SC-001**: PHPUnit green on PHP 8.2–8.5 × Symfony 7.4 / 8.1 with 100% statement and element coverage of `src/` (`composer test-coverage-100`).
- **SC-002**: PHPStan level 8 with FrankenPHP rulesets, `ignoreErrors: []`, 0 errors; Igor 0 KO.
- **SC-003**: `RoutesOptInTest` proves rule 7; `HealthEndpointsTest` proves US-1…US-6 in a real kernel.
- **SC-004**: Demo Playwright e2e green (`make test-e2e`).
- **SC-005**: Code inventory maps **35/35** production files (33 under `src/` + 2 route files under `config/routes/`).

---

## Key Entities

`RegisteredProbe` (name, probe, groups, critical, timeout) · `ProbeResult` · `ProbeReport` · `HealthReport` · `MetricFamily` · `MetricSample`.

## Non-goals

Host-specific metrics, rate limiting, firewall configuration, histogram/summary types, push gateways, HTML pages.

## Assumptions

Hosts set tokens through env vars, put the endpoints behind `PUBLIC_ACCESS`, and bound DBAL connect time in the connection options.

## Validation commands

```bash
composer test-coverage-100
composer phpstan
composer igor
make release-check
make test-e2e
```
