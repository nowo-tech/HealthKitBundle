# HealthKitBundle Constitution

## Core Principles

### I. Documented integrator contract
Product behavior lives in `specs/001-baseline/spec.md`, `docs/SPEC-DRIVEN-DEVELOPMENT.md`, and integrator docs (`USAGE.md`, `CONFIGURATION.md`). Demos are illustrative unless promoted in the spec.

### II. Spec-first, test-proven
PHPUnit and PHPStan (and Vitest when frontend exists) are the mechanical proof. Behavioral changes require tests.

### III. 100% code inventory traceability
Every production file under `src/` must appear in `specs/001-baseline/code-inventory.md`. New files require spec updates in the same PR.

### IV. Cursor + Spec Kit
GitHub Spec Kit is initialized with **Cursor Agent** (`cursor-agent`). Skills live in `.cursor/skills/speckit-*`.

### V. Symfony compatibility
Follow declared PHP/Symfony ranges in `composer.json` and README badges.

### VI. Fail closed, status only
Public callers get a status only; details, metrics and routes are opt-in or token/role/IP gated. An empty allowlist or
roles list grants nobody. Tokens are compared in constant time and never logged.

### VII. Bounded and worker-safe
Every probe bounds its I/O with its timeout; groups have a budget. Services keep no per-request state (FrankenPHP
worker mode); the anonymous readiness memo is the only deliberate exception.

## Governance
Amendments update this file, baseline spec when principles affect behavior, and `CHANGELOG.md` when consumer-visible.

**Version**: 1.0.0 | **Ratified**: 2026-10-31 | **Last Amended**: 2026-10-31
