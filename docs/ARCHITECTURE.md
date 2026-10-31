# Architecture

## Table of contents

- [Overview](#overview)
- [Request flow](#request-flow)
- [Components](#components)
- [Container wiring](#container-wiring)
- [Worker safety](#worker-safety)
- [Extension points](#extension-points)

## Overview

```
HTTP ──► HealthController ──► DetailsAccessPolicy (IP / token / public)
                │
                └──► HealthChecker ──► RegisteredProbe[] ──► HealthProbeInterface
                         │                                   (Doctrine, Redis, Messenger, FrankenPHP, host probes)
                         └──► HealthReport ──► JSON (status only, or detailed)

HTTP ──► MetricsController ──► BearerToken / roles
                │
                └──► MetricsCollector ──► MetricsProviderInterface[] (ProbeMetricsProvider + host providers)
                         └──► PrometheusTextFormatter ──► text/plain; version=0.0.4
```

## Request flow

1. The host imports `config/routes/health.yaml` (and optionally `metrics.yaml`); nothing is routed otherwise.
2. `/health/ready`: the controller asks `DetailsAccessPolicy` whether the caller may see details.
   - Detail callers: `HealthChecker::check('ready')` runs every `ready` probe and the detailed report is returned.
   - Public callers: the memoised status is returned when younger than `ready.public_cache_ttl`, else the probes run
     and only `status` is returned.
3. `HealthChecker` runs probes in name order: each gets its timeout; exceptions become sanitized errors; overruns
   become errors; once the group budget is spent the remaining probes are skipped (error); non-critical errors are
   downgraded to `degraded`. The overall status is the worst one; `error` maps to HTTP 503.
4. `/metrics`: 404 when disabled, 503 when no access is configured, 401 without a matching token or role, else the
   collector aggregates the providers and the formatter renders the text.

## Components

| Namespace | Role |
| --- | --- |
| `Controller` | `HealthController` (live/ready), `MetricsController` (/metrics). Not autoconfigured. |
| `Health` | `HealthChecker`, `RegisteredProbe`, `ProbeReport`, `HealthReport`, `ErrorSanitizer`. |
| `Http` | `DetailsAccessPolicy`, `BearerToken` (constant-time bearer check, `resolve()` of static token vs provider), `TokenProviderInterface`, `DetailsTokenProviderInterface`. |
| `Probe` | `HealthProbeInterface`, `ProbeResult`, `HealthStatus`, built-in probes. |
| `Runtime` | `FrankenPhpRuntime` (request server bag → process env). |
| `Metrics` | `MetricsProviderInterface`, `MetricFamily`, `MetricSample`, `MetricType`, `MetricsCollector`, `PrometheusTextFormatter`, `ProbeMetricsProvider`, `MetricsTokenProviderInterface`. |
| `Attribute` | `#[AsHealthProbe]`. |
| `DependencyInjection` | `Configuration`, `NowoHealthKitExtension`, `Compiler\DoctrineProbePass`, `Compiler\HealthProbePass`. |

## Container wiring

- `NowoHealthKitExtension` registers services, the built-in Redis / Messenger / FrankenPHP probes and the
  controllers (`autoconfigure: false`, tag `controller.service_arguments`). It autoconfigures
  `HealthProbeInterface` (tag `nowo_health_kit.probe`), `#[AsHealthProbe]` (tag with attributes) and
  `MetricsProviderInterface` (tag `nowo_health_kit.metrics_provider`).
- `DoctrineProbePass` (priority 10) reads `%doctrine.connections%` and registers one `doctrine.<name>` probe per
  connection, failing on unknown names.
- `HealthProbePass` (priority 0) validates probe tags (name, groups, timeout, uniqueness), builds the sorted
  `RegisteredProbe` list for the checker and aliases the clock (`clock` when FrameworkBundle provides it, else
  `NativeClock`).

## Worker safety

All services are immutable (`readonly`) except `HealthController`, which keeps the anonymous readiness memo (status +
timestamp). No static state, no superglobal reads, no request data kept between requests. See
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## Extension points

- Probes: `HealthProbeInterface` + `#[AsHealthProbe]` or the `nowo_health_kit.probe` tag.
- Tokens: `DetailsTokenProviderInterface` / `MetricsTokenProviderInterface` (`*.token_provider`, resolved per request).
- Metrics: `MetricsProviderInterface` (tag `nowo_health_kit.metrics_provider`, `priority` supported).
