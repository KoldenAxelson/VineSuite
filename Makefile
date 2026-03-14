# VineSuite Development Commands
# Usage: make <target>

.PHONY: up down restart build logs test testsuite quicktest migrate seed fresh shell horizon ps help

# ─── Docker ───────────────────────────────────────────────────────
up:                          ## Start all services
	docker compose up -d

down:                        ## Stop all services
	docker compose down

build:                       ## Rebuild Docker images
	docker compose build --no-cache

restart:                     ## Restart all services
	docker compose down && docker compose up -d

ps:                          ## Show running services and health
	docker compose ps

logs:                        ## Tail all service logs
	docker compose logs -f

logs-api:                    ## Tail API logs only
	docker compose logs -f app

logs-horizon:                ## Tail Horizon queue worker logs
	docker compose logs -f horizon

# ─── Laravel (API) ────────────────────────────────────────────────
shell:                       ## Open a bash shell in the API container
	docker compose exec app bash

migrate:                     ## Run migrations (central + tenant)
	docker compose exec app php artisan migrate
	docker compose exec app php artisan tenants:migrate

seed:                        ## Run database seeders
	docker compose exec app php artisan db:seed

fresh:                       ## Drop all tables, re-migrate, re-seed
	docker compose exec app php artisan migrate:fresh --seed
	docker compose exec app php artisan tenants:migrate --fresh --seed

test:                        ## Run PHP test suite (use: make test or make test F=Transfer)
	docker compose exec app ./vendor/bin/pest $(if $(F),--filter="$(F)",)

test-coverage:               ## Run tests with coverage report
	docker compose exec app php artisan test --coverage

lint:                        ## Run Laravel Pint (code style)
	docker compose exec app ./vendor/bin/pint

analyse:                     ## Run PHPStan (static analysis)
	docker compose exec app ./vendor/bin/phpstan analyse

testsuite:                   ## Full QA: filtered Pest → full Pest → Pint → PHPStan (use: make testsuite F="Transfer Addition")
	@START=$$(date +%s); PEST_OK=0; PINT_OK=0; STAN_OK=0; \
	PEST_COUNT=""; PINT_COUNT=""; STAN_COUNT=""; \
	if [ -n "$(F)" ]; then \
		for filter in $(F); do \
			echo "══════ PEST (filter: $$filter) ══════"; \
			docker compose exec app ./vendor/bin/pest --filter="$$filter" || exit 1; \
			echo ""; \
		done; \
	fi; \
	echo "══════ PEST (full suite) ══════"; \
	PEST_OUT=$$(docker compose exec app ./vendor/bin/pest 2>&1) && PEST_OK=1; \
	echo "$$PEST_OUT"; \
	PEST_COUNT=$$(echo "$$PEST_OUT" | grep -oP '\d+(?= passed)' | tail -1); \
	echo ""; \
	echo "══════ PINT ══════"; \
	PINT_OUT=$$(docker compose exec app ./vendor/bin/pint 2>&1) && PINT_OK=1; \
	echo "$$PINT_OUT"; \
	PINT_COUNT=$$(echo "$$PINT_OUT" | grep -oP '\d+(?= files)' | tail -1); \
	echo ""; \
	echo "══════ PHPSTAN ══════"; \
	STAN_OUT=$$(docker compose exec app ./vendor/bin/phpstan analyse 2>&1) && STAN_OK=1; \
	echo "$$STAN_OUT"; \
	STAN_COUNT=$$(echo "$$STAN_OUT" | grep -oP '\d+/\d+' | tail -1 | grep -oP '\d+$$'); \
	echo ""; \
	END=$$(date +%s); ELAPSED=$$((END - START)); \
	W=48; \
	echo "┌$$(printf '%*s' $$W '' | tr ' ' '─')┐"; \
	TITLE="TESTSUITE SUMMARY"; \
	PAD=$$(( (W - $${#TITLE}) / 2 )); \
	printf "│%*s%s%*s│\n" $$PAD "" "$$TITLE" $$((W - PAD - $${#TITLE})) ""; \
	echo "├$$(printf '%*s' $$W '' | tr ' ' '─')┤"; \
	if [ $$PEST_OK -eq 1 ]; then PEST_S="✅ $$PEST_COUNT passed"; else PEST_S="❌ FAIL"; fi; \
	PEST_LABEL="Pest"; PEST_DOTS=$$((W - 4 - $${#PEST_LABEL} - $${#PEST_S})); \
	printf "│  %s%.*s%s  │\n" "$$PEST_LABEL" $$PEST_DOTS "··············································" "$$PEST_S"; \
	if [ $$PINT_OK -eq 1 ]; then PINT_S="✅ $$PINT_COUNT files"; else PINT_S="❌ FAIL"; fi; \
	PINT_LABEL="Pint"; PINT_DOTS=$$((W - 4 - $${#PINT_LABEL} - $${#PINT_S})); \
	printf "│  %s%.*s%s  │\n" "$$PINT_LABEL" $$PINT_DOTS "··············································" "$$PINT_S"; \
	if [ $$STAN_OK -eq 1 ]; then STAN_S="✅ $$STAN_COUNT files"; else STAN_S="❌ FAIL"; fi; \
	STAN_LABEL="PHPStan"; STAN_DOTS=$$((W - 4 - $${#STAN_LABEL} - $${#STAN_S})); \
	printf "│  %s%.*s%s  │\n" "$$STAN_LABEL" $$STAN_DOTS "··············································" "$$STAN_S"; \
	echo "├$$(printf '%*s' $$W '' | tr ' ' '─')┤"; \
	DUR_S="Duration: $${ELAPSED}s"; DUR_PAD=$$((W - 2 - $${#DUR_S})); \
	printf "│  %s%*s│\n" "$$DUR_S" $$DUR_PAD ""; \
	echo "└$$(printf '%*s' $$W '' | tr ' ' '─')┘"; \
	if [ $$PEST_OK -eq 0 ] || [ $$PINT_OK -eq 0 ] || [ $$STAN_OK -eq 0 ]; then exit 1; fi

quicktest:                   ## Filtered Pest only, no full suite (use: make quicktest F="Transfer Addition")
	@if [ -z "$(F)" ]; then echo "Usage: make quicktest F=\"FilterName\""; exit 1; fi
	@for filter in $(F); do \
		echo "══════ PEST (filter: $$filter) ══════"; \
		docker compose exec app ./vendor/bin/pest --filter="$$filter" || exit 1; \
		echo ""; \
	done
	@echo "✅ All filtered tests passed."

# ─── KMP Shared Core ─────────────────────────────────────────────
test-shared:                 ## Run KMP shared core JVM tests
	cd shared && ./gradlew :shared:jvmTest

# ─── Widgets ──────────────────────────────────────────────────────
widgets-dev:                 ## Start widget dev server
	cd widgets && npm run dev

widgets-build:               ## Build widgets for production
	cd widgets && npm run build

# ─── VineBook ─────────────────────────────────────────────────────
vinebook-dev:                ## Start VineBook dev server
	cd vinebook && npm run dev

vinebook-build:              ## Build VineBook for production
	cd vinebook && npm run build

# ─── Utilities ────────────────────────────────────────────────────
mail:                        ## Open Mailpit UI (caught emails)
	open http://localhost:8025

horizon-ui:                  ## Open Horizon dashboard
	open http://localhost:8000/horizon

help:                        ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
