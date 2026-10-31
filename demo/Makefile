DEMOS := symfony8

.PHONY: help clean test-all test-coverage-all verify-all release-check release-verify demo-smoke update-bundle-all
.PHONY: $(foreach demo,$(DEMOS),up-$(demo) down-$(demo) restart-$(demo) build-$(demo) install-$(demo) update-bundle-$(demo) shell-$(demo) logs-$(demo) test-$(demo) test-coverage-$(demo) verify-$(demo))
.PHONY: up down restart build install update-bundle test shell logs test-coverage verify

help:
	@echo "Health Kit Bundle - Demo Commands"
	@echo ""
	@echo "Usage: make <target>"
	@echo ""
	@echo "  up-symfony8 / down-symfony8 / restart-symfony8"
	@echo "  build-symfony8, install-symfony8, update-bundle-symfony8"
	@echo "  test-symfony8, test-coverage-symfony8"
	@echo "  release-check    Run update-bundle-all + test-all + demo-smoke"
	@echo "  demo-smoke       REQ-TEST-011: up -> HTTP 200 on :8197 -> down"
	@echo "  release-verify   Alias of demo-smoke"
	@echo ""
	@echo "Access: Symfony 8 http://localhost:8197"

define target_template
$(1)-$(2):
	@$(MAKE) -C $(2) $(1)
endef

$(foreach d,$(DEMOS),$(eval $(call target_template,up,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,down,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,restart,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,build,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,install,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,update-bundle,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,shell,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,logs,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,test,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,test-coverage,$(d))))
$(foreach d,$(DEMOS),$(eval $(call target_template,verify,$(d))))

test-all:
	@$(foreach d,$(DEMOS),$(MAKE) test-$(d);)

test-coverage-all:
	@$(foreach d,$(DEMOS),$(MAKE) test-coverage-$(d);)

verify-all:
	@$(foreach d,$(DEMOS),$(MAKE) verify-$(d);)

update-bundle-all:
	@$(foreach d,$(DEMOS),$(MAKE) update-bundle-$(d);)

up:
	@[ -n "$(DEMO)" ] || { echo "Usage: make up DEMO=symfony8"; exit 1; }
	@$(MAKE) up-$(DEMO)

down:
	@[ -n "$(DEMO)" ] || { echo "Usage: make down DEMO=symfony8"; exit 1; }
	@$(MAKE) down-$(DEMO)

restart:
	@[ -n "$(DEMO)" ] || { echo "Usage: make restart DEMO=symfony8"; exit 1; }
	@$(MAKE) restart-$(DEMO)

build:
	@[ -n "$(DEMO)" ] || { echo "Usage: make build DEMO=symfony8"; exit 1; }
	@$(MAKE) build-$(DEMO)

install:
	@[ -n "$(DEMO)" ] || { echo "Usage: make install DEMO=symfony8"; exit 1; }
	@$(MAKE) install-$(DEMO)

update-bundle:
	@[ -n "$(DEMO)" ] || { echo "Usage: make update-bundle DEMO=symfony8"; exit 1; }
	@$(MAKE) update-bundle-$(DEMO)

test:
	@[ -n "$(DEMO)" ] || { echo "Usage: make test DEMO=symfony8"; exit 1; }
	@$(MAKE) test-$(DEMO)

test-coverage:
	@[ -n "$(DEMO)" ] || { echo "Usage: make test-coverage DEMO=symfony8"; exit 1; }
	@$(MAKE) test-coverage-$(DEMO)

shell:
	@[ -n "$(DEMO)" ] || { echo "Usage: make shell DEMO=symfony8"; exit 1; }
	@$(MAKE) shell-$(DEMO)

logs:
	@[ -n "$(DEMO)" ] || { echo "Usage: make logs DEMO=symfony8"; exit 1; }
	@$(MAKE) logs-$(DEMO)

verify:
	@[ -n "$(DEMO)" ] || { echo "Usage: make verify DEMO=symfony8"; exit 1; }
	@$(MAKE) verify-$(DEMO)

# REQ-TEST-011: boot demo stack and expect HTTP 200
demo-smoke:
	@for demo in $(DEMOS); do \
		port=8197; \
		echo "Demo smoke: $$demo (http://127.0.0.1:$$port)"; \
		$(MAKE) up-$$demo || exit 1; \
		code=000; \
		for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do \
			code=$$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 http://127.0.0.1:$$port 2>/dev/null || echo "000"); \
			if [ "$$code" = "200" ]; then break; fi; \
			sleep 2; \
		done; \
		$(MAKE) down-$$demo 2>/dev/null || true; \
		if [ "$$code" != "200" ]; then echo "$$demo failed with HTTP $$code"; exit 1; fi; \
		echo "$$demo OK (HTTP $$code)"; \
	done

release-verify: demo-smoke

release-check: update-bundle-all test-all demo-smoke

clean:
	@rm -rf symfony8/var
	@echo "Demo caches cleaned"


# REQ-MAKE-008: update-deps-all (optional: monorepo helper absent on standalone GitHub Actions checkout)
BUNDLE_ROOT := $(abspath $(dir $(lastword $(MAKEFILE_LIST)))/..)
-include $(BUNDLE_ROOT)/../.scripts/Makefile.demo-aggregate-update-deps.mk
