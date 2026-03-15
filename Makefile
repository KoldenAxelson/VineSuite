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

test:                        ## Run PHP test suite (use: make test or make test F=Transfer)
	docker compose exec app ./vendor/bin/pest $(if $(F),--filter="$(F)",)

test-coverage:               ## Run tests with coverage report
	docker compose exec app php artisan test --coverage

lint:                        ## Run Laravel Pint (code style)
	docker compose exec app ./vendor/bin/pint

analyse:                     ## Run PHPStan (static analysis)
	docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=512M

testsuite:                   ## Full QA: filtered Pest → full Pest → Pint → PHPStan (use: make testsuite F="Transfer Addition")
	@START=$$(date +%s); PEST_OK=0; PINT_OK=0; STAN_OK=0; \
	if [ -n "$(F)" ]; then \
		for filter in $(F); do \
			echo "══════ PEST (filter: $$filter) ══════"; \
			docker compose exec app ./vendor/bin/pest --filter="$$filter" || exit 1; \
			echo ""; \
		done; \
	fi; \
	echo "══════ PEST (full suite) ══════"; \
	docker compose exec app ./vendor/bin/pest && PEST_OK=1; \
	echo ""; \
	echo "══════ PINT ══════"; \
	docker compose exec app ./vendor/bin/pint && PINT_OK=1; \
	echo ""; \
	echo "══════ PHPSTAN ══════"; \
	docker compose exec app ./vendor/bin/phpstan analyse --memory-limit=512M && STAN_OK=1; \
	echo ""; \
	END=$$(date +%s); ELAPSED=$$((END - START)); \
	echo "┌──────────────────────────────────────┐"; \
	echo "│         TESTSUITE SUMMARY            │"; \
	echo "├──────────────────────────────────────┤"; \
	if [ $$PEST_OK -eq 1 ]; then echo "│  Pest ························ PASS  │"; else echo "│  Pest ························ FAIL  │"; fi; \
	if [ $$PINT_OK -eq 1 ]; then echo "│  Pint ························ PASS  │"; else echo "│  Pint ························ FAIL  │"; fi; \
	if [ $$STAN_OK -eq 1 ]; then echo "│  PHPStan ····················· PASS  │"; else echo "│  PHPStan ····················· FAIL  │"; fi; \
	echo "├──────────────────────────────────────┤"; \
	printf "│  Duration: %-26s│\n" "$${ELAPSED}s"; \
	echo "└──────────────────────────────────────┘"; \
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
