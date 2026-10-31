# Configuration

All options live under `nowo_health_kit` (`config/packages/nowo_health_kit.yaml`). The tree is strict: unknown keys
and invalid values fail the container compilation with the offending path in the message (REQ-SF-006).

## Table of contents

- [Full reference with defaults](#full-reference-with-defaults)
- [Global options](#global-options)
- [details — who sees probe details](#details--who-sees-probe-details)
- [probes — built-in probes](#probes--built-in-probes)
  - [Shared probe settings](#shared-probe-settings)
  - [doctrine](#doctrine)
  - [redis](#redis)
  - [messenger](#messenger)
  - [frankenphp](#frankenphp)
- [metrics — Prometheus endpoint](#metrics--prometheus-endpoint)
  - [Runtime token providers](#runtime-token-providers)
- [Timeout hierarchy](#timeout-hierarchy)
- [Environment-specific configuration](#environment-specific-configuration)
- [Profiles](#profiles)

## Full reference with defaults

```yaml
nowo_health_kit:
    default_timeout: 2.0          # seconds per probe without its own timeout (> 0)
    budget: 5.0                   # seconds per group (live / ready) (> 0)
    version: ''                   # shown by /health/live when not empty
    live:
        expose_client_ip: false   # add client_ip (Request::getClientIp(), trusted proxies applied)
    ready:
        public_cache_ttl: 5.0     # seconds the anonymous readiness answer is reused (0 = off)
        cache_pool: cache.app     # PSR-6 pool sharing it across PHP processes (null = per-worker memo only)
    details:
        public: false             # details for everybody (dev/test only)
        allowed_ips: ['127.0.0.1', '::1']
        token: null               # Authorization: Bearer <token>, >= 16 chars
        token_provider: null      # service id of a DetailsTokenProviderInterface (exclusive with token)
        trust_forwarded_ip: false # match allowed_ips on getClientIp() instead of REMOTE_ADDR
    probes:
        doctrine:
            enabled: false
            connections: []       # empty = every DBAL connection
            groups: [ready]
            critical: true
            timeout: null         # null = default_timeout
        redis:
            enabled: false
            dsn: null             # exactly one of dsn / client when enabled
            client: null          # service id of \Redis, \Relay\Relay or a Predis client
            groups: [ready]
            critical: true
            timeout: 1.0
        messenger:
            enabled: false
            transports: {}        # name: { warn_at: int|null, fail_at: int|null }
            groups: [ready]
            critical: false
            timeout: null
        frankenphp:
            enabled: true
            require_worker: false
            groups: [live]
            critical: true
            timeout: null
    metrics:
        enabled: false
        token: null               # Authorization: Bearer <token>, >= 16 chars
        token_provider: null      # service id of a MetricsTokenProviderInterface (exclusive with token)
        roles: []                 # session roles also allowed (empty = none)
        probe_metrics: true       # export probes as gauges
```

## Global options

| Key | Type / default | Meaning |
| --- | --- | --- |
| `default_timeout` | float `2.0`, > 0 | Timeout passed to probes that have no own timeout. A probe that takes longer is reported as `error` ("Timed out after …"). |
| `budget` | float `5.0`, > 0 | Time a whole group may spend. Probes not started once it is spent are reported as `error` (or `degraded` when not critical). |
| `version` | string `''` | Version string added to `/health/live` (`"version"`). Typical: `'%env(default::APP_VERSION)%'`. |
| `live.expose_client_ip` | bool `false` | Add `"client_ip"` to `/health/live` — useful to verify `framework.trusted_proxies`. The caller only sees its own address. |
| `ready.public_cache_ttl` | float `5.0`, ≥ 0 | Anonymous readiness answers are reused for this many seconds (status, healthy flag and timestamp only): per-worker memo plus `ready.cache_pool`. Detail callers always get a fresh run. |
| `ready.cache_pool` | string `cache.app`, or null | PSR-6 pool (service id) storing the anonymous readiness answer for `public_cache_ttl`, so PHP-FPM and classic FrankenPHP processes share it instead of running the probes on every anonymous request. A missing service is ignored; a failing pool only disables sharing. Null: per-worker memo only (then rate-limit `/health/*` at the proxy under FPM / classic mode). |

## details — who sees probe details

> **Reverse proxy on the same host or pod?** If a proxy (Caddy/nginx on the host, Varnish, `cloudflared`, an nginx
> sidecar…) connects to the app over loopback, every client arrives with `REMOTE_ADDR = 127.0.0.1`. The bundle then
> ignores the IP allowlist for requests carrying `Forwarded`, `X-Forwarded-For` or `X-Real-IP` (only the token grants
> details), but a proxy that does **not** add those headers would still expose details to the Internet. In that setup
> set `details.allowed_ips: []` (token only), or configure `framework.trusted_proxies` and `details.trust_forwarded_ip: true`.


Public callers get the status only (`{"status":"ok"}`). A caller gets the full report (probe names, durations,
messages, data) when **one** of these holds:

| Key | Default | Rule |
| --- | --- | --- |
| `details.public` | `false` | Everybody sees details. Use only under `when@dev` / `when@test`. |
| `details.allowed_ips` | `['127.0.0.1', '::1']` | `REMOTE_ADDR` matches an IP or CIDR (`10.0.0.0/8`, `fd00::/8`). Validated at compile time. An empty list disables the IP rule. With `trust_forwarded_ip: false`, requests carrying `Forwarded` / `X-Forwarded-For` / `X-Real-IP` never match (they came through a proxy). |
| `details.token` | `null` | `Authorization: Bearer <token>` matches (constant-time). Tokens shorter than 16 characters are ignored (fail closed). `?token=` query strings are never read. Empty string = `null`. |
| `details.token_provider` | `null` | Service id of a `Nowo\HealthKitBundle\Http\DetailsTokenProviderInterface` whose `getToken(): ?string` is called per request (only when the caller is not already allowed by `public` / `allowed_ips`). For tokens editable at runtime. Mutually exclusive with `token` (configuration error when both are set). A null, short (< 16 chars) or throwing provider grants nothing. |
| `details.trust_forwarded_ip` | `false` | Match `allowed_ips` against `Request::getClientIp()` (honours `framework.trusted_proxies`). Leave off unless every request goes through a trusted proxy: otherwise a client could claim `X-Forwarded-For: 127.0.0.1`. |

## probes — built-in probes

### Shared probe settings

Every built-in probe accepts:

| Key | Values | Meaning |
| --- | --- | --- |
| `groups` | non-empty list of `live`, `ready` | Endpoints running the probe. |
| `critical` | bool | `true`: a failure answers HTTP 503. `false`: it only degrades the answer (HTTP 200, `"degraded"`). |
| `timeout` | float > 0 or `null` | Seconds the probe may spend (`null` = `default_timeout`). |

Custom probes set the same values through `#[AsHealthProbe(name:, groups:, critical:, timeout:)]` or tag attributes
(`nowo_health_kit.probe` with `name`, `groups`, `critical`, `timeout`). Probe names must match `[a-z0-9_.-]+` and be
unique; probes run and are reported in name order.

### doctrine

One probe named `doctrine.<connection>` per DBAL connection runs the platform dummy select (`SELECT 1`).

| Key | Default | Notes |
| --- | --- | --- |
| `enabled` | `false` | Needs `doctrine/doctrine-bundle`; enabling it without a DBAL connection fails the compilation. |
| `connections` | `[]` | Names to ping; empty = all. Unknown names fail the compilation and list the available ones. |

On MySQL the probe sends `SELECT /*+ MAX_EXECUTION_TIME(<timeout ms>) */ 1`. DBAL has no portable socket or statement
timeout, so also bound connect and read time in the connection: `options: { !php/const PDO::ATTR_TIMEOUT: 2,
!php/const PDO::MYSQL_ATTR_READ_TIMEOUT: 2 }` for MySQL, `?connect_timeout=2&options=-c%20statement_timeout%3D2000` for
PostgreSQL. The checker still reports an overrun as an error.

### redis

Probe `redis` sends `PING`.

| Key | Default | Notes |
| --- | --- | --- |
| `dsn` | `null` | e.g. `'%env(REDIS_URL)%'`. A short-lived connection is opened with `timeout` and `read_timeout` = the probe timeout, then closed. Needs `symfony/cache` and `ext-redis` / `predis/predis` / `ext-relay`. |
| `client` | `null` | Service id of an existing client (`\Redis`, `\Relay\Relay`, Predis). Its own timeouts apply. |
| `timeout` | `1.0` | |

Exactly one of `dsn` / `client` is required when the probe is enabled.

### messenger

One probe named `messenger.<transport>` per listed transport (service `messenger.transport.<name>` must exist).

```yaml
probes:
    messenger:
        enabled: true
        transports:
            async: { warn_at: 100, fail_at: 1000 }
            failed: { warn_at: 1 }
```

| Key | Default | Notes |
| --- | --- | --- |
| `transports.<name>.warn_at` | `null` | From this many waiting messages the probe is `degraded`. Integer ≥ 1. |
| `transports.<name>.fail_at` | `null` | From this many messages the probe fails. Integer ≥ 1, ≥ `warn_at`. |
| `critical` | `false` | A backlog degrades readiness instead of taking the instance out of rotation. |

The count comes from the transport (`MessageCountAwareInterface`: Doctrine, Redis, AMQP, Beanstalkd…); a transport
that cannot count reports `degraded` ("cannot report its message count"). `getMessageCount()` takes no timeout, so
bound it in the transport DSN: Doctrine through the DBAL connection options above, Redis `?read_timeout=2&timeout=2`,
AMQP `?connect_timeout=2&read_timeout=2`. The checker still reports an overrun as an error.

### frankenphp

Probe `frankenphp` reports `sapi`, `frankenphp_mode` (`classic`/`worker`), `frankenphp_worker`, `reset_kernel`,
`app_runtime_mode` and `worker_num` from the request server bag (falling back to the process environment).

| Key | Default | Notes |
| --- | --- | --- |
| `enabled` | `true` | No I/O. |
| `require_worker` | `false` | Fail when the request is not served by a FrankenPHP worker (useful to catch a classic-mode rollback). |
| `groups` | `[live]` | |

## metrics — Prometheus endpoint

| Key | Default | Notes |
| --- | --- | --- |
| `enabled` | `false` | Disabled: `/metrics` answers 404 (when its routes are imported). |
| `token` | `null` | `Authorization: Bearer <token>`, ≥ 16 characters, constant-time compare, never logged. `?token=` is never read. |
| `token_provider` | `null` | Service id of a `Nowo\HealthKitBundle\Metrics\MetricsTokenProviderInterface`; `getToken(): ?string` is called on **every scrape** (no caching across requests), so a token edited in your settings applies immediately, also in FrankenPHP worker mode. Mutually exclusive with `token`. Same fail-closed rules: a null, short or throwing provider counts as "no token" (503 unless `roles` is set). |
| `roles` | `[]` | Session roles also allowed (e.g. `ROLE_ADMIN`; needs `symfony/security-core`). An empty list grants nobody. |
| `probe_metrics` | `true` | Export every probe as `health_probe_status{probe}` (1/0.5/0), `health_probe_duration_seconds{probe}` and `health_probe_data{probe,key}`. Probes run once per scrape, within `budget`. |

With `enabled: true` and neither a usable token (static or from the provider) nor a role, the endpoint answers **503** (fail closed). With a `token_provider`, requests without an `Authorization: Bearer` header never call the provider and get **401** (or 200 via `roles`).

### Runtime token providers

```php
use Nowo\HealthKitBundle\Metrics\MetricsTokenProviderInterface;

final readonly class SettingsMetricsToken implements MetricsTokenProviderInterface
{
    public function __construct(private OpsSettings $settings) {}

    public function getToken(): ?string
    {
        return $this->settings->metricsToken(); // read per request; never log it
    }
}
```

```yaml
nowo_health_kit:
    metrics:
        enabled: true
        token_provider: App\Metrics\SettingsMetricsToken
```

The provider is called only for requests that carry an `Authorization: Bearer` header (anonymous traffic never
triggers it), once per such request; keep the lookup cheap (one settings read). If it throws, the bundle treats the
token as missing and logs a warning with the provider and exception class names only (never the message).

## Timeout hierarchy

Innermost deadline first (REQ-RUNTIME-001):

| Layer | Default | Rule |
| --- | --- | --- |
| Probe I/O (`probes.*.timeout`, Redis connect/read, DBAL connect options) | 1–2 s | Fires first. |
| Group `budget` | 5 s | Greater than the sum of the probe timeouts you expect to run. |
| PHP `max_execution_time` | host | Greater than `budget`. |
| Reverse proxy / Caddy write timeout | host | Greater than PHP. |
| Orchestrator probe timeout (Kubernetes `timeoutSeconds`, Docker `--timeout`) | host | Greater than `budget`. |

The demo uses 5 s budget < 20 s `max_execution_time` < 30 s Caddy write timeout (see [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md)).

## Environment-specific configuration

```yaml
when@dev:
    nowo_health_kit:
        details: { public: true }

when@test:
    nowo_health_kit:
        details: { public: true }
        probes:
            redis: { enabled: false }
```

## Profiles

Not applicable: the bundle has a single configuration (REQ-CFG-001 / REQ-CFG-002 do not apply).
