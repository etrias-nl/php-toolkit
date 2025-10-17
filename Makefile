MAKEFLAGS += --warn-undefined-variables --always-make
.DEFAULT_GOAL := _

compose=docker compose
run=${compose} run --quiet-pull --rm
run_app=${run} --no-deps php
run_app_deps=${run} php

composer-update:
	${run_app} sh -c "composer update --no-progress -n && composer --working-dir=/usr/local/etc/tools normalize /app/composer.json && composer validate"
cli:
	${run_app} bash
lint:
	${run_app} sh -c "shellcheck --severity=error --format=gcc $(shell find bin/ -type f -executable)"
	${run_app} sh -c "yamllint ."
	${run_app} sh -c "phplint --no-progress --cache=var/phplint-cache --warning bin/console src tests"
cs-fix:
	${run_app} sh -c "php-cs-fixer fix --show-progress=none"
psalm:
	${run_app} sh -c "psalm --no-progress --set-baseline=psalm-baseline.xml"
test:
	${run_app_deps} sh -c "phpunit"
rector-fix:
	${run_app} sh -c "rector process --no-progress-bar"
tools:
	mkdir -p var/tools
	${run_app} sh -c "cd /usr/local/etc/tools/vendor && cp -r --remove-destination friendsofphp phpunit rector /app/var/tools"
qa: composer-update lint cs-fix rector-fix psalm test
