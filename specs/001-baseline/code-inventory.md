# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md)  
**Package**: `nowo-tech/health-kit-bundle`  
**Last audited**: 2026-10-31

Scope: every file under `src/` plus the shipped route imports under `config/routes/` (the bundle path is the package
root). Audit command: `find src config -type f | wc -l` → **32**.

## Bundle and dependency injection

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/NowoHealthKitBundle.php` | Bundle and DI | FR-BUNDLE-001 |
| `src/DependencyInjection/Configuration.php` | Bundle and DI | FR-CFG-001 |
| `src/DependencyInjection/NowoHealthKitExtension.php` | Bundle and DI | FR-CFG-002, FR-CFG-003 |
| `src/DependencyInjection/Compiler/DoctrineProbePass.php` | Bundle and DI | FR-CFG-004 |
| `src/DependencyInjection/Compiler/HealthProbePass.php` | Bundle and DI | FR-CFG-005 |
| `src/Attribute/AsHealthProbe.php` | Probes and runner | FR-RUN-004, FR-CFG-003 |

## Routes

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `config/routes/health.yaml` | Bundle and DI | FR-ROUTE-001, FR-HTTP-001, FR-HTTP-002 |
| `config/routes/metrics.yaml` | Bundle and DI | FR-ROUTE-001, FR-HTTP-006 |

## HTTP layer

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Controller/HealthController.php` | HTTP | FR-HTTP-001, FR-HTTP-002, FR-HTTP-003 |
| `src/Controller/MetricsController.php` | HTTP | FR-HTTP-006 |
| `src/Http/DetailsAccessPolicy.php` | HTTP | FR-HTTP-004 |
| `src/Http/BearerToken.php` | HTTP | FR-HTTP-005 |

## Probes

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Probe/HealthProbeInterface.php` | Probes and runner | FR-PROBE-001 |
| `src/Probe/ProbeResult.php` | Probes and runner | FR-PROBE-001 |
| `src/Probe/HealthStatus.php` | Probes and runner | FR-PROBE-002 |
| `src/Probe/DoctrineConnectionProbe.php` | Probes and runner | FR-PROBE-003 |
| `src/Probe/RedisProbe.php` | Probes and runner | FR-PROBE-004 |
| `src/Probe/MessengerTransportProbe.php` | Probes and runner | FR-PROBE-005 |
| `src/Probe/FrankenPhpProbe.php` | Probes and runner | FR-PROBE-006 |
| `src/Runtime/FrankenPhpRuntime.php` | Probes and runner | FR-PROBE-007 |

## Runner

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Health/HealthChecker.php` | Probes and runner | FR-RUN-001 |
| `src/Health/RegisteredProbe.php` | Probes and runner | FR-RUN-001, FR-CFG-005 |
| `src/Health/ProbeReport.php` | Probes and runner | FR-RUN-002 |
| `src/Health/HealthReport.php` | Probes and runner | FR-RUN-002 |
| `src/Health/ErrorSanitizer.php` | Probes and runner | FR-RUN-003 |

## Metrics

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Metrics/MetricsProviderInterface.php` | Metrics | FR-MET-005 |
| `src/Metrics/MetricFamily.php` | Metrics | FR-MET-001 |
| `src/Metrics/MetricSample.php` | Metrics | FR-MET-001 |
| `src/Metrics/MetricType.php` | Metrics | FR-MET-001 |
| `src/Metrics/PrometheusTextFormatter.php` | Metrics | FR-MET-002 |
| `src/Metrics/MetricsCollector.php` | Metrics | FR-MET-003 |
| `src/Metrics/ProbeMetricsProvider.php` | Metrics | FR-MET-004 |

## Coverage summary

| Category | Files |
| --- | --- |
| Bundle and dependency injection | 6 |
| Routes | 2 |
| HTTP layer | 4 |
| Probes | 8 |
| Runner | 5 |
| Metrics | 7 |
| **Total production sources** | **32 / 32** |
