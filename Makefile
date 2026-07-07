# Dalfred public development Makefile

MODULE_NAME := dalfred
MODULE_FILE := core/modules/modDalfred.class.php
VERSION := $(shell sed -n "s/.*\$$this->version\s*=\s*'\([^']*\)'.*/\1/p" $(MODULE_FILE))
BUILD_DIR := build
RELEASE_DIR := releases
ZIP_NAME := module_$(MODULE_NAME)-$(VERSION)-source.zip

.PHONY: help version lint validate build-source clean

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

clean: ## Remove local build artifacts
	@rm -rf $(BUILD_DIR)
	@echo "Build directory cleaned."

# Optional local extension (release/publish/test-harness targets, gitignored)
-include Makefile.local
