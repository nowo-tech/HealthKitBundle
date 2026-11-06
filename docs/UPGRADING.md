# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 1.0.0](#to-100)
  - [Requirements](#requirements)
  - [Enable and configure](#enable-and-configure)
  - [Migrating from an in-app health controller](#migrating-from-an-in-app-health-controller)
  - [Breaking changes](#breaking-changes)

## Unreleased

No action needed: `ready.public` and `live.public` default to `true`, so responses are unchanged byte for byte.

To keep readiness off the internet (for example when only a container healthcheck over loopback calls it):

```yaml
nowo_health_kit:
    ready:
        public: false
```

Before switching, check that every legitimate caller is allowed by the `details` rules, because the others now get
404: loopback without proxy headers (default `details.allowed_ips`), an `Authorization: Bearer` token
(`details.token` / `details.token_provider`), or `trust_forwarded_ip` with `framework.trusted_proxies`. A load
balancer or orchestrator probing `/health/ready` from another address needs its address in `details.allowed_ips` or
the token; with a same-host proxy in front, requests through it carry `X-Forwarded-For` and only the token works.
After enabling it, check from outside (`curl -i https://<public-host>/health/ready` → 404): a same-host proxy that
adds none of `Forwarded` / `X-Forwarded-For` / `X-Real-IP` (for example one that only sends `CF-Connecting-IP`) makes
every client look like loopback and would still get the answer. Host routes declared on `HealthController` are hidden
too, except their trailing-slash redirect; prefer importing the bundle routes.
Code that instantiates `HealthController` directly can pass the two new trailing arguments (`$livePublic`,
`$readyPublic`).

## To 1.0.0

Initial release.

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
| Details only for loopback callers in prod, everybody outside prod | `details.allowed_ips` (loopback default; requests with forwarding headers are not matched) + `when@dev` / `when@test`: `details.public: true`. If a proxy on the same host connects over loopback without forwarding headers, use `allowed_ips: []` + token |
| Readiness checks the database and Redis (Redis skipped in test) | `probes.doctrine.enabled: true`, `probes.redis` (`when@test`: `enabled: false`) |
| Public readiness memoised a few seconds per worker | `ready.public_cache_ttl` (default 5) + `ready.cache_pool` (default `cache.app`, shared across processes) |
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
