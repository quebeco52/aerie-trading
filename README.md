# aerie-trading

Aerie Trading is a fullstack trading application with a stock market simulator and a trading platform. This application is for my own use.

Aerie Trading uses the tech stack:

- PHP/Symfony
- Redis
- MariaDB
- Caddy
- Docker

## Requirements specification

### The application should:

- Use session handling
- Store user/stock data safely
- Be mobile friendly
- Be publicly accessible securely

#### Market simulator
- Be a "complete market solution"
- should "feel" realist to the extent that it can be done with synthetic data
- Handle earning reports
- Handle the underling microeconomic situation of the market (boom busts, etc)

#### Market Stretch goal
- The market should react dynamically on event's appearing in universe
- Should be unable to be "beaten" or manipulate by players

### User should be able to:

- Register and login
- Browse stock data (fundamentals and stock history)
- Buy and sell stocks
- Keep track of there portfolio

### Stretch goal

- Stock comment sections
- User leaderboard
- Options trading
- Optimize the application to run on as little resources as possible
- Be fun to use
- Teach players about the real stock market