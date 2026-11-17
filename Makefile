# Dalfred public development Makefile

MODULE_NAME := dalfred
MODULE_FILE := core/modules/modDalfred.class.php
VERSION := $(shell sed -n "s/.*\$$this->version\s*=\s*'\([^']*\)'.*/\1/p" $(MODULE_FILE))
BUILD_DIR := build
RELEASE_DIR := releases
ZIP_NAME := module_$(MODULE_NAME)-$(VERSION)-source.zip

.PHONY: help version lint validate build-source clean check-scoper

# php-scoper prefixes the bundled dependencies at release build time (see
# tools/scoper.inc.php and tools/isolate-dependencies.sh). Pinned, like in
# emMCP: another version may scope differently.
PHP_SCOPER ?= $(HOME)/.local/share/php-tools/php-scoper.phar
PHP_SCOPER_VERSION ?= 0.18.17

help: ## Show available commands
	@echo "Dalfred public Makefile v$(VERSION)"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

version: ## Show module version
	@echo "$(VERSION)"

validate: ## Validate composer.json
	composer validate --no-check-lock --strict

lint: ## Run PHP syntax check
	find . -path ./.git -prune -o -path ./vendor -prune -o -name '*.php' -print0 | xargs -0 -n1 php -l

build-source: ## Build a source ZIP without private release dependencies
	@mkdir -p $(RELEASE_DIR)
	@rm -rf $(BUILD_DIR)
	@git archive --format=zip --output=$(RELEASE_DIR)/$(ZIP_NAME) HEAD
	@echo "Created $(RELEASE_DIR)/$(ZIP_NAME)"

check-scoper: ## Verify the pinned php-scoper is installed
	@test -f "$(PHP_SCOPER)" || (echo "php-scoper not found: $(PHP_SCOPER)"; echo "curl -sSL -o $(PHP_SCOPER) https://github.com/humbug/php-scoper/releases/download/$(PHP_SCOPER_VERSION)/php-scoper.phar"; exit 1)
	@php $(PHP_SCOPER) --version 2>/dev/null | grep -q "$(PHP_SCOPER_VERSION)" || (echo "php-scoper must be $(PHP_SCOPER_VERSION)"; exit 1)
	@echo "  php-scoper $(PHP_SCOPER_VERSION) found"

clean: ## Remove local build artifacts
	@rm -rf $(BUILD_DIR)
	@echo "Build directory cleaned."

# Optional local extension (release/publish/test-harness targets, gitignored)
-include Makefile.local
