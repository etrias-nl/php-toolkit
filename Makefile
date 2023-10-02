MAKEFLAGS += --warn-undefined-variables --always-make
.DEFAULT_GOAL := _

exec_docker=docker compose run $(shell [ "$$CI" = true ] && echo "-t" || echo "-it") -e CI -e GITHUB_ACTIONS -e RUNNER_DEBUG -u "$(shell id -u):$(shell id -g)" --rm

composer-update:
	${exec_docker} php sh -c "composer validate && composer update --no-progress -n && composer bump && composer --working-dir=/usr/local/etc/tools normalize /app/composer.json"
