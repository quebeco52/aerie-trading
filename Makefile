.PHONY: up down clean install seed reset ticker ticker-stop tailwind-watch bash test phpstan

DC = docker compose --env-file .env.dev -f docker-compose.dev.yml
EXEC_PHP = $(DC) exec aerie-app

up:
	$(DC) up -d

down:
	$(DC) --profile live down --remove-orphans

clean:
	$(DC) --profile live down -v --remove-orphans

install: up
	$(EXEC_PHP) composer install
	$(EXEC_PHP) php bin/console doctrine:migrations:migrate --no-interaction
	$(EXEC_PHP) php bin/console tailwind:build
	$(EXEC_PHP) php bin/console app:market-seed

seed:
	$(EXEC_PHP) php bin/console app:market-seed

reset: ticker-stop
	$(EXEC_PHP) php bin/console app:market-reset

ticker:
	$(DC) --profile live up -d aerie-ticker

ticker-stop:
	$(DC) --profile live stop aerie-ticker

tailwind-watch:
	$(EXEC_PHP) php bin/console tailwind:build --watch

bash:
	$(EXEC_PHP) bash

test: up
	$(EXEC_PHP) vendor/bin/phpunit

phpstan: up
	$(EXEC_PHP) vendor/bin/phpstan analyse --memory-limit=1G