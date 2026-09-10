# Market Layer Plan — Microstructure → Bond Desk → Short/Margin → NPC Flow

Status: draft, 2026-09-10
Scope: the four phases that turn the exogenous price process into a market with liquidity,
a tradable rates curve, leverage, and endogenous order flow.

## The premise

`src/Service/Macro/` and `src/Service/Model/Sector/` are deep. The market layer is not.
`TradeExecutionService::executeOrder()` fills any size instantly at mid: no spread, no fee,
no impact, no volume series (`StockHistory` stores `price` only). Every phase below closes
part of that gap, and each one depends on state introduced by the one before it.

Dependency order is real, not cosmetic:

- Phase 1 introduces **volume and liquidity**. Nothing else can be priced honestly without it.
- Phase 2 is **independent** of Phase 1 and can be built in parallel if convenient.
- Phase 3 needs Phase 1: a forced liquidation that does not move the price is not a margin call,
  and a short squeeze without impact is a scripted animation.
- Phase 4 needs Phase 1: agent order flow has no channel to reach the price without an impact
  function, and no budget to consume without a volume state.

## The invariant that governs all four phases

**Every new source of price movement must consume variance from the existing diffusion, not
stack on top of it.**

`MarketEngine` already enforces exactly this for the systemic jump
(`SYSTEMIC_JUMP_SECOND_MOMENT`, `MAX_SYSTEMIC_VARIANCE_DRAG_SHARE`, `src/Service/Market/MarketEngine.php:161-172`):
the district jump supplies part of the return variance, so `theta` gives the same amount back.
Order-flow impact (Phase 1), forced liquidations (Phase 3), and agent flow (Phase 4) are all
additional return variance. Wired in naively, each one re-breaks the calibration that comment
describes — every name simply gets more volatile and the SVJJ parameters stop meaning anything.

Each phase below states what it draws from the budget and what it gives back.

---

## Phase 1 — Microstructure: volume, spread, impact — SHIPPED 2026-09-10

Built as described below. Notes from implementation:

- **The budget is measured, not assumed.** The plan said to subtract an expected impact variance; that
  would quietly suppress the volatility of every name nobody trades, which is most of the market.
  `Stock::$impactVarianceEma` now holds the realized, annualized, exponentially decayed variance the
  name's flow actually supplied, and only that is handed back. A stock with no flow reclaims nothing.
- **Calibration.** `PERMANENT_IMPACT_GAMMA = 1.0` pins the square-root law to its canonical statement:
  trading one full day's volume moves the price by about one daily standard deviation. Half-spreads run
  0.5bp on a mega-cap to ~110bp on a micro-cap. `TEMPORARY_IMPACT_ETA = 0.5` is derived rather than
  chosen — the price walks to its new level while the order fills, so the average fill is the midpoint.
- **Measured result.** With the budget on, realized volatility is unchanged (under 4bp of drift) up to
  players trading ~30% of daily volume; without it the same flow inflates volatility by ~3.9 points.
  Past ~60% of daily volume the 25% cap binds and volatility genuinely rises, which is correct: a market
  the players churn that hard IS more volatile.
- **Orders above 2x ADV are refused**, not priced. Beyond that the square-root law is extrapolation, and
  a capped impact would make size free again above the cap — the exact hole this phase closes.
- **Turnover is derived, not hand-set.** `LiquidityEngine::structuralTurnoverRatio()` scales it off the
  name's own volatility, so forty seed numbers cannot drift out of step with the volatilities they match.
- `MarketResetCompletenessTest` caught both new Stock columns missing from the reset. It is a good guard.

Not built: NPC counterparty flow (Phase 4) — background volume is simulated from the mixture-of-
distributions link between volume and volatility rather than produced by agents.

### Goal
Order size costs money. Liquidity is finite and varies with volatility and float. A volume
series exists and is chartable.

### Models

**Volume baseline.** Annual share turnover is a structural property of a firm, not a random
draw. `ADV_shares = sharesOutstanding * publicFloatPercentage * turnoverRatio / tradingDaysPerYear`,
with `turnoverRatio` a new `Stock` column seeded per sector (large caps ~1.0–1.5x float/year,
small and speculative names higher). Realized volume is an EMA around that baseline, lifted by
volatility and by news ticks (earnings, shocks, splits).

**Spread.** Wyart, Bouchaud, Kockelkoren, Potters & Vettorazzo (2008), *Relation between
bid–ask spread, impact and volatility in order-driven markets*: `S ≈ c · σ_daily / √N`, where
`N` is trades per day and `c ≈ 1`. This is the right choice here because it ties the spread to
`currentVolatility`, which is already simulated per stock per tick — no second calibration
surface, and the spread widens in a crash on its own.

**Impact.** Almgren–Chriss (2005) decomposition, with the square-root law for the permanent leg:

- Permanent (information): `Δlog P = γ · sign(Q) · σ_daily · √(|Q| / ADV)`, `γ ≈ 0.3–0.5`.
  Moves the price and stays. **This is what draws from the variance budget.**
- Temporary (liquidity): `η · sign(Q) · (|Q| / ADV)^0.5 · S`. Paid by the taker as execution
  slippage, decays within the tick, never touches the recorded price. **Draws nothing.**

The split is the whole point. A single blended impact term that permanently moves the price by
the full execution cost both double-charges the trader and inflates realized volatility.

### Constants (new section in `FinancialConstants`)

```
// --- Market Microstructure (Almgren-Chriss / Wyart) ---
SPREAD_VOLATILITY_COEFFICIENT   = 1.0     // c in S ~= c * sigma / sqrt(N)
PERMANENT_IMPACT_GAMMA          = 0.40    // square-root law coefficient
TEMPORARY_IMPACT_ETA            = 0.60    // slippage in units of half-spread
MAX_IMPACT_VARIANCE_DRAG_SHARE  = 0.25    // ceiling on theta given back to order flow
MIN_ADV_SHARES                  = 1000.0  // floor so a dead name is illiquid, not undefined
BASELINE_ANNUAL_TURNOVER        = 1.20
```

### Entity changes (no migration files — Doctrine generates them)

- `Stock`: `turnoverRatio` (float), `advShares` (float, rolling EMA state).
- `StockHistory`: `open`, `high`, `low`, `volume`. Existing `price` becomes the close — keep the
  column name, the API and the charts already read it, and renaming it churns
  `StockController::history()` and `assets/js/stock/price-chart.js` for nothing.
- `TradeOrder`: `spreadCost`, `impactCost` (decimal, nullable) so the fill is auditable and the
  UI can show the trader what the size cost them.

### New files

- `src/Service/Market/LiquidityEngine.php` — ADV maintenance, spread from volatility, permanent
  and temporary impact given order size. Pure math, no persistence, unit-testable in isolation.
- `src/Service/Market/OrderFlowStoreInterface.php` + `RedisOrderFlowStore.php` +
  `InMemoryOrderFlowStore.php` — accumulated signed order flow and traded volume per ticker per
  tick. Mirror `IndustryShareStoreInterface` exactly, including the degrade-to-empty-on-failure
  behaviour: an order-flow outage must never abort a tick.
- `src/DTO/LiquidityContext.php`, `src/DTO/ExecutionResult.php`.

### Integration points

1. `TradeExecutionService::executeOrder()` (`src/Service/Market/TradeExecutionService.php:33`) —
   quote from `LiquidityEngine`, fill at mid ± half-spread ± temporary impact, write the signed
   quantity to the order-flow store, persist the cost breakdown on the `TradeOrder`. Same in
   `fillOpenOrder()` (line 292) for resting limit orders, which currently fill at the live price.
2. `StockTracker::updateStocks()` (`src/Service/Market/StockTracker.php:204`) — between
   `calculateNextPrice()` and `setPrice()`, drain the tick's net order flow and apply permanent
   impact.
3. `MarketEngine::calculateNextPrice()` — subtract the expected permanent-impact variance from
   `adjustedTheta` alongside `$systemicJumpVariance`, capped by `MAX_IMPACT_VARIANCE_DRAG_SHARE`.
4. `MarketTickerCommand` (`src/Command/MarketTickerCommand.php:216-235`) — accumulate OHLCV per
   bar across the ticks between history writes (`historyIntervalTicks()` is often >1) and extend
   the raw `INSERT INTO stock_history` to the new columns.

### Frontend

`assets/js/stock/price-chart.js` gains a candlestick mode and a volume sub-panel; the trade form
(`assets/controllers/trade_form_controller.js`) shows an estimated fill price with spread and
impact before submit.

### Tests

- Unit (`tests/Service/Market/LiquidityEngineTest.php`): square-root scaling — quadrupling size
  doubles permanent impact; spread widens monotonically in volatility; zero net flow is zero
  permanent impact.
- Financial (`tests/Financial/`): **the variance budget test.** Run a seeded stock through N ticks
  with heavy two-sided order flow and assert realized volatility stays within tolerance of the
  same run with no flow. This is the test that protects the calibration.
- Integration: a large buy fills above mid, a large sell below, and round-tripping a big position
  in one tick loses money.

### Risks

- The ticker clears the EntityManager on history ticks and re-fetches
  (`MarketTickerCommand.php:233-235`). New per-stock state must live on the entity or in Redis,
  never in a service-local array keyed by object identity.
- ADV must be floored. `√(Q/ADV)` with `ADV → 0` sends any order to infinite impact and a dead
  small cap becomes untradable rather than merely illiquid.

**Effort: large.** This is the phase that pays for the other three.

---

## Phase 2 — Sovereign bond desk — SHIPPED 2026-09-10

Built as described below, with three changes made during implementation:

- **The curve is evaluated once, not twice.** `MonetaryPolicySubsystem` published only the benchmark
  2y/5y/10y/30y points and its fitted factors were local variables, so a bond with 6.75 years left had
  nothing exact to discount against. `MathUtility::calculateSovereignZeroYield()` is now the single
  evaluation; the subsystem delegates to it and publishes `beta1`, `base_term_premium` and
  `long_end_premium` into `MacroState`/`MacroStateDTO`, and `MacroStateDTO::sovereignCurve()` hands the
  desk the same factors. Note that `MacroState::$nsSlope` is the 10y-minus-policy REPORTING metric, not
  the fitted beta1 — substituting it produces a plausible curve that reprices nothing correctly.
- **`AssetResolver` was extracted first.** The nullable `if ($stock) … else ($etf)` chain appeared at four
  sites in `TradeExecutionService` and does not survive a third asset class. The class went from 441 to
  370 lines in the process.
- **NAV lives in four places, not one.** `PortfolioEscrowTest` already knew this: `Portfolio`,
  `LeaderboardController` and `DashboardController` each total net worth with their own SQL. All three
  now value bonds, and the guard test was extended to assert it.

Cost: a fully mature 184-issue ladder marks in 2.5 ms per tick, 2.5% of the 100 ms tick budget.

The UI shipped the same day: `/bonds` (the ladder, grouped by tenor, with a live curve chart) and
`/bond/{ticker}` (chart, key statistics, rate-sensitivity table, remaining cash flow schedule, order
ticket). `/api/history` serves bond tickers off `clean_price`, and the shared price-chart and
trade-form modules are reused rather than duplicated.

One thing the UI nearly broke and now guards: the curve chart initially re-derived the Svensson
evaluation in JavaScript so it could redraw each tick without a round trip. That is a second authority
on the curve with copied lambda constants, exactly what the single-evaluation rule forbids. The tick
payload now carries the evaluated points (`bond_curve`), and
`BondDeskTest::testTheSvenssonEvaluationIsNotReimplementedAnywhere` fails if the formula reappears in
`src/Service/Market`, `src/Controller` or the page modules.

### Goal
Make the rates engine directly tradable. `nsLevel`, `nsSlope`, `nsCurvature`, `yield2y/5y/10y/30y`
and the OU term-premium process already run every tick and currently reach the player only as a
second-order effect on equity discount rates.

### Models

Pricing is already in the codebase: `MathUtility::calculateNelsonSiegelYield()`
(`src/Service/Math/MathUtility.php:630`) gives the zero yield at any tenor from live state.

```
P = Σ_i  (c · F) · e^(−y(t_i) · t_i)  +  F · e^(−y(T) · T)
```

Missing and to be added to `MathUtility` (real definitions, no approximations):

- `calculateMacaulayDuration()` / `calculateModifiedDuration()`
- `calculateConvexity()`
- `calculateAccruedInterest()` (actual/actual)
- `calculateYieldToMaturity()` (Newton–Raphson on the price function)

### Design decision: real issues, not constant-maturity synthetics

A bond must **roll down the curve** — a 10y bought today is a 7y in three simulated years, and its
duration falls as it ages. A constant-maturity tracker never ages, which quietly removes both
roll-down carry and the duration decay that makes a bond ladder a real decision. Issue real bonds.

`MacroState` already carries `sovereignDebtToGdp`, so a quarterly auction calendar has somewhere
honest to hang: issuance size scales with the deficit, and heavy issuance feeds the existing
`calculatePreferredHabitatTermPremiumShift()` (`MathUtility.php:1445`) rather than being cosmetic.

### Entities

- `Bond` — `isin`, `tenorLabel`, `couponRate`, `faceValue`, `issuedAtTick`, `maturesAtTick`,
  `isOnTheRun`, `price`, `outstandingFace`.
- `UserBond` — holdings, mirroring `UserStock`.
- `CouponPayment` — mirroring `DividendPayment`, which is the pattern to copy for cash crediting.
- `BondHistory` — price and yield series.

### New files

- `src/Service/Market/BondPricingEngine.php` — price, YTM, duration, convexity, accrued from live
  curve state.
- `src/Service/Market/TreasuryAuctionService.php` — quarterly on-the-run issuance, coupon set to
  par at auction, previous on-the-run demoted to off-the-run.
- `src/Service/Market/BondTracker.php` — per-tick repricing and history, alongside `StockTracker`.

### Integration points

1. `MarketTickerCommand` — call `BondTracker` in the tick loop; add bonds to the `market_updates`
   pub/sub payload and to the `stocks_live_data` equivalent.
2. `TradeExecutionService` — extend `assetType` beyond `'STOCK'`/`'ETF'` to `'BOND'`. Worth doing
   properly now: the current `if ($stock) … else ($etf)` branching in `executeOrder()` does not
   survive a third asset class cleanly. Extract an asset resolver.
3. `Portfolio` — bond holdings into both NAV SQL blocks (`recordBulkSnapshots()`,
   `recordUserSnapshot()`).
4. Coupon crediting on the `Schedule`/tick path, following `DividendPayment`.

### Tests

- Financial: **the duration test.** Shift the curve 25bp and assert the realized price change
  matches `−D_mod · Δy + ½ · C · Δy²` within tolerance. If this fails, pricing and risk disagree
  and the desk is arbitrageable.
- A par bond prices at ~100 at issue; a zero prices below par; price → face at maturity.
- Inverted curve: the 2y out-yields the 10y and the ladder reprices in the right direction.

### Follow-on (2b, not in this plan's scope)
Corporate bonds. Every `Stock` already carries `creditSpread`, `creditRating` and `wholesaleDebt`,
and `calculateMertonCreditSpread()` (`MathUtility.php:910`) already exists. Discount at
`sovereign + spread`, recovery at LGD on bankruptcy. Cheap once the sovereign desk is built.

**Effort: medium.** Highest payoff per hour of the four — most of the math is already written.

---

## Phase 3 — Short selling and margin

### Goal
Leverage, risk of ruin, and squeezes that emerge from the mechanism rather than from a script.

### Models

**Margin.** Reg-T style: 50% initial, 25% maintenance long, 30% short.
`equity = totalAssets − marginDebit + shortProceeds − shortMarketValue`. Margin call when
`equity / marketValue < maintenance`; liquidate to restore compliance.

**Borrow.** Lendable supply is a fraction of `publicFloatPercentage`. Utilization
`u = shortInterest / lendableSupply` drives the fee convexly — general collateral near 0.3%/yr at
low utilization, rising sharply above ~0.9 as in real securities lending. Rebate = `policyRate − fee`.
Hard-to-borrow triggers buy-ins, which are forced market buys.

**Squeeze.** Do not script it. Price rises → shorts lose → margin calls → forced buys → Phase 1
impact pushes price higher → utilization rises → fee rises → more forced closes. With Phases 1
and 3 in place the feedback loop is already there; adding a "squeeze event" on top would be
invented game logic.

**Margin interest.** `policyRate + MARGIN_LOAN_SPREAD` — the constant already exists at
`FinancialConstants.php:141` and is currently used only by the corporate-side brokerage model.
Route the interest players actually pay into the revenue of `BrokerageBusinessModel` firms. That
closes a genuine loop: player leverage becomes brokerage earnings becomes a tradable stock.

### Entity changes

- `User`: `marginDebit`, `marginEnabled`.
- `UserStock`: allow negative `quantity` for shorts, plus `borrowRate` and `borrowAccrued`.
- `Stock`: `lendableSupplyRatio`, `shortInterestShares`.
- `TradeOrder`: extend `VALID_ACTIONS` (`TradeExecutionService.php:24`) with `SHORT` and `COVER`.

### The signed-quantity warning

Negative `quantity` silently breaks three things that currently assume it is positive:

1. `Portfolio::recordBulkSnapshots()` and `recordUserSnapshot()` — `SUM(quantity * price)` happens
   to be correct for a short's mark-to-market, but the short **proceeds** and the borrow accrual
   are missing entirely, so NAV is wrong by the proceeds.
2. `CostBasisCalculator` — cost basis on a short is inverted; P&L flips sign without an explicit branch.
3. `DividendIncomeCalculator` — a short **pays** the dividend. Silently crediting it is free money.

Handle all three deliberately in this phase. Do not assume the arithmetic works out.

### New files

- `src/Service/Market/MarginEngine.php` — equity, buying power, maintenance checks, liquidation sizing.
- `src/Service/Market/SecuritiesLendingDesk.php` — utilization, fee curve, buy-in decisions.
- `src/Service/Market/ForcedLiquidationService.php` — routes liquidations through the same
  `LiquidityEngine` path as player orders. A margin call that fills at mid is not a margin call.

### Integration points

1. `TradeExecutionService::executeOrder()` — buying-power check replaces the flat
   `Insufficient funds` test; short sales credit proceeds and register borrow.
2. `MarketTickerCommand` tick loop — margin sweep and borrow accrual, throttled to the existing
   `snapshotInterval` rhythm rather than every tick.
3. `Portfolio::accrueCashInterest()` (`Portfolio.php:58`) — the mirror leg. It currently only pays
   credit interest on positive balances; margin debits accrue at the debit rate.
4. Dividend path — shorts are debited.

### Tests

- Financial: a margin call fires at exactly the maintenance threshold, not one tick late.
- A short position's P&L is the exact negative of the long's, borrow cost aside.
- Utilization at 100% triggers a buy-in; total short interest never exceeds lendable supply.
- Integration: forced liquidation goes through `LiquidityEngine` and moves the price.

**Effort: large**, mostly because of the three NAV/basis/dividend corrections above.

---

## Phase 4 — NPC capital and endogenous flow

### Goal
Give the order-flow channel from Phase 1 something to carry, so prices become partly endogenous
instead of an exogenous process with a fundamental anchor.

### Model

Brock & Hommes (1997, 1998) Adaptive Belief System — a real, published heterogeneous-agent model,
which is what this project's rules require instead of hand-rolled trader personalities.

Agents choose between strategies each period by discrete choice on realized profit:

```
n_{h,t} = exp(β · U_{h,t−1}) / Σ_k exp(β · U_{k,t−1})
```

with `β` the intensity of choice. Strategy types map onto state the codebase already computes:

- **Fundamentalist** — trades `perceived_fair_value` vs price. Already produced by
  `MarketConsensusEngine` and already present in the `StockTracker` update payload.
- **Chartist/momentum** — trades `Stock.priceMomentumTrend`, already maintained at
  `StockTracker.php:229-243` (Jegadeesh–Titman EWMA).
- **Index fund** — buys market-cap-proportional, flow driven by macro rather than by price.
- **Market maker** — quotes around mid with mean-reverting inventory; supplies the resting
  liquidity that `LiquidityEngine` currently assumes exists.

Fund AUM responds to `financialConditionsIndex` and `marketZ`, both already in `MacroState`.

Brock–Hommes is the right pick specifically because the endogenous volatility clustering and
bubble/crash dynamics come out of the switching intensity — they do not have to be authored.

### The variance handover

This is the phase where the budget rule bites hardest. Agent flow reaching the price through
Phase 1's permanent impact is a **new, large** variance source. Two options, and the second is
the right one:

1. Cap agent flow under `MAX_IMPACT_VARIANCE_DRAG_SHARE` and keep the SVJJ diffusion dominant.
   Safe, and the agents stay decorative.
2. Explicitly re-budget: raise the drag share for agent flow and lower the exogenous `theta`
   correspondingly, so total realized volatility is unchanged and an increasing fraction of it is
   generated by the agents rather than drawn from a random number generator. Ship this behind a
   tunable share so it can be dialled from 0 toward a meaningful fraction under multi-seed
   verification rather than in one jump.

The long-run destination is that the SVJJ process is the residual, not the driver. That is a
migration, not a switch.

### New files

- `src/Service/Market/Agent/AgentPopulation.php` — Brock–Hommes fitness and strategy shares.
- `src/Service/Market/Agent/{Fundamentalist,Momentum,IndexFund,MarketMaker}Strategy.php` behind an
  `AgentStrategyInterface`, mirroring the `BusinessModelInterface` + registry pattern in
  `src/Service/Model/`.
- `src/Service/Market/Agent/AgentFlowEngine.php` — aggregates desired positions into signed order
  flow, written to the Phase 1 order-flow store so agent and player flow share one path.

### Integration points

`StockTracker::updateStocks()` — agent flow is written into the same order-flow store the
`TradeExecutionService` writes to, and drained by the same impact step. One channel, not two.

### Tests

- Financial: with fundamentalists dominant, price tracks fair value; with chartists dominant,
  bubbles and reversals appear; realized volatility clusters (autocorrelated squared returns).
- **Total realized volatility is invariant to agent population size** once re-budgeted — the
  headless multi-seed harness is the right tool for this.
- Market-maker inventory mean-reverts and does not drift to a corner.

**Effort: large.** Highest risk to existing calibration of the four; ship it last and behind a
share parameter.

---

## Cross-cutting

**Migrations.** Entity changes only. Doctrine generates the migration files.

**Static analysis.** `make phpstan FILE=<path>` on every touched file. The Redis stores will need
the same `@phpstan-ignore method.notFound` treatment the industry share store uses for phpredis
hash commands.

**Test execution.** `make test` needs Docker. Without it, run PHPUnit directly and exclude the
Controller suite.

**Redis discipline.** The order-flow store is on the tick hot path. Copy the share ledger's
posture exactly: catch `Throwable`, log a warning, degrade to empty. A Redis hiccup must never
abort a tick.

**Suite membership is explicit.** New test directories must be added to `phpunit.dist.xml` or they
will not run in any suite.

## Suggested sequencing

Phase 2 has no dependency on Phase 1 and the most math already written, so it is the natural
warm-up if you want a shipped desk early. Otherwise the order as listed is correct: 1 → 3 → 4 is
a hard dependency chain, and 2 slots in wherever it fits.
