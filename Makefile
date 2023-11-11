UID=$(shell id -u)
GID=$(shell id -g)
PHP_SERVICE=php-mercadona-importer

init: down build composer-install up

build:
	docker compose up -d

up:
	docker compose up -d

stop:
	docker compose stop

down:
	docker compose down -v

composer-install:
	docker compose run --rm -u ${UID}:${GID} ${PHP_SERVICE} composer install

bash:
	docker compose run --rm -u ${UID}:${GID} ${PHP_SERVICE} sh

sync:
	docker compose exec --user=${UID} ${PHP_SERVICE} sh -c "php mercadona-importer.php"

test:
	docker compose exec --user=${UID} ${PHP_SERVICE} sh -c "composer tests"