# aerie-trading

Aerie Trading is a full stack trading application with a stock market simulator and a trading platform. This application is for my own use.

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
- [ ] Use session handling
- [ ] Store user/stock data in mariadb, this data should be protect so not to be allowed to be lost, corrupted or comprised 
- [ ] The site should be usable throw a mobile interface but will not be the optimal way to use the site
- [ ] Should be hosted on the cloud, most likely a hetzer vps and should be open to the public
- [ ] Should have a Admin role and a User role
- [ ] everything relevant to users should be displayed in realtime via WebSocket/Redis

### User should be able to:
- [ ] Register and login
- [ ] Browse stock data (fundamentals and stock history)
- [ ] Buy and sell stocks
- [ ] Keep track of there portfolio

### Admin should be able to:
- [ ] Se register users and monitor there portfolios
- [ ] Change the values/info of the stocks
- [ ] Add new stocks
- [ ] Do all this without casing downtime for users

### Market simulator should:
- [ ] Simulate stock prices in a realistic way compared to a real world stock based on its fundamentals and microeconomic conditions
- [ ] Handle earning reports and should integrate the reports into the price of the stock
- [ ] Have a boom and bust cycle
- [ ] Use mathematical proven and well tested formulas (Geometric Brownian Motion, Heston Stochastic Volatility Model, etc) so to keep the simulation realistic compared to the real world economy. (as little magic numbers as possible)

#### Market Stretch goal
- [ ] The market should react dynamically on event's appearing in universe (news, conflict, etc)
- [ ] Should be unable to be "beaten" or manipulate by users so to able to generate free money (For example a user should not be able to know the instant a bust will happen and dump there stocks)

### Stretch goal
- [ ] Stock comment sections
- [ ] User leaderboard
- [ ] Options trading
- [ ] Optimize the application to run on as little resources as possible
- [ ] Should have a consistent narrative (world building)
- [ ] Transaction Fees
- [ ] Transaction History. The system must keep an immutable log of every trade



## AI policy
- AI may be used to write code snippets and analys code with human oversight.
- No autonomous AI agents are allowed to write code.
- The developer who commits the code stands for the code and understands the code.
