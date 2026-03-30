# aerie-trading

Aerie Trading is a fullstack trading application with a stock market simulator and a trading platform. This application is for my own use.

Aerie Trading uses the tech stack:

- PHP/Symfony
- Workerman (websocket)
- Redis
- MariaDB
- Caddy
- tailwind (frontend)
- Docker

## Requirements specification

### The application should:
- Use session handling
- Store user/stock data safely
- Be mobile "semi friendly"
- Be publicly accessible securely
- Have a admin panel to edit data (handel user handel stock data etc...) in prod 

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
- Should have a consistent narrative




## AI policy
- AI may be used to write code snippets and analys code with human oversight.
- No autonomous AI agents are allowed to write code.
- The developer how commit's the code stands for the code and understands the code.