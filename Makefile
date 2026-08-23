# Convenience wrapper around the Docker stack. Every target works from a clean
# clone with only Docker installed — PHP/Node are pulled as throwaway images so
# nothing needs to be on the host.

.DEFAULT_GOAL := help
COMPOSE := docker compose

.PHONY: help
help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

.env: ## Create .env from the example if missing
	@[ -f .env ] || cp .env.example .env

vendor: ## Install PHP dependencies (via an ephemeral Composer image)
	docker run --rm -v "$(CURDIR)":/app -w /app composer:2 install --no-interaction --prefer-dist

public/build: ## Build frontend assets (via an ephemeral Node image)
	docker run --rm -v "$(CURDIR)":/app -w /app node:20-alpine sh -c "npm ci && npm run build"

.PHONY: setup
setup: .env vendor public/build ## One-time bootstrap: env + PHP deps + assets

.PHONY: up
up: setup ## Bootstrap (if needed) and start the whole stack
	$(COMPOSE) up --build

.PHONY: down
down: ## Stop the stack (keeps data volumes)
	$(COMPOSE) down

.PHONY: fresh
fresh: ## Stop the stack and delete data volumes (reset MySQL/MinIO/Redis)
	$(COMPOSE) down -v

.PHONY: shell
shell: ## Open a shell in the app container
	$(COMPOSE) exec app sh

.PHONY: test
test: ## Run the Pest test suite in the app container
	$(COMPOSE) exec app php artisan test

.PHONY: pint
pint: ## Check code style with Pint
	$(COMPOSE) exec app vendor/bin/pint --test

.PHONY: stan
stan: ## Run PHPStan static analysis (level 6)
	$(COMPOSE) exec app vendor/bin/phpstan analyse --no-progress --memory-limit=1G
