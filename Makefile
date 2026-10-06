.PHONY: up down clean install seed reset ticker ticker-stop macro-dump tailwind-watch bash test test-unit test-integration test-functional test-financial test-realism test-e2e test-all test-coverage phpstan profile-cmd profile-clean

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

# The recorded run as NDJSON in var/, one object per simulated quarter: state vector plus what moved the
# output gap. YEARS counts back from the present; omit it for everything retained (120 simulated years).
macro-dump:
	$(EXEC_PHP) php bin/console app:macro:gap-dump $(if $(YEARS),--years=$(YEARS),)

tailwind-watch:
	$(EXEC_PHP) php bin/console tailwind:build --watch

bash:
	$(EXEC_PHP) bash

test: up
	$(EXEC_PHP) vendor/bin/phpunit --testsuite Fast --no-progress

test-unit: up
	$(EXEC_PHP) vendor/bin/phpunit --testsuite Unit --no-progress

test-integration: up
	$(EXEC_PHP) vendor/bin/phpunit --testsuite Integration --no-progress

test-functional: up
	$(EXEC_PHP) vendor/bin/phpunit --testsuite Functional --no-progress

test-financial: up
	$(EXEC_PHP) vendor/bin/phpunit --testsuite Financial --no-progress

test-realism: up
	$(EXEC_PHP) vendor/bin/phpunit --testsuite Realism --no-progress

test-e2e: up
	$(EXEC_PHP) vendor/bin/phpunit --testsuite E2E --no-progress

test-all: up
	$(EXEC_PHP) vendor/bin/phpunit --no-progress

test-coverage: up
	$(EXEC_PHP) vendor/bin/phpunit --testsuite Fast --no-progress --coverage-text

phpstan: up
	$(EXEC_PHP) vendor/bin/phpstan analyse --memory-limit=1G --no-progress --error-format=raw $(FILE)

profile-cmd: up
	$(DC) exec -e XDEBUG_MODE=profile -e XDEBUG_TRIGGER=1 aerie-app php bin/console $(CMD)

profile-clean:
	rm -f var/profiling/cachegrind.out.*