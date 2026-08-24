# aerie-trading

Aerie Trading is a full stack trading application with a stock market simulator and a trading platform. This application is for my own use.

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

## Requirements specification

### The application should:
- [x] Use session handling
- [x] Store user/stock data in mariadb
- [x] The site should be usable throw a mobile interface but will not be the optimal way to use the site
- [x] Should be hosted on the cloud, most likely a hetzer vps and should be open to the public
- [x] Should have a Admin role and a User role
- [x] everything relevant to users should be displayed in realtime via WebSocket/Redis

### User should be able to:
- [x] Register and login
- [x] Browse stock data (fundamentals and stock history)
- [x] Buy and sell stocks
- [x] Keep track of there portfolio
- [x] compare stock throw a screener

### Admin should be able to:
- [x] Se register users and monitor there portfolios
- [x] Change the values/info of the stocks
- [x] Add new stocks
- [x] Do all this without casing downtime for users



### Market simulator should:
- [x] Simulate stock prices in a realistic way compared to a real world stock based on its fundamentals and microeconomic conditions
- [x] Handle earning reports and should integrate the reports into the price of the stock
- [x] Change Volatility dynamically for individual stocks and the market
- [x] Have a boom and bust cycle that alter the trajectory of the market
- [x] Have etfs that change its price based on the underlying stocks it made out of
- [x] Use mathematical proven and well tested formulas (Geometric Brownian Motion, Heston Stochastic Volatility Model, etc) so to keep the simulation realistic compared to the real world economy. (as little magic numbers as possible)
- [x] All formulas should be tested and pass in PHPUnit
- [x] Handle stock splits and reverse stocksplits
- [x] Handle stock dividend

#### Market Stretch goal
- [ ] Have a narrative engine that fires generated events
- [ ] The market should react dynamically on event's appearing in universe (news, conflict, etc)
- [ ] Should be unable to be "beaten" or manipulate by users so to able to generate free money (For example a user should not be able to know the instant a bust will happen and dump there stocks)
- [ ] Use Telemetry to log statistical properties like volatility clustering and variance to be able to prove the feasibility of the math

### Stretch goal
- [ ] Stock comment sections
- [x] User leaderboard
- [ ] Options trading
- [ ] Optimize the application to run on as little resources as possible
- [ ] Should have a consistent narrative (world building)
- [ ] Transaction Fees
- [ ] Code and infrastructure hardening (immutable logs, encrypt all data in transit and at rest, etc)
- [ ] have a dashboard to see the health of the application (latency of market ticks, etc...)



## AI policy
- AI may be used to write code snippets and analys code with human oversight.
- No autonomous AI agents are allowed to write code.
- The developer who commits the code stands for the code and understands the code.

## License
This project is licensed under the [GNU Affero General Public License v3.0 (AGPLv3)](LICENSE).
