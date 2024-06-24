MAKEFLAGS += --warn-undefined-variables --always-make
.DEFAULT_GOAL := _

exec_docker=docker compose run --quiet-pull --rm
exec_app=${exec_docker} --no-deps php
exec_app_deps=${exec_docker} php

composer-update:
	${exec_app} sh -c "composer update --no-progress -n && composer --working-dir=/usr/local/etc/tools normalize /app/composer.json && composer validate"
cli:
	${exec_app} bash
lint:
	${exec_app} sh -c "shellcheck --severity=error --format=gcc $(shell find bin/ -type f -executable)"
	${exec_app} sh -c "yamllint ."
	${exec_app} sh -c "phplint --no-progress --cache=var/phplint-cache --warning bin/console src tests"
cs-fix:
	${exec_app} sh -c "php-cs-fixer fix --show-progress=none"
psalm:
	${exec_app} sh -c "psalm --no-progress"
psalm-suppress: composer-update
	${exec_app} sh -c "psalm --no-progress --set-baseline=psalm-baseline.xml"
test:
	${exec_app_deps} sh -c "phpunit"
rector-fix: composer-update
	${exec_app} sh -c "rector process --no-progress-bar"
tools:
	${exec_app} sh -c "rm -rf /app/var/tools && cp -R /usr/local/etc/tools/vendor /app/var/tools"
qa: composer-update lint cs-fix rector-fix psalm test
