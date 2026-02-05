MAKEFLAGS += --warn-undefined-variables --always-make
.DEFAULT_GOAL := _
CI ?= false

compose=docker compose
run=${compose} run --quiet-pull --rm
run_app=${run} --no-deps php
run_app_deps=${run} php

ifeq ($(CI),true)
	composer_flags=--classmap-authoritative
	psalm_flags=--set-baseline=psalm-baseline.xml
else
	composer_flags=
	psalm_flags=
endif

composer-install:
	${run_app} sh -c "composer update --no-progress -n --lock && composer bump && composer --working-dir=/usr/local/etc/tools normalize /app/composer.json && composer validate && composer install --no-progress --audit -n ${composer_flags}"
cli:
	${run_app} bash
lint:
	${run_app} sh -c "shellcheck --severity=error --format=gcc $(shell find bin/ -type f -executable)"
	${run_app} sh -c "yamllint ."
	${run_app} sh -c "phplint --no-progress --cache=var/phplint-cache --warning bin/console src tests"
cs:
	${run_app} sh -c "php-cs-fixer fix --show-progress=none"
psalm:
	${run_app} sh -c "psalm --no-progress ${psalm_flags}"
test:
	${run_app_deps} sh -c "phpunit"
rector:
	${run_app} sh -c "rector process --no-progress-bar"
tools:
	mkdir -p var/tools
	${run_app} sh -c "cd /usr/local/etc/tools/vendor && cp -r --remove-destination friendsofphp phpunit rector /app/var/tools"
qa: composer-install lint cs rector psalm test
