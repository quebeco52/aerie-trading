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
- [ ] Has to be hosted securely "lock downed" in a way that that it is not vulnerable and follows good security practices
- [ ] Should have Admin role and a user role

### User should be able to:
- [ ] Register and login
- [ ] Browse stock data (fundamentals and stock history)
- [ ] Buy and sell stocks
- [ ] Keep track of there portfolio

### Admin should be able to:
- [ ] Se register users and monitor there portfolios
- [ ] Change the values/info of the stocks
- [ ] Add new stocks
- [ ] Should be able to do all this without casing downtime for users

### Market simulator
- [ ] Simulate stock prices in a realistic way compared to a real world stock based on its fundamentals and microeconomic conditions
- [ ] Handle earning reports and should integrate this result into the price of the stock
- [ ] Handle the underlying microeconomic situation of the market (boom busts, etc) and have the effect directly and indirectly effect the stock prices
- [ ] should use mathematical proven and well tested formulas (Geometric Brownian Motion, Heston Stochastic Volatility Model, etc) so to keep the simulation realistic compared to the real world economy. (as little magic numbers as possible)

#### Market Stretch goal
- [ ] The market should react dynamically on event's appearing in universe (news, conflict, etc)
- [ ] Should be unable to be "beaten" or manipulate by players so to able to generate free money

### Stretch goal
- [ ] Stock comment sections
- [ ] User leaderboard
- [ ] Options trading
- [ ] Optimize the application to run on as little resources as possible
- [ ] Should have a consistent narrative (world building)




## AI policy
- AI may be used to write code snippets and analys code with human oversight.
- No autonomous AI agents are allowed to write code.
- The developer who commits the code stands for the code and understands the code.
