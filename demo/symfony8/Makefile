.PHONY: help up down composer-install down-dev build install shell logs test test-coverage cache-clear update-bundle ensure-up restart verify setup seed-queue test-e2e demo-screenshots

help:
	@echo "Symfony 8 Demo - Health Kit Bundle"
	@echo "  make up               Start containers (FrankenPHP + MySQL + Redis), create the queue, seed 12 messages"
	@echo "  make down             Stop containers"
	@echo "  make down-dev         Stop containers (keep volumes; REQ-MAKE-007)"
	@echo "  make restart          Restart containers"
	@echo "  make build            Build image (no cache)"
	@echo "  make install          Install dependencies"
	@echo "  make setup            Wait for MySQL and create the Messenger table"
	@echo "  make seed-queue       Reset the async queue to 12 waiting messages (degraded Messenger probe)"
	@echo "  make shell            Open shell in php container"
	@echo "  make logs             Stream container logs"
	@echo "  make test             Run tests"
	@echo "  make test-coverage    Run tests with coverage"
	@echo "  make update-bundle    Update nowo-tech/health-kit-bundle and clear cache"
	@echo "  make verify           HTTP 200 on the demo home"
	@echo "  make test-e2e         Playwright e2e (REQ-DEMO-013)"
	@echo "  make demo-screenshots Refresh docs/images/demo/*.webp (README screenshots)"

composer-install:
	@echo "Installing Composer dependencies (ephemeral container)..."
	$(DC) run --rm --no-deps --entrypoint "" -e COMPOSER_MEMORY_LIMIT=-1 php \
		composer install --no-interaction

up:
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	@$(MAKE) composer-install
	$(DC) up -d
	@echo "Waiting for FrankenPHP to stay up..."
	@ok=0; \
	for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do \
		if $(COMPOSE) exec -T php true 2>/dev/null; then ok=1; break; fi; \
		sleep 2; \
	done; \
	if [ "$$ok" != "1" ]; then \
		echo "Demo php container failed to stay up. Recent logs:"; \
		$(COMPOSE) logs --tail=100 php || true; \
		exit 1; \
	fi
	@$(MAKE) setup
	@$(MAKE) seed-queue
	@PORT=$$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	[ -z "$$PORT" ] && PORT=$$(grep "^PORT=" .env.example 2>/dev/null | cut -d= -f2 | tr -d '\r'); \
	echo "Demo started at: http://localhost:$$PORT"

# Waits for MySQL (up to 120 s) and creates the Messenger table.
setup:
	@i=0; \
	until $(COMPOSE) exec -T php php bin/console dbal:run-sql "SELECT 1" >/dev/null 2>&1; do \
		i=$$((i+1)); \
		if [ $$i -ge 60 ]; then echo "MySQL did not become reachable"; $(COMPOSE) logs --tail=50 database || true; exit 1; fi; \
		sleep 2; \
	done
	$(COMPOSE) exec -T php php bin/console messenger:setup-transports --no-interaction

seed-queue:
	$(COMPOSE) exec -T php php bin/console app:demo:seed-queue --count=12

ensure-up:
	@if ! $(COMPOSE) exec -T php true 2>/dev/null; then \
		echo "Starting demo container..."; \
		$(MAKE) up; \
	fi

down:
	$(COMPOSE) down

# Stop containers without removing volumes (REQ-MAKE-007)
down-dev:
	$(COMPOSE) down --remove-orphans

restart: ensure-up
	$(COMPOSE) restart

build:
	$(COMPOSE) build --no-cache

install: ensure-up
	@if [ ! -f .env ]; then cp .env.example .env 2>/dev/null || true; fi
	$(COMPOSE) exec -T php composer install --no-interaction
	$(COMPOSE) exec -T php php bin/console cache:clear

shell: ensure-up
	$(COMPOSE) exec php sh

logs:
	$(COMPOSE) logs -f

test: ensure-up
	$(COMPOSE) exec -T -e APP_ENV=test php php bin/phpunit

test-coverage: ensure-up
	$(COMPOSE) exec -T -e APP_ENV=test php php bin/phpunit --coverage-text

cache-clear: ensure-up
	$(COMPOSE) exec -T php php bin/console cache:clear

update-bundle: ensure-up
	$(COMPOSE) exec -T php composer update nowo-tech/health-kit-bundle --no-interaction
	$(COMPOSE) exec -T php php bin/console cache:clear --env=dev
	$(COMPOSE) exec -T php php bin/console assets:install public --no-interaction

verify: ensure-up
	@code=$$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:$${PORT:-8197} 2>/dev/null || echo "000"); \
	if [ "$$code" != "200" ]; then echo "Healthcheck failed: $$code"; exit 1; fi; \
	echo "Healthcheck OK ($$code)"

# REQ-DEMO-013: Playwright runs in the official image (host network, 1 worker).
PLAYWRIGHT_IMAGE ?= mcr.microsoft.com/playwright:v1.63.0-jammy
DEMO_PORT = $$(grep "^PORT=" .env 2>/dev/null | cut -d= -f2 | tr -d '\r'); PORT=$${PORT:-8197}

test-e2e: ensure-up seed-queue
	@mkdir -p e2e/node_modules
	@PORT=$(DEMO_PORT); \
	docker run --rm --network host --user "$$(id -u):$$(id -g)" -e HOME=/tmp \
		-v "$(CURDIR)/e2e:/e2e" -w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=chromium --workers=1'

# Reseeds the queue, captures PNGs with the screenshots project, converts them to WebP (sharp, quality 90) under
# docs/images/demo/ and deletes the PNGs.
demo-screenshots: ensure-up seed-queue
	@mkdir -p ../../docs/images/demo e2e/node_modules
	@PORT=$(DEMO_PORT); \
	docker run --rm --network host --user "$$(id -u):$$(id -g)" -e HOME=/tmp \
		-v "$(CURDIR)/e2e:/e2e" \
		-v "$(CURDIR)/../../docs/images/demo:/screenshots" \
		-w /e2e \
		-e DEMO_BASE_URL=http://127.0.0.1:$$PORT \
		-e SCREENSHOT_DIR=/screenshots \
		$(PLAYWRIGHT_IMAGE) \
		bash -lc 'npm ci --no-fund --no-audit && npx playwright test --project=screenshots --workers=1 && node scripts/to-webp.mjs /screenshots 90'
	@echo "Screenshots written under docs/images/demo/ (WebP)"

# REQ-MAKE-008: update-deps (optional: monorepo helper absent on standalone GitHub Actions checkout)
SERVICE_PHP := php
# Prefer Compose V2 plugin (GitHub Actions / modern Docker Desktop); fall back to docker-compose V1 (REQ-MAKE-010).
# Resolve `docker` via `command -v` so the local `docker/` directory cannot shadow the CLI when PATH has empty components.
DOCKER_BIN := $(shell command -v docker 2>/dev/null || echo docker)
COMPOSE ?= $(shell $(DOCKER_BIN) compose version >/dev/null 2>&1 && echo "$(DOCKER_BIN) compose" || echo "docker-compose")
DC = :; $(COMPOSE)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/../..)
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-update-deps.mk
