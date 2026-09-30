_: list

## Config

PHPUNIT_CONFIG=tools/phpunit.xml

PROFILE ?=
ifeq ($(PROFILE),)
VENDOR_DIR=vendor
PROFILE_ENV=
PHPCS_CONFIG=tools/phpcs.xml
PHPCS_PREPARE=true
else
VENDOR_DIR=vendor-$(PROFILE)
PROFILE_ENV=COMPOSER=composer.$(PROFILE).json COMPOSER_VENDOR_DIR=$(VENDOR_DIR)
PHPCS_CONFIG=var/tools/PHP_CodeSniffer/phpcs.$(PROFILE).xml
PHPCS_PREPARE=sed -e 's\#\./\.\./vendor/\#$(CURDIR)/$(VENDOR_DIR)/\#g' -e 's\#\./\.\.\#$(CURDIR)\#g' tools/phpcs.xml > $(PHPCS_CONFIG)
endif

ifneq ($(filter latte3%,$(PROFILE)),)
PHPSTAN_CONFIG=tools/phpstan.latte3.neon
PHPSTAN_BASELINE_CONFIG=tools/phpstan.latte3.baseline.neon
PHPSTAN_PATHS=
else
PHPSTAN_CONFIG=tools/phpstan.neon
PHPSTAN_BASELINE_CONFIG=tools/phpstan.baseline.neon
PHPSTAN_PATHS=src tests tools/corpus
endif

## Install

update: ## Update all dependencies
	make update-php

update-php: ## Update PHP dependencies
	composer update

profile: ## Install a dependency profile into vendor-<name>: make profile PROFILE=latte31 PRE_PHP="php8.4"
	test -n "$(PROFILE)" || { echo "PROFILE is required, one of: $(basename $(notdir $(wildcard tools/profiles/*.json)))"; exit 1; }
	$(PRE_PHP) tools/profile.php $(PROFILE)
	$(PROFILE_ENV) $(PRE_PHP) "$(shell command -v composer)" update --no-interaction --no-progress --prefer-dist $$($(PRE_PHP) tools/profile.php $(PROFILE) --flags) $(ARGS)

## QA

cs: ## Check PHP files coding style
	mkdir -p var/tools/PHP_CodeSniffer
	$(PHPCS_PREPARE)
	$(PROFILE_ENV) $(PRE_PHP) "$(VENDOR_DIR)/bin/phpcs" src tests tools/corpus --standard=$(PHPCS_CONFIG) --parallel=$(LOGICAL_CORES) $(ARGS)

csf: ## Fix PHP files coding style
	mkdir -p var/tools/PHP_CodeSniffer
	$(PHPCS_PREPARE)
	$(PROFILE_ENV) $(PRE_PHP) "$(VENDOR_DIR)/bin/phpcbf" src tests tools/corpus --standard=$(PHPCS_CONFIG) --parallel=$(LOGICAL_CORES) $(ARGS)

phpstan: ## Analyse code with PHPStan
	mkdir -p var/tools
	$(PROFILE_ENV) $(PRE_PHP) "$(VENDOR_DIR)/bin/phpstan" analyse $(PHPSTAN_PATHS) -c $(PHPSTAN_CONFIG) $(ARGS)

phpstan-baseline: ## Add PHPStan errors to baseline
	make phpstan ARGS="-b $(PHPSTAN_BASELINE_CONFIG)"

## Tests

.PHONY: tests
tests: ## Run all tests
	$(PROFILE_ENV) $(PRE_PHP) $(PHPUNIT_COMMAND) $(ARGS)

coverage-clover: ## Generate code coverage in XML format
	$(PROFILE_ENV) $(PRE_PHP) $(PHPUNIT_COVERAGE) --coverage-clover=var/coverage/clover.xml $(ARGS)

coverage-html: ## Generate code coverage in HTML format
	$(PROFILE_ENV) $(PRE_PHP) $(PHPUNIT_COVERAGE) --coverage-html=var/coverage/html $(ARGS)

## Corpus

corpus-harvest: ## Harvest upstream Latte test templates of the installed versions into var/corpus/templates/<profile>
	$(PROFILE_ENV) $(PRE_PHP) tools/corpus/harvest.php $(or $(PROFILE),default)

corpus-manifest: ## Rewrite tests/Corpus/manifest.<profile>.json from a corpus analysis and print the state diff
	CORPUS_MANIFEST_WRITE=1 $(PROFILE_ENV) $(PRE_PHP) "$(VENDOR_DIR)/bin/phpunit" -c $(PHPUNIT_CONFIG) tests/Corpus $(ARGS)

## Utilities

.SILENT: $(shell grep -h -E '^[a-zA-Z_-]+:.*?$$' $(MAKEFILE_LIST) | sort -u | awk 'BEGIN {FS = ":.*?"}; {printf "%s ", $$1}')

list:
	awk 'BEGIN {FS = ":.*##"; printf "Usage:\n  make \033[36m<target>\033[0m\n"}'
	@max_len=0; \
	for target in $$(grep -h -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "} {print $$1}'); do \
		len=$${#target}; \
		if [ $$len -gt $$max_len ]; then \
			max_len=$$len; \
		fi \
	done; \
	awk -v max_len=$$max_len 'BEGIN {FS = ":.*?## "; last_section=""} \
	/^## /{last_section=sprintf("\n\033[1m%s\033[0m", substr($$0, 4)); next} \
	/^[a-zA-Z_-]+:.*?## /{if (last_section != "") { printf "%s\n", last_section; last_section=""; } printf "  \033[36m%-*s\033[0m %s\n", max_len + 1, $$1, $$2}' $(MAKEFILE_LIST)

PRE_PHP=XDEBUG_MODE=off

PHPUNIT_COMMAND="$(VENDOR_DIR)/bin/paratest" -c $(PHPUNIT_CONFIG) --runner=WrapperRunner -p$(LOGICAL_CORES)
PHPUNIT_COVERAGE=php -d pcov.enabled=1 -d pcov.directory=./src $(PHPUNIT_COMMAND)

LOGICAL_CORES=$(shell nproc || sysctl -n hw.logicalcpu || wmic cpu get NumberOfLogicalProcessors || echo 4)
