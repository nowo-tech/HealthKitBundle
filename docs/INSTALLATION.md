# Installation

## Table of contents

- [Requirements](#requirements)
- [Install with Composer](#install-with-composer)
- [Symfony Flex recipe](#symfony-flex-recipe)
- [Manual setup (without Flex)](#manual-setup-without-flex)
- [Firewall and access control](#firewall-and-access-control)
- [Verify](#verify)

## Requirements

- PHP `>=8.2 <8.6` (Symfony 8.x requires PHP 8.4+)
- Symfony 7.4, 8.0 or 8.1
- Optional, per feature: `doctrine/doctrine-bundle` (Doctrine probes), `symfony/cache` + `ext-redis`/Predis/Relay (Redis DSN probe), `symfony/messenger` (queue probes), `symfony/security-core` (`metrics.roles`)

## Install with Composer

```bash
composer require nowo-tech/health-kit-bundle
```

## Symfony Flex recipe

The recipe lives in this repository under [`.symfony/recipe/nowo-tech/health-kit-bundle/1.0/`](../.symfony/recipe/nowo-tech/health-kit-bundle/1.0/) and installs:

- `config/packages/nowo_health_kit.yaml` — safe defaults with commented examples;
- `config/routes/nowo_health_kit.yaml` — the **health** route import (`/health/live`, `/health/ready`). The `/metrics` import is present but commented out.

Until the recipe is published on `symfony/recipes-contrib`, follow the manual steps.

## Manual setup (without Flex)

1. Register the bundle:

```php
// config/bundles.php
return [
    // ...
    Nowo\HealthKitBundle\NowoHealthKitBundle::class => ['all' => true],
];
```

2. Import the routes you want (they are opt-in; `routing.controllers` never loads them):

```yaml
# config/routes/nowo_health_kit.yaml
nowo_health_kit_health:
    resource: '@NowoHealthKitBundle/config/routes/health.yaml'
    # prefix: /_internal   # optional

nowo_health_kit_metrics:
    resource: '@NowoHealthKitBundle/config/routes/metrics.yaml'
```

3. Configure the probes — see [CONFIGURATION.md](CONFIGURATION.md).

## Firewall and access control

The endpoints carry their own access rules (status-only for anonymous callers, token/IP for details, token/role for
metrics), so they must be reachable without a login. With SecurityBundle:

```yaml
security:
    access_control:
        - { path: ^/health/(live|ready)$, roles: PUBLIC_ACCESS }
        - { path: ^/metrics$, roles: PUBLIC_ACCESS }
```

When you rely on `metrics.roles`, keep `/metrics` inside a firewall that has the session (a stateless firewall
would not see the logged-in user).

## Verify

```bash
php bin/console debug:router | grep nowo_health_kit
curl -s http://localhost/health/live    # {"status":"ok"}
curl -s http://localhost/health/ready   # {"status":"ok"} or 503 {"status":"error"}
```
