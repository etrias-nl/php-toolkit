MAKEFLAGS += --warn-undefined-variables --always-make
.DEFAULT_GOAL := _

exec_docker=docker compose run --quiet-pull --rm -u "$(shell id -u):$(shell id -g)"

composer-update:
	${exec_docker} php sh -c "composer update --no-progress -n && composer --working-dir=/usr/local/etc/tools normalize /app/composer.json && composer validate"
cli:
	${exec_docker} php bash
lint:
	${exec_docker} php sh -c "phplint --no-progress --cache=var/phplint-cache --warning bin/console src tests"
cs-fix:
	${exec_docker} php sh -c "php-cs-fixer fix"
psalm:
	${exec_docker} php sh -c "psalm --no-progress"
psalm-suppress: composer-update
	${exec_docker} php sh -c "psalm --no-progress --set-baseline=psalm-baseline.xml"
test:
	${exec_docker} php sh -c "phpunit"
rector-fix: composer-update
	${exec_docker} php sh -c "rector process --no-progress-bar"
tools:
	${exec_docker} php sh -c "rm -rf /app/var/tools && cp -R /usr/local/etc/tools/vendor /app/var/tools"
qa: composer-update lint cs-fix rector-fix psalm test
