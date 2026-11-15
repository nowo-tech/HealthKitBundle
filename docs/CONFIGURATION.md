# Configuration

All options live under `nowo_health_kit` (`config/packages/nowo_health_kit.yaml`). The tree is strict: unknown keys
and invalid values fail the container compilation with the offending path in the message (REQ-SF-006).

## Table of contents

- [Full reference with defaults](#full-reference-with-defaults)
- [Global options](#global-options)
- [Non-public endpoints (live.public / ready.public)](#non-public-endpoints-livepublic--readypublic)
- [details — who sees probe details](#details--who-sees-probe-details)
- [probes — built-in probes](#probes--built-in-probes)
  - [Shared probe settings](#shared-probe-settings)
  - [doctrine](#doctrine)
  - [redis](#redis)
  - [messenger](#messenger)
  - [frankenphp](#frankenphp)
- [metrics — Prometheus endpoint](#metrics--prometheus-endpoint)
  - [Runtime token providers](#runtime-token-providers)
- [runtime — FrankenPHP worker guards](#runtime--frankenphp-worker-guards)
  - [worker_recycle](#worker_recycle)
  - [dev_collector_reset](#dev_collector_reset)
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
        public: true              # false = 404 for callers the details rules do not allow
        expose_client_ip: false   # add client_ip (Request::getClientIp(), trusted proxies applied)
    ready:
        public: true              # false = 404 for callers the details rules do not allow
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
    runtime:                      # FrankenPHP worker guards, all off by default
        dev_collector_reset: false  # clear the profiler form collector's per-form data after each request
        worker_recycle:
            enabled: false        # end the worker after a request that lost a critical connection
            redis: true           # trigger on Redis client errors (phpredis, Relay, Predis)
            exception_classes: [] # extra throwable classes / interfaces (FQCN) that trigger it
            log_level: critical   # PSR-3 level of the recycle log entry
            state_pool: null      # PSR-6 pool recording recycles (metrics, probe); never the Redis pool
            recent_window: 300    # seconds the probe stays degraded after a recycle (0 = never)
            probe:                # "runtime.worker_recycle" probe (needs state_pool)
                enabled: false
                groups: [ready]
                critical: false
                timeout: null
```

## Global options

| Key | Type / default | Meaning |
| --- | --- | --- |
| `default_timeout` | float `2.0`, > 0 | Timeout passed to probes that have no own timeout. A probe that takes longer is reported as `error` ("Timed out after …"). |
| `budget` | float `5.0`, > 0 | Time a whole group may spend. Probes not started once it is spent are reported as `error` (or `degraded` when not critical). |
| `version` | string `''` | Version string added to `/health/live` (`"version"`). Typical: `'%env(default::APP_VERSION)%'`. |
| `live.public` | bool `true` | `false`: `/health/live` exists only for callers allowed by the [details rules](#details--who-sees-probe-details); everybody else gets a 404. See [Non-public endpoints](#non-public-endpoints-livepublic--readypublic). |
| `ready.public` | bool `true` | `false`: `/health/ready` exists only for callers allowed by the details rules; everybody else gets a 404, no probe runs and nothing is cached for them. |
| `live.expose_client_ip` | bool `false` | Add `"client_ip"` to `/health/live` — useful to verify `framework.trusted_proxies`. The caller only sees its own address. |
| `ready.public_cache_ttl` | float `5.0`, ≥ 0 | Anonymous readiness answers are reused for this many seconds (status, healthy flag and timestamp only): per-worker memo plus `ready.cache_pool`. Detail callers always get a fresh run. |
| `ready.cache_pool` | string `cache.app`, or null | PSR-6 pool (service id) storing the anonymous readiness answer for `public_cache_ttl`, so PHP-FPM and classic FrankenPHP processes share it instead of running the probes on every anonymous request. A missing service is ignored; a failing pool only disables sharing. Null: per-worker memo only (then rate-limit `/health/*` at the proxy under FPM / classic mode). |

## Non-public endpoints (live.public / ready.public)

By default `/health/live` and `/health/ready` answer their status (`{"status":"ok"}`) to every caller. When only your
own infrastructure needs an endpoint (for example a container healthcheck over loopback), hide it from everybody else:

```yaml
nowo_health_kit:
    ready:
        public: false   # /health/ready: loopback (no proxy headers) or Bearer token only
    live:
        public: true    # /health/live stays public (uptime probes)
```

With `public: false`, the caller must be allowed by the same rules that grant [details](#details--who-sees-probe-details)
(`details.public`, `details.allowed_ips` with the forwarding-header rule, `details.token` / `details.token_provider`).
Allowed callers get the detailed report, exactly as today. Everybody else gets:

- **404**, never 401 / 403: the same `NotFoundHttpException` the router throws for an unknown URL (same message
  shape), so the host's error page and headers are those of any path without a route. A `kernel.request` listener
  (priority 31, right after the router and before the firewall) throws it as soon as the request is routed to a
  hidden endpoint, including the router's trailing-slash redirect (`/health/ready/` would otherwise answer 301), and
  a `kernel.exception` listener replays a 405 (`POST` / `PUT` / `OPTIONS`…, also on percent-encoded paths such as
  `/health/%72eady`) through the router as a GET and turns it into that 404. The controller checks again.
- **no probe run, no cache access**: the decision is taken before the probes and before the anonymous readiness memo
  and `ready.cache_pool` are read or written, so the answer does not depend on which probes exist or on their state.

Examples with `ready.public: false` and the default `details.allowed_ips` (loopback):

| Request | Answer |
| --- | --- |
| `curl http://127.0.0.1/health/ready` inside the container (no proxy headers) | 200 + detailed report |
| `https://app.example.com/health/ready` through the host proxy (adds `X-Forwarded-For`) | 404 |
| any client with `Authorization: Bearer <details.token>` | 200 + detailed report |

`details.public: true` allows everybody, so it makes `public: false` moot (keep it to `when@dev` / `when@test`). With
`details.trust_forwarded_ip: true` the allowlist is matched on `Request::getClientIp()`: only `framework.trusted_proxies`
decide which forwarded addresses count (see the warning below).

> **Check from outside after enabling it.** A same-host proxy that connects over loopback and adds **none** of
> `Forwarded`, `X-Forwarded-For` or `X-Real-IP` (nginx `proxy_pass` without `proxy_set_header`, a tunnel that only
> sends `CF-Connecting-IP`…) makes every Internet client look like `127.0.0.1`, so the "hidden" endpoint (and its
> details) is answered to everybody. Run `curl -i https://<public-host>/health/ready` and expect 404; otherwise make
> the proxy send `X-Forwarded-For`, or set `details.allowed_ips: []` and call the endpoint with the token.

Routes covered: the bundle routes (`nowo_health_kit_live` / `nowo_health_kit_ready`, with any `prefix:` on the
import) and any host route whose controller is `HealthController::live` / `::ready` (GET/HEAD and other methods).
One gap remains for host-defined routes: the router's trailing-slash redirect of such a route (`/internal/ready/` →
301) points at the redirect controller and carries only the host route name, so it is not recognised. Import the
bundle routes (with `prefix:` if needed) rather than declaring your own routes to the controller.

Debug mode (`kernel.debug`) is not covered: the exception page shows the stack trace (`HiddenEndpoint` ←
`HiddenEndpointListener`) and log lines name other files. Never expose a debug kernel to the Internet.


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

## runtime — FrankenPHP worker guards

Opt-in guards for applications served by FrankenPHP worker mode with the kernel kept alive
(`FRANKENPHP_RESET_KERNEL=false`). Everything is off by default: a host that does not set `runtime` is unchanged.

### worker_recycle

When the shared Redis (or another backend) is restarted or recreated, a kept-alive worker keeps its dead connections
(session handler, cache pools, rate-limit clients) and every request it serves fails, while `/health/live` may stay
green. With `worker_recycle.enabled`, a request that fails on a lost connection ends its worker **after its response
has been sent**, and FrankenPHP starts a fresh worker with new connections.

How it works:

1. `kernel.exception` (priority 2048) walks the exception and its `getPrevious()` chain. An instance (`instanceof`) of
   a trigger class marks the current worker. Host code that catches the failure itself can mark it through the
   `Nowo\HealthKitBundle\Runtime\WorkerRecyclerInterface` port (`requestRecycle(string $reason)`).
2. `kernel.terminate` (priority -8192: the response is already sent; listeners registered **below** -8192 do not run
   on a recycled request) records the recycle in `state_pool`, logs it once on the `health` channel and throws
   `Nowo\HealthKitBundle\Runtime\WorkerRecycleException`, whose message is a constant (the reason is never in the
   exception). Symfony's `FrankenPhpWorkerRunner` (symfony/runtime, used when `APP_RUNTIME_MODE` has `worker=` with a
   value other than 0) rethrows it once `frankenphp_handle_request()` returns: the worker script ends and FrankenPHP
   restarts it.
3. Outside FrankenPHP worker mode (PHP-FPM, classic FrankenPHP, CLI, tests) nothing is thrown, because those
   processes do not outlive the request. It is a no-op there.

The recycle is an **uncaught exception, not a clean exit** (unlike a worker reaching `FRANKENPHP_LOOP_MAX`):

- FrankenPHP logs `unexpected termination, restarting` (exit code 255) for every recycle.
- Symfony logs a `CRITICAL` "Uncaught PHP Exception ... WorkerRecycleException" line on the `request` channel.
- In dev the error renderer cannot run after the response (`ob_start()` fatal); the worker restarts anyway.
- Error trackers see it as an error. Filter it. Sentry (`sentry/sentry-symfony`):
  `sentry: { options: { ignore_exceptions: ['Nowo\HealthKitBundle\Runtime\WorkerRecycleException'] } }`. BeaconBundle:
  set `nowo_beacon: { ignore_exceptions: ['Nowo\\HealthKitBundle\\Runtime\\WorkerRecycleException'] }` (matched with `instanceof`; covers the HTTP, Messenger and console listeners and the Monolog handler; `nowo_beacon.monolog_handler.ignore_exceptions` adds entries for the handler only). For log alerting, drop `request` channel records whose
  `context.exception` is a `WorkerRecycleException`; the guard's own `health` log entry is the one to alert on.

The mark lives for one request only: it is cleared at the main `kernel.request`, at `kernel.terminate` and on
`kernel.reset`.

| Key | Default | Notes |
| --- | --- | --- |
| `enabled` | `false` | Turns the guard on. |
| `redis` | `true` | Triggers on `RedisException`, `RedisClusterException` (phpredis), `Relay\Exception` and `Predis\Connection\ConnectionException`. Classes that are not installed are skipped. |
| `exception_classes` | `[]` | More triggers, as fully qualified class or interface names, for example `Doctrine\DBAL\Exception\ConnectionLost` or your own client's "connection lost" exception. With `redis: false`, at least one is required. |
| `allow_broad_exception_classes` | `false` | Container build refuses broad bases in `exception_classes` (`Throwable`, `Exception`, `Error`, `RuntimeException`, `LogicException`, `InvalidArgumentException`, `HttpExceptionInterface`, `HttpException`, `Doctrine\DBAL\Exception`, `Doctrine\DBAL\Exception\DriverException`): any 404 or duplicate key would recycle the worker. Also refused: anything that is a `HttpExceptionInterface` or DBAL `ConstraintViolationException`, plus `ErrorException`, `UnexpectedValueException`, `DomainException`, `TypeError`, `ValueError`. The guard cannot catch every subclass of the broad bases (legitimate triggers such as `RedisException` extend `Exception`), so an ordinary subclass you name on purpose is accepted. Set `true` only to accept that risk. |
| `log_level` | `critical` | PSR-3 level of the entry `Critical connection lost: recycling the FrankenPHP worker…`. Its context holds `reason` (the exception class name, or the reason given to `requestRecycle()` with credentials, emails and IP addresses redacted, max 200 characters) and `recorded`. Exception messages are never logged by the guard. |
| `state_pool` | `null` | PSR-6 pool that records recycles (count, last time, last reason) for `/metrics` and the probe. Use a pool that does **not** depend on the connection being recycled, for example `cache.system` (filesystem), never a Redis pool. The record expires after 30 days (renewed by each recycle). A missing service fails the container build (`InvalidConfigurationException`). Pool failures never block a recycle. |
| `recent_window` | `300` | Seconds the `runtime.worker_recycle` probe reports `degraded` after the last recycle (`0`: never). |
| `probe` | disabled | `runtime.worker_recycle` probe: `degraded` after a recent recycle, `ok` otherwise, with `recycles_total`, `last_recycle_age_seconds` and `last_reason` as data. Shared probe settings: groups `[ready]`, `critical: false`. It requires `state_pool`. |

When `metrics.enabled` is true, `/metrics` also exports `health_kit_worker_recycle_enabled`,
`health_kit_worker_recycle_persistent` (1 with a `state_pool`), `health_kit_worker_recycles_total` (counter, from the
pool) and `health_kit_worker_recycle_last_timestamp_seconds` (once a recycle has happened). The pool count is a
read-increment-write: two workers recycling at the same instant may count once.

```yaml
# config/packages/nowo_health_kit.yaml
nowo_health_kit:
    runtime:
        worker_recycle:
            enabled: true
            state_pool: cache.system
            probe: { enabled: true }
```

Notes:

- The failing request itself still gets its normal error response. Only the next requests benefit.
- Messenger consumers (`messenger:consume`) are not HTTP workers. Use `--failure-limit`, `--memory-limit` and
  `--time-limit` with your process manager's restart policy there.
- A custom runtime that does not rethrow exceptions escaping `kernel.terminate` will not end the worker. The guard
  only throws when `APP_RUNTIME_MODE` has `worker=` with a value other than 0, which Symfony's FrankenPHP runner sets.

### dev_collector_reset

`Symfony\Component\Form\Extension\DataCollector\FormDataCollector::reset()` clears its report but not the per-form
data it gathers while forms are built (`dataByForm`, `dataByView`, `formsByView`, with every resolved option). A
kept-alive worker keeps the collector between requests, so in dev that data grows until the worker runs out of
memory (Symfony Beacon measured +340 KB per request on its login page). With `dev_collector_reset: true` the bundle
clears those three properties on `kernel.terminate` (priority -4096, after the profiler's late collection) and on
`kernel.reset`. Without the profiler or symfony/form it does nothing. Enable it in dev only:

```yaml
when@dev:
    nowo_health_kit:
        runtime:
            dev_collector_reset: true
```

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
