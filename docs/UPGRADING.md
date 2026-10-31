# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.0.0 (initial release)](#to-100-initial-release)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app health controller](#migrating-from-an-in-app-health-controller)
  - [Breaking changes](#breaking-changes)

## Unreleased

Nothing yet beyond the initial 1.0.0 content below.

## To 1.0.0 (initial release)

### Requirements

- PHP `>=8.2 <8.6`, Symfony 7.4 / 8.0 / 8.1.

### Enable and configure

1. `composer require nowo-tech/health-kit-bundle` (registers the bundle with Flex).
2. Import the routes you want — they are opt-in:

```yaml
# config/routes/nowo_health_kit.yaml
nowo_health_kit_health:
    resource: '@NowoHealthKitBundle/config/routes/health.yaml'
nowo_health_kit_metrics:
    resource: '@NowoHealthKitBundle/config/routes/metrics.yaml'
```

3. Enable the probes you need and set tokens through env vars — see [CONFIGURATION.md](CONFIGURATION.md).

### Migrating from an in-app health controller

Apps that copied a `HealthController` / `MetricsController` (for example the Symfony Beacon template) can replace them:

| In-app behaviour | Bundle equivalent |
| --- | --- |
| `/health/live` returns `status`, `version`, `client_ip` | `version: '%env(...)%'`, `live.expose_client_ip: true` |
| Details only for loopback callers in prod, everybody outside prod | `details.allowed_ips` (loopback default) + `when@dev` / `when@test`: `details.public: true` |
| Readiness checks the database and Redis (Redis skipped in test) | `probes.doctrine.enabled: true`, `probes.redis` (`when@test`: `enabled: false`) |
| Public readiness memoised a few seconds per worker | `ready.public_cache_ttl` (default 5) |
| `/metrics` with a bearer token (no `?token=`), 503 when required token is empty | `metrics.enabled`, `metrics.token`; 503 when neither token nor role is configured |
| Metrics token editable at runtime (stored in settings) | `metrics.token_provider: <service implementing MetricsTokenProviderInterface>` (resolved on every scrape; exclusive with `metrics.token`) |
| Developer role may scrape | `metrics.roles: [ROLE_DEVELOPER]` |
| Collector service with app counters | Implement `MetricsProviderInterface` (autoconfigured) |
| Prometheus formatter | `Nowo\HealthKitBundle\Metrics\PrometheusTextFormatter` |

Differences to check in clients and dashboards:

- The failure status is `error` (HTTP 503) and there is also `degraded` (HTTP 200).
- Detailed readiness lists `checks.<probe>` objects (`status`, `critical`, `duration_ms`, `message`, `data`) instead
  of booleans; the FrankenPHP snapshot moved from `runtime` to `checks.frankenphp.data` on `/health/live`.
- Route names are `nowo_health_kit_live`, `nowo_health_kit_ready`, `nowo_health_kit_metrics`.
- Probe metrics are exported as `health_probe_*` gauges; app metric names are unchanged when the provider keeps them.

### Breaking changes

None (first release).
