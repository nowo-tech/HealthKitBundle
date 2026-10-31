# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/health-kit-bundle` (`symfony-bundle`) |
| Audited revision | `1.0.0` (unreleased, branch `feat/1.0.0`) |
| Audit date | 2026-10-31 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`, 0 KO / 0 WARN); demo run in worker mode |
| **Verdict** | ✅ **Viable** — services are immutable except one deliberate per-worker memo (anonymous readiness status) that holds no request or user data |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Findings](#findings)
- [Usage recommendations in worker mode](#usage-recommendations-in-worker-mode)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once per worker and serves many requests with the same container. Two
scenarios are evaluated:

- **A — kernel not rebooted, `services_resetter` still runs.**
- **B — no reset at all:** any state kept in a service survives into the next request.

A bundle safe under **B** is safe under **A**, classic mode and PHP-FPM.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Only `HealthController::$publicReady` (status string, healthy flag, timestamp) — intended to survive requests |
| Static properties / `static` locals | ✅ | None (`BearerToken` has static pure methods only) |
| `ResetInterface` / `kernel.reset` | ✅ N/A | Nothing must be reset; resetting the memo would only cost one extra probe run |
| Request / user captured in services | ✅ | `Request` is a controller argument; `FrankenPhpRuntime` reads `RequestStack::getMainRequest()` per call |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None; environment fallback is `getenv()` (read-only) |
| Doctrine | ✅ | Probes ping injected DBAL connections per call; nothing kept |
| Resources (sockets) held open | ✅ | Redis DSN connections are opened and closed per check (`finally`); injected Redis clients are the host's |
| Memory growth across requests | ✅ | No accumulating arrays; reports are built per call |
| Blocking I/O and timeouts | ✅ | Per-probe timeouts (Redis connect/read) + group budget; overruns reported |
| Output, headers, `exit` | ✅ | Responses only |
| PHPStan FrankenPHP rulesets / Igor | ✅ | 0 errors / 0 KO |

## Services reviewed

| Service | Mutable state | Scenario A | Scenario B |
|---------|---------------|------------|------------|
| `Nowo\HealthKitBundle\Controller\HealthController` | `publicReady` memo (status + timestamp) — `@igor-ignore` with justification | ✅ | ✅ |
| `Nowo\HealthKitBundle\Controller\MetricsController` | none (`readonly`) | ✅ | ✅ |
| `nowo_health_kit.health_checker` (`HealthChecker`) | none (`readonly`); reports built per call | ✅ | ✅ |
| `nowo_health_kit.details_access_policy` | none (`readonly`) | ✅ | ✅ |
| `nowo_health_kit.metrics_collector`, `nowo_health_kit.prometheus_formatter`, `nowo_health_kit.metrics.probe_provider` | none | ✅ | ✅ |
| `nowo_health_kit.frankenphp_runtime` | none; reads the current main request per call | ✅ | ✅ |
| `nowo_health_kit.probe.*` (Doctrine, Redis, Messenger, FrankenPHP) | none (`readonly`) | ✅ | ✅ |
| `nowo_health_kit.error_sanitizer` | none | ✅ | ✅ |

## Findings

No worker-mode findings under scenario **B**. The readiness memo is deliberate: it bounds the load anonymous traffic
can put on dependencies (one probe run per worker per `ready.public_cache_ttl`). Each worker has its own memo, so
with N workers up to N runs happen per TTL window.

## Usage recommendations in worker mode

- Host probes and metrics providers must stay stateless and bound their I/O with the probe timeout.
- Prefer atomic counters (Redis `INCRBY`, SQL) in metrics providers: in-memory counters are per worker and lost on restart.
- Use `probes.frankenphp.require_worker: true` when the deployment must run in worker mode.

## Re-audit triggers

Re-run this audit when a change adds a property to a service, an event listener, a cache, a persistent connection,
or any read of `$_SERVER` / `$_ENV` / static state at runtime.
