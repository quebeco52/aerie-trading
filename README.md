# aerie-trading

Aerie Trading is a full stack trading application with a stock market simulator and a trading platform.

## installation
To run Aerie Trading locally, you will need to have [Docker](https://www.docker.com/) and `make` installed.

1. **Install dependencies, setup the database, build assets, and seed the market:**
   ```bash
   make install
   ```

2. **Start the live Market Ticker (Required for live price movements):**
   ```bash
   make ticker
   ```

3. **Access the platform:**
   Open your browser and navigate to `http://localhost` and login with email test.test@test.se and password test

**Useful Commands:**
- `make down` - Stop the Docker containers.
- `make up` - Start the Docker containers (without reinstalling everything).
- `make reset` - Soft reset the market timelines while keeping configurations.
- `make tailwind-watch` - Watch and compile Tailwind CSS changes automatically during frontend development.

## Alternative: manual

If you do not have `make` installed, you can use these commands manually:

```bash
docker compose --env-file .env.dev -f docker-compose.dev.yml up -d
docker compose --env-file .env.dev -f docker-compose.dev.yml exec aerie-app composer install
docker compose --env-file .env.dev -f docker-compose.dev.yml exec aerie-app php bin/console doctrine:migrations:migrate --no-interaction
docker compose --env-file .env.dev -f docker-compose.dev.yml exec aerie-app php bin/console tailwind:build
docker compose --env-file .env.dev -f docker-compose.dev.yml exec aerie-app php bin/console app:market-seed
docker compose --env-file .env.dev -f docker-compose.dev.yml --profile live  up
```

## Aerie Trading uses the tech stack:

- PHP/Symfony
- Workerman (websocket)
- Redis
- MariaDB
- Caddy
- tailwind (frontend)
- Docker


## License
This project is licensed under the [GNU Affero General Public License v3.0 (AGPLv3)](LICENSE).
