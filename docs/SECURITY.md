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
| Anonymous HTTP `GET`/`HEAD` | `/health/live`, `/health/ready` (only when the host imports `config/routes/health.yaml`) | Status-only answer; readiness memoised per worker. |
| `Authorization: Bearer` header | health details, `/metrics` | Compared in constant time against configured tokens (≥ 16 chars). |
| `REMOTE_ADDR` (or `getClientIp()` when `trust_forwarded_ip`) | health details | Matched against `details.allowed_ips`. |
| Session roles | `/metrics` | Only when `metrics.roles` is not empty and SecurityBundle is installed. |
| Configuration | `nowo_health_kit.*` | Validated at compile time; DSNs and tokens come from env vars. |
| Probe / provider output | host code | Messages and data are shown to detail callers and scrapers. |

## Threat model and mitigations

| Threat | Mitigation |
| --- | --- |
| **Information disclosure** (dependency names, hosts, versions, error messages) to the public | Public callers get the status only. Details require an allowlisted `REMOTE_ADDR` (loopback by default), a bearer token, or the explicit `details.public` dev switch. Exception messages are sanitized (DSN credentials and `password=`/`token=` pairs redacted, 300 chars max). |
| **IP spoofing** through `X-Forwarded-For` to reach details | `REMOTE_ADDR` (TCP peer) is used by default; forwarded headers only with `details.trust_forwarded_ip: true`, which then relies on `framework.trusted_proxies`. |
| **Token brute force / timing attacks** | `hash_equals()`; tokens shorter than 16 characters are ignored (fail closed); only the `Authorization` header is read (no query string, so no leak into access logs, proxies or `Referer`). Rate limiting belongs to the host (reverse proxy or `symfony/rate-limiter`). |
| **Unauthorized metrics scraping** | `/metrics` is disabled by default (404). Enabled without token or roles it answers 503. An empty roles list grants nobody (no "allow everyone" fallback). Missing SecurityBundle disables the role path. Wrong or missing token: 401 with `WWW-Authenticate`. |
| **Denial of service** (hammering the database through `/health/ready`) | Anonymous readiness answers are memoised per worker (`ready.public_cache_ttl`, 5 s). Probes are bounded by per-probe timeouts and a group budget; one cheap query/command per dependency, no N+1. |
| **Cache poisoning / stale health** | `Cache-Control: no-store, private` on every response; health routes are stateless. |
| **Accidental route exposure** | Controllers are not autoconfigured, so `routing.controllers` never loads them; routes exist only through the documented import (REQ-SF-004 rule 7, covered by an integration test). |
| **Injection** | No user input reaches queries or commands: Doctrine runs the platform dummy select, Redis `PING`, Messenger `getMessageCount()`. Prometheus label values and help texts are escaped; metric and label names are validated. JSON responses use `JsonResponse` encoding. |
| **SSRF** | Probe targets come from configuration only (DSN / service ids), never from the request. |
| **Cross-request state leaks (FrankenPHP worker)** | Services are stateless except the anonymous readiness memo (status + timestamp, no request data). Redis DSN connections are opened and closed per check. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md). |
| **XSS / CSRF** | No HTML output and no state-changing endpoint. |

## Secrets and cryptography

- Tokens (`details.token`, `metrics.token`) must come from env vars or Symfony secrets — never committed. The bundle
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
| Limits / DoS | Public readiness memo, per-probe timeouts, group budget. |
| AI security audit (REQ-SEC-004) | Pending independent audit before the first tag — see below. |

## AI security audit (REQ-SEC-004)

Not yet audited. The first release (1.0.0) is blocked until an independent AI security audit records a passing grade
in the monorepo `BUNDLES_SECURITY_ANALYSIS.md`.

## Reporting a vulnerability

Please follow [`.github/SECURITY.md`](../.github/SECURITY.md) — do not open a public issue.
