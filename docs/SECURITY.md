# Security

Bundle-specific security analysis. The short GitHub reporting policy lives in [`.github/SECURITY.md`](../.github/SECURITY.md).

## Table of contents

- [Scope](#scope)
- [Attack surface](#attack-surface)
- [Threat model and mitigations](#threat-model-and-mitigations)
- [Secrets and cryptography](#secrets-and-cryptography)
- [Logging](#logging)
- [Resource limits and timeouts](#resource-limits-and-timeouts)
- [CSRF](#csrf)
- [Dependencies and updates](#dependencies-and-updates)
- [Release security checklist (12.4.1)](#release-security-checklist-1241)
- [AI security audit (REQ-SEC-004)](#ai-security-audit-req-sec-004)
- [Reporting a vulnerability](#reporting-a-vulnerability)

## Scope

Covers the HTTP endpoints `/health/live`, `/health/ready` and `/metrics`, the built-in probes (Doctrine, Redis,
Messenger, FrankenPHP), the probe runner, the Prometheus formatter and the configuration tree. It does **not** cover
the host's own probes and metrics providers, the firewall in front of the endpoints, or the security of the probed
services themselves.

## Attack surface

| Input | Where | Notes |
| --- | --- | --- |
| Anonymous HTTP `GET`/`HEAD` | `/health/live`, `/health/ready` (only when the host imports `config/routes/health.yaml`) | Status-only answer; anonymous readiness reused for `ready.public_cache_ttl` (worker memo + shared PSR-6 pool). |
| `Authorization: Bearer` header | health details, `/metrics` | Compared in constant time against configured tokens (≥ 16 chars), static or returned per request by a token provider service. |
| `REMOTE_ADDR` (or `getClientIp()` when `trust_forwarded_ip`) | health details | Matched against `details.allowed_ips`; with `trust_forwarded_ip` off, requests carrying `Forwarded` / `X-Forwarded-For` / `X-Real-IP` are never matched. |
| Session roles | `/metrics` | Only when `metrics.roles` is not empty and SecurityBundle is installed. |
| Configuration | `nowo_health_kit.*` | Validated at compile time; DSNs and tokens come from env vars. |
| Probe / provider output | host code | Messages and data are redacted, then shown to detail callers; provider families go to scrapers. |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **Information disclosure** (dependency names, hosts, versions, error messages) to the public | Public callers get the status only. Details require an allowlisted `REMOTE_ADDR` (loopback by default), a bearer token, or the explicit `details.public` dev switch. Exception messages (300 chars max) and host probe messages/data are redacted: URL userinfo up to the last `@`, scheme-less `user:pass@host`, `Bearer`/`Basic` credentials, and `key=value` / `key: value` / JSON / PHP-array pairs whose key contains password, secret, token, api key, auth or credential (`PGPASSWORD=`, `client_secret=`…). |
| **Same-host reverse proxy over loopback** (audit BX L1) | Every client would look like `127.0.0.1`. With `trust_forwarded_ip` off, requests carrying `Forwarded` / `X-Forwarded-For` / `X-Real-IP` are never granted details through the IP allowlist (token only). A proxy that adds none of these headers is not detectable: set `details.allowed_ips: []` (token only) or use trusted proxies + `trust_forwarded_ip: true`. Documented in CONFIGURATION, USAGE and the recipe. |
| **IP spoofing** through `X-Forwarded-For` to reach details | `REMOTE_ADDR` (TCP peer) is used by default; forwarded headers only with `details.trust_forwarded_ip: true`, which then relies on `framework.trusted_proxies`. |
| **Runtime token providers** (`details.token_provider`, `metrics.token_provider`) | Resolved on every request (never cached by the bundle), so a revoked or rotated token stops working immediately, also in FrankenPHP worker mode. Mutually exclusive with the static token (compile error). A provider returning null, a token shorter than 16 characters, or throwing is treated as "no token" (fail closed: 401 / 503 / status-only); the failure is logged as a warning with the provider and exception class names only (never the message, which could contain the value). Providers are only called for requests carrying an `Authorization: Bearer` header, so anonymous traffic cannot use them as an amplifier. Same constant-time, header-only comparison. |
| **Token brute force / timing attacks** | `hash_equals()`; tokens shorter than 16 characters are ignored (fail closed); only the `Authorization` header is read (no query string, so no leak into access logs, proxies or `Referer`). Rate limiting belongs to the host (reverse proxy or `symfony/rate-limiter`). |
| **Unauthorized metrics scraping** | `/metrics` is disabled by default (404). Enabled without token or roles it answers 503. An empty roles list grants nobody (no "allow everyone" fallback). Missing SecurityBundle disables the role path. Wrong or missing token: 401 with `WWW-Authenticate`. |
| **Denial of service** (hammering the database through `/health/ready`) | Anonymous readiness answers are reused for `ready.public_cache_ttl` (5 s): a per-worker memo plus a PSR-6 pool shared across processes (`ready.cache_pool`, default `cache.app`), so PHP-FPM and classic mode are covered too. With `cache_pool: null` under FPM/classic, every anonymous request runs the probes — rate-limit `/health/*` at the proxy. `/health/live` is not memoised (keep `live` probes cheap). Probes get their timeout (Redis connect/read, MySQL `MAX_EXECUTION_TIME` hint; DBAL and Messenger transports need connection/DSN timeouts, see CONFIGURATION) and every run — including `/metrics` probe gauges — stops starting probes once the group budget is spent. One cheap query/command per dependency, no N+1. |
| **Cache poisoning / stale health** | `Cache-Control: no-store, private` on every response; health routes are stateless. |
| **Accidental route exposure** | Controllers are not autoconfigured, so `routing.controllers` never loads them; routes exist only through the documented import (REQ-SF-004 rule 7, covered by an integration test). |
| **Injection** | No user input reaches queries or commands: Doctrine runs the platform dummy select, Redis `PING`, Messenger `getMessageCount()`. Prometheus label values and help texts are scrubbed to valid UTF-8 and escaped (`\r` normalised to an escaped line feed); metric and label names are validated (anchored with `D`, so no trailing newline); duplicate label sets are rejected per family and dropped (with a warning) when merging providers. JSON responses use `JsonResponse` encoding. |
| **SSRF** | Probe targets come from configuration only (DSN / service ids), never from the request. |
| **Cross-request state leaks (FrankenPHP worker)** | Services are stateless except the anonymous readiness memo (status + timestamp, no request data). Redis DSN connections are opened and closed per check. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). |
| **XSS / CSRF** | No HTML output and no state-changing endpoint. |

## Secrets and cryptography

- Tokens (`details.token`, `metrics.token`) must come from env vars or Symfony secrets — never committed. Tokens kept in application settings go through `*.token_provider` services; store them encrypted at rest when possible. The bundle
  never logs or echoes them. Generate them with a CSPRNG, e.g. `php -r 'echo bin2hex(random_bytes(24));'`.
- No cryptography beyond constant-time comparison.
- Demo tokens (`demo-*-token-change-me`) are placeholders for a local stack only.

## Logging

- Probe failures: `warning` on the `health` channel with the probe name, the status and a **redacted** reason.
- Metrics provider failures: `warning` with the provider class and the exception class only.
- Never logged: tokens, the `Authorization` header, DSNs, raw exception messages, request data.

## Resource limits and timeouts

See the timeout hierarchy in [CONFIGURATION.md](CONFIGURATION.md#timeout-hierarchy): probe timeouts (Redis
connect/read, DBAL connect options) < group budget < PHP `max_execution_time` < proxy write timeout. A probe that
overruns is reported as an error; probes not started within the budget are reported as errors too.

## CSRF

N/A — no session-authenticated mutation: every endpoint is a read-only `GET`/`HEAD`; `/metrics` uses a bearer token
(or a read-only role check).

## Dependencies and updates

- Runtime dependencies: Symfony components and PSR interfaces only; optional integrations are `suggest`.
- CI runs `composer audit --locked` on every matrix cell; Dependabot groups Composer and GitHub Actions updates.
- Security fixes are released as patch versions and noted in the changelog.

## Release security checklist (12.4.1)

| Item | How it is satisfied |
| --- | --- |
| SECURITY.md | This document + `.github/SECURITY.md`. |
| `.env` in `.gitignore` | Root and demo `.gitignore` exclude `.env`, `.env.local`, `.env.dev`. |
| No secrets | Tokens via env vars; demo placeholders only; GitGuardian on PRs. |
| Safe config / recipe | Recipe ships safe defaults (details for loopback only, metrics disabled). |
| Input / output | Header-only token, validated IPs/CIDRs, escaped Prometheus output, JSON encoding. |
| Dependencies | `composer audit --locked` in CI. |
| No-secret logs | See [Logging](#logging). |
| Cryptography | Constant-time comparison only. |
| Permissions / exposure | Opt-in routes; status-only public answers; fail-closed metrics. |
| Limits / DoS | Public readiness memo + shared pool, per-probe timeouts, group budget (also for `/metrics`). |
| AI security audit (REQ-SEC-004) | Pass (good) / Low, 2026-10-31 (Appendix BX) — see below. |

## AI security audit (REQ-SEC-004)

**Pass (good) / Low** — independent audit, 2026-10-31, revision `13f0a8b` (monorepo `BUNDLES_SECURITY_ANALYSIS.md`,
Appendix BX). No Critical, High or Medium findings. Low findings fixed before 1.0.0:

| Finding | Status |
| --- | --- |
| L1 — loopback allowlist behind a same-host reverse proxy | Fixed: forwarding headers disable the IP allowlist when `trust_forwarded_ip` is off; prominent docs + recipe warning. Residual: a proxy that adds no forwarding header is undetectable (documented). |
| L2 — anonymous readiness memo only effective in worker mode | Fixed: shared PSR-6 pool (`ready.cache_pool`), MySQL statement hint, budget in `checkAll()`, documented DB/transport timeouts and claims corrected. Residual: DBAL/Messenger socket timeouts remain connection/DSN configuration. |
| L3 — token providers called for anonymous requests | Fixed: providers only run when an `Authorization: Bearer` header is present (details and metrics). |
| L4 — Redis DSN probe always failing with Predis | Fixed: Predis `disconnect()`, phpredis/Relay `close()`, closing never masks the result, owned clients closed on every path. |
| L5 — sanitizer gaps; host probe output not redacted | Fixed: wider redaction and applied to probe messages and data. Residual: free-form secrets without a recognisable shape cannot be detected — probes must not echo secrets. |
| I1 / I2 — Prometheus names, `\r`, invalid UTF-8, duplicate series | Fixed. |
| I4 — silent token provider failures | Fixed: warning with class names only. |
| I9 — demo port on all interfaces | Fixed: bound to `127.0.0.1`. |

Accepted residuals (Info):

- **I3** — `hash_equals()` returns early on a length mismatch, leaking only the token length; negligible for random tokens of 16+ characters.
- **I5** — `metrics.roles` without SecurityBundle, or `/metrics` outside a firewall with the session: the role path never grants (fails closed).
- **I6** — anonymous `/metrics` callers can tell "not configured" (503) from "unauthorized" (401); with a token provider, anonymous requests always get 401.
- **I7** — `version` and `live.expose_client_ip` add the app version and the caller's own resolved IP to the public `/health/live` answer (both opt-in).
- **I8** — `trust_forwarded_ip: true` with permissive `framework.trusted_proxies` (`0.0.0.0/0`, `REMOTE_ADDR`) makes details spoofable through `X-Forwarded-For`; configure trusted proxies narrowly.

## Reporting a vulnerability

Please follow [`.github/SECURITY.md`](../.github/SECURITY.md) — do not open a public issue.
