# Usage

## Table of contents

- [Endpoints and JSON contract](#endpoints-and-json-contract)
  - [Status values and HTTP codes](#status-values-and-http-codes)
  - [Public answer](#public-answer)
  - [Detailed answer](#detailed-answer)
- [Writing a probe](#writing-a-probe)
- [Writing a metrics provider](#writing-a-metrics-provider)
- [Prometheus formatter on its own](#prometheus-formatter-on-its-own)
- [Orchestrators](#orchestrators)
  - [Kubernetes](#kubernetes)
  - [Docker Compose healthcheck](#docker-compose-healthcheck)
  - [Prometheus scrape config](#prometheus-scrape-config)
- [Tokens stored in application settings](#tokens-stored-in-application-settings)
- [Using the checker in your own code](#using-the-checker-in-your-own-code)
- [Demo smoke](#demo-smoke)

## Endpoints and JSON contract

| Route name | Path | Methods | Runs group |
| --- | --- | --- | --- |
| `nowo_health_kit_live` | `/health/live` | GET, HEAD | `live` (by default only `frankenphp`) |
| `nowo_health_kit_ready` | `/health/ready` | GET, HEAD | `ready` (database, Redis, queues, custom probes) |
| `nowo_health_kit_metrics` | `/metrics` | GET | every probe once (when `probe_metrics`) + providers |

All responses carry `Cache-Control: no-store, private`; health responses also `X-Robots-Tag: noindex`. Health routes
are `stateless` (no session is started).

### Status values and HTTP codes

| `status` | Meaning | HTTP |
| --- | --- | --- |
| `ok` | Every probe passed. | 200 |
| `degraded` | A probe is degraded (e.g. queue above `warn_at`) or a **non-critical** probe failed. | 200 |
| `error` | A **critical** probe failed, timed out, or was not started because the budget ran out. | 503 |

### Public answer

```json
{"status": "ok"}
```

`/health/live` adds `"version"` when `version` is set and `"client_ip"` when `live.expose_client_ip` is true. Public
`/health/ready` answers are reused for `ready.public_cache_ttl` seconds (per-worker memo + `ready.cache_pool`).

### Detailed answer

For allowlisted IPs, `Authorization: Bearer <details.token>` callers, or everybody with `details.public: true`:

```json
{
  "status": "degraded",
  "duration_ms": 1.343,
  "checks": {
    "doctrine.default": {"status": "ok", "critical": true, "duration_ms": 0.261},
    "messenger.async": {
      "status": "degraded", "critical": false, "duration_ms": 0.407,
      "message": "12 messages waiting (warning threshold 10).",
      "data": {"messages": 12, "warn_at": 10, "fail_at": 50}
    },
    "redis": {"status": "error", "critical": true, "duration_ms": 500.2, "message": "Timed out after 0.500s (timeout 0.500s)."}
  }
}
```

Exception messages are sanitized before they are shown or logged: credentials in DSNs (`scheme://user:pass@`) and
`password=` / `token=` pairs are replaced by `***`, and the text is truncated to 300 characters.

## Writing a probe

```php
namespace App\Health;

use Nowo\HealthKitBundle\Attribute\AsHealthProbe;
use Nowo\HealthKitBundle\Probe\HealthProbeInterface;
use Nowo\HealthKitBundle\Probe\ProbeResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsHealthProbe(name: 'search', groups: ['ready'], critical: false, timeout: 1.0)]
final readonly class SearchProbe implements HealthProbeInterface
{
    public function __construct(private HttpClientInterface $client) {}

    public function check(float $timeout): ProbeResult
    {
        $response = $this->client->request('GET', 'http://search:9200/_cluster/health', [
            'timeout' => $timeout,
            'max_duration' => $timeout,
        ]);
        $health = $response->toArray()['status'] ?? 'red';

        return match ($health) {
            'green' => ProbeResult::ok(['cluster' => $health]),
            'yellow' => ProbeResult::degraded('Cluster is yellow.', ['cluster' => $health]),
            default => ProbeResult::error('Cluster is red.', ['cluster' => $health]),
        };
    }
}
```

Rules:

- **Bound every I/O call with `$timeout`** (HTTP client `timeout` + `max_duration`, socket timeouts…). The checker
  reports overruns, but it cannot interrupt a blocking call.
- **Keep no per-request state** in properties (FrankenPHP worker mode).
- **No secrets in `message` or `data`** — they are shown to detail callers. Throwing is fine: the exception becomes a
  sanitized `error` result.
- Numeric and boolean `data` values become `health_probe_data{probe,key}` gauges.
- Without the attribute, implementing `HealthProbeInterface` is enough (autoconfiguration): the name is the snake_case
  short class name without `Probe` (`SearchProbe` → `search`), group `ready`, critical, `default_timeout`.

## Writing a metrics provider

```php
namespace App\Metrics;

use Nowo\HealthKitBundle\Metrics\MetricFamily;
use Nowo\HealthKitBundle\Metrics\MetricSample;
use Nowo\HealthKitBundle\Metrics\MetricsProviderInterface;
use Nowo\HealthKitBundle\Metrics\MetricType;

final readonly class IngestMetricsProvider implements MetricsProviderInterface
{
    public function __construct(private IngestCounters $counters) {}

    public function collect(): iterable
    {
        yield MetricFamily::counter('app_ingest_ack_total', 'Accepted ingest requests', $this->counters->acks());
        yield new MetricFamily('app_ingest_reject_total', MetricType::Counter, 'Rejected ingest requests', [
            new MetricSample($this->counters->rejects('quota'), ['reason' => 'quota']),
            new MetricSample($this->counters->rejects('invalid'), ['reason' => 'invalid']),
        ]);
    }
}
```

- Implementing the interface is enough (autoconfigured, tag `nowo_health_kit.metrics_provider`; use the tag
  `priority` attribute to order providers).
- Read counters atomically (Redis `INCRBY`, SQL counters): PSR-6 get/set loses increments under concurrent workers.
- One query or read per family — no N+1. A provider that throws is skipped for that scrape and reported as
  `health_kit_metrics_provider_up{provider="IngestMetricsProvider"} 0` (the failure is logged with the exception class only).
- Families with the same name and type from several providers are merged; a name reused with another type is dropped.
- Metric names must match `[a-zA-Z_:][a-zA-Z0-9_:]*`, label names `[a-zA-Z_]\w*` (not starting with `__`).

## Prometheus formatter on its own

```php
use Nowo\HealthKitBundle\Metrics\MetricFamily;
use Nowo\HealthKitBundle\Metrics\PrometheusTextFormatter;

$text = (new PrometheusTextFormatter())->format([MetricFamily::gauge('queue_depth', 'Waiting jobs', 3)]);
// # HELP queue_depth Waiting jobs
// # TYPE queue_depth gauge
// queue_depth 3
```

Supported types: `counter`, `gauge`, `untyped` (histograms and summaries are out of scope for 1.0).

## Orchestrators

### Kubernetes

```yaml
livenessProbe:
  httpGet: { path: /health/live, port: 8080 }
  periodSeconds: 10
  timeoutSeconds: 3
readinessProbe:
  httpGet: { path: /health/ready, port: 8080 }
  periodSeconds: 5
  timeoutSeconds: 6        # > nowo_health_kit.budget
  failureThreshold: 3
```

Kubelet probes come from the node IP, not loopback: they get the status-only answer, which is all they need. To see
details from inside the cluster, add the pod/node CIDR to `details.allowed_ips` or use the token.

### Docker Compose healthcheck

```yaml
healthcheck:
  test: ["CMD", "curl", "-fsS", "http://127.0.0.1/health/ready"]
  interval: 30s
  timeout: 6s
  retries: 3
```

Inside the container the caller is loopback, so `curl http://127.0.0.1/health/ready` shows the details. Behind a
reverse proxy on the same host or pod, read the proxy warning in [CONFIGURATION.md](CONFIGURATION.md#details--who-sees-probe-details).

### Prometheus scrape config

```yaml
scrape_configs:
  - job_name: symfony-app
    metrics_path: /metrics
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/app-metrics-token
    static_configs:
      - targets: ['app.example:443']
    scheme: https
```

## Tokens stored in application settings

When operators edit the details or metrics token at runtime, implement `DetailsTokenProviderInterface` /
`MetricsTokenProviderInterface` (`getToken(): ?string`) and point `details.token_provider` /
`metrics.token_provider` at the service instead of `token`. The bundle calls it per request — see
[CONFIGURATION.md](CONFIGURATION.md#runtime-token-providers).

## Using the checker in your own code

`Nowo\HealthKitBundle\Health\HealthChecker` (service `nowo_health_kit.health_checker`, autowirable) runs a group and
returns a `HealthReport`:

```php
$report = $checker->check('ready');
$report->isHealthy();         // false only on error
$report->toDetailedArray();   // same shape as the detailed JSON answer
```

Do not expose `toDetailedArray()` on a public page.

## Demo smoke

`make demo-smoke` boots `demo/symfony8`, expects HTTP 200 on `http://127.0.0.1:8197/` and stops the stack
(REQ-TEST-011). `make test-e2e` runs the Playwright suite against the running demo. See [DEMO-FRANKENPHP.md](DEMO-FRANKENPHP.md).
