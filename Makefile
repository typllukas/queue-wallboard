HOST_UID := $(shell id -u)
HOST_GID := $(shell id -g)
export HOST_UID
export HOST_GID

DC = docker compose
BACKEND = $(DC) exec -T daemon
WEB = $(DC) exec -T web
PHPSTAN_MEMORY = 512M

.DEFAULT_GOAL := help
.PHONY: help up down logs check fix test test-one web-test-one backend-check web-check

help: ## List the available targets
	@grep -E '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

up: ## Build, install dependencies, start the containers and wait until they are healthy
	$(DC) build
	$(DC) run --rm --no-deps daemon composer install --no-interaction
	$(DC) run --rm --no-deps web npm install
	$(DC) up -d --wait
	@$(DC) ps --format '{{.Service}}\t{{.Status}}'

down: ## Stop the containers
	$(DC) down

logs: ## Follow the container logs
	$(DC) logs -f

check: backend-check web-check ## The whole gate, backend and web

backend-check:
	$(BACKEND) vendor/bin/php-cs-fixer fix --dry-run --diff
	$(BACKEND) vendor/bin/rector process --dry-run
	$(BACKEND) vendor/bin/phpstan analyse --no-progress --memory-limit=$(PHPSTAN_MEMORY)
	$(BACKEND) vendor/bin/phpunit

web-check:
	$(WEB) npx tsc --noEmit
	$(WEB) npx eslint .
	$(WEB) npx vitest run

fix: ## Apply Rector, php-cs-fixer and ESLint fixes
	$(BACKEND) vendor/bin/rector process
	$(BACKEND) vendor/bin/php-cs-fixer fix
	$(WEB) npx eslint . --fix

test: ## PHPUnit and Vitest
	$(BACKEND) vendor/bin/phpunit
	$(WEB) npx vitest run

test-one: ## One PHPUnit test: make test-one FILTER=ClassOrMethod
	$(BACKEND) vendor/bin/phpunit --filter '$(FILTER)'

web-test-one: ## One Vitest file: make web-test-one FILE=src/path/file.test.ts
	$(WEB) npx vitest run '$(FILE)'
