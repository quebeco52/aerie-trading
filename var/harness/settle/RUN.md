# Opening settle

Does each firm, opened on its seed row, stay where it opened? `SettleHarnessTest` opens the board as the seed does,
then steps every firm's own EarningsEngine in time order on one industry ledger, in an economy held at the opening,
with the market pricing each report at fair value. There are no deals, no outlook changes, no order flow, no failure
sweep and no shocks. What still moves is each model pulling its seed toward where the model wants it. It is
deterministic and the whole board takes ~9 s.

    bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap tests/bootstrap.php --no-configuration \
      var/harness/settle/SettleHarnessTest.php            # OUT=var/harness/settle/runs/settle.jsonl
    python3 var/harness/settle/settle.py var/harness/settle/runs/settle.jsonl [more runs] [--csv out.csv]

Env: `YEARS` (10), `TPY` (360), `SEED` (1), `TICKERS` (comma list), `OUT`.
- `MACRO=frozen` (default): the opening DTO, with potential and nominal GDP, the deflator and the CPI grown at trend.
  `MACRO=path`: the macro engine's own noiseless path. It runs a little hot (gap +0.5-0.8%, policy rate 3.4% -> 4.5%
  in two years), which lifts bank margins and every P/E (ops 19.6 -> 25.8 by year 10, against 20.1 frozen).
- `DRAWS=none` (default): ScriptedDraws, medians for uniforms, zero normals, no events. `DRAWS=seeded`: the shared
  stream from `SEED`. The mean of 4 seeded runs matches the noiseless run to <0.5pp on returns and <1% on size, so
  the noiseless run stands for the mean path.

`settle.py` flags a firm when, by year 5, its return state moved >3pp, revenue/GDP or equity/GDP >25%, margin >5pp,
or net D/E >50% (and >0.1).

## Not covered
- Fee financials (investment banks, brokers, shadow bank, hedge fund, asset manager): here their nominal revenue is
  flat (revenue/GDP 0.64 at year 10), but on the whole board (scorecard sfc0, 8 seeds) KING/PERE/CORV/PTRL grow
  17-53%. They need the market the board provides; read them on a BoardRun.
- Spheres (BRKW): holdings stay at their opening marks.

## Results 11-19 at e1b30b23 (frozen, 10y)
59/103 flagged. Medians, operating firms (n=82):
- cash/assets 8.6% -> 7.1% (y1) -> 5.2% (y5), then flat. The live board shows the same fall (7.2% -> 4.6%): seed
  treasuries sit ~3pp of assets above what the model holds, and the surplus goes out as buybacks (shares -8% by y5).
- return state 16.0% -> 15.0% (y1) -> 14.6%, then flat: a firm closes ~25% (IQR 15-36%) of its seed gap over
  required + moat in 1-2 years and holds there. The step scales with the seed return: JDAW 35 -> 25, LYRE 30 -> 22,
  PENG 29 -> 22, BREW 23 -> 18.
- net D/E 0.60 -> 0.74 = the model's target (gross 0.54 -> 0.62); live gross holds ~0.6, so part of this is the calm.
- equity/GDP 0.87 by y5, P/B 3.54 -> 4.44 (ROE up on the leverage), revenue/GDP 0.97, margin 20.0 -> 20.2.
Financials: banks hold ROE (15.0 -> 15.4) but their reported margin opens far from the seed's (38 -> 49%); insurers
and card lenders ROE 17.5 -> 12.3 (SAFE 17.5 -> 12.3); REITs' leverage doubles (1.0-1.2 -> 2.1-2.3) and margins fall
6-10pp.

## Banks built from character (11-19)
LAKE/RIVR/PLVR/EGRT/BNTH now state revenue, wholesale funding share, moat, addressable share and retained share; the
bank model builds their books (`banks_after.jsonl` vs `frozen.jsonl`). Opening margin now equals the first report's
(LAKE 45 -> 51.6 opening, files 52.4 either way); BNTH opens at its target cash (7.2% -> 8.2% of assets). ROE, cash,
equity/GDP and P/B paths unchanged within 0.4pp; the other 98 firms are identical. Shared by both arms, not a seed
effect: bank cash climbs from ~9% to ~10.9% of assets by y5 (past the 10%-of-deposits target), and PLVR/BNTH ROE
drift up ~1.5pp over 10 years.

## Moat ladder (11-19)
Every firm carries a moat step (`moat.jsonl` vs `banks_after.jsonl`). Operating firms' return state now reverts to
their own step instead of the model's 0.5-3pp: median 10y drift -2.07 -> +0.08pp (IQR -3.4..-0.6 -> -0.5..+0.6), ops
ROIC at y10 14.7 -> 16.7%; P/B and P/E unchanged. Banks open on cost of equity + step: LAKE 21.0 -> 18.1% ROE (P/B
3.25 -> 2.74), RIVR 15.0 -> 13.6, PLVR 11.0 -> 10.1, EGRT 13.0 -> 12.2, BNTH 18.0 -> 19.2. Insurers settle higher (SAFE
+3.3pp, DOVE +1.5). The other financials do not read the moat.

## Operating firms built from character (11-19)
77 operating rows (all but the 4 REITs and BRKW) state revenue, operating margin and addressable share;
`StandardCorporateBusinessModel::resolveOpeningBooks()` builds return (asset CAPM + moat), equity and debt at the
manager's leverage target under the covenant cap, cash at the treasury target, retained = half of equity
(`ops_after.jsonl` vs `ops_before.jsonl` at 4b4dd689; compare runs separately, settle.py averages files it is given).
Medians over the 77, q0/q4/q8/q20/q40:
- cash/assets 8.7/7.4/5.7/5.3/5.3% -> 4.4/5.8/4.9/4.7/4.6%; net D/E 0.58/0.68/0.70/0.73/0.73 -> 0.75/0.74/0.74/0.73/0.74
  (|D/E - target| at q0 0.235 -> 0.000, max 1.18 -> 0.32); P/B 3.60 -> 4.59 by y10 before, 4.03 -> 4.34 after.
- opening return gap to required + moat median +0.52 -> +0.08pp, max 11.4 -> 0.3pp; 10y drift IQR -0.47..+0.51 ->
  -0.18..+0.50pp. Shares -9.8% -> -9.6% by y5: the buybacks are the ordinary payout, not the seed's cash surplus.
- flagged 38 -> 13 of 103; among the 77, 30 -> 5 (BIRD, WADE margin; SKYL ret/margin; TICK rev/eq/margin; IBIS eq/lev).

## Card lenders and the shadow bank built from character (11-19)
TALN/STRK state revenue, wholesale funding share, addressable and retained share; POOL states revenue, its
pre-credit-loss operating margin, addressable and retained share (all wholesale by construction). With no capital
target a lender opens at its manager's leverage target, debt and leases over equity (`lend_after.jsonl` vs
`lend_before.jsonl`; the other 100 firms identical). TALN margin 45.0 -> 37.5 opening (files 37.7-37.9 either way; flag
gone); STRK 32.0 -> 32.9. POOL ROE 29.0 -> 25.1 (top of the moat ladder; was drifting to 32.1, flag gone), revenue
24.0 -> 17.4bn to hold its equity at 55bn, cash 9.8 -> 4.0% of assets (it fell to 4.8% by y5 either way). POOL's
margin flag (50 -> 43.8) is its row's margin being before credit losses; the filed margin is after them.
Then TALN wholesale share 6.4 -> 25% and POOL moat very_wide -> moderate, revenue 17.4 -> 8.06bn to hold equity at 55bn
(`lend2.jsonl`): TALN margin 39.4%, ROE 25.1 -> 26.6 by y10, cash 6.5% of assets; POOL ROE 16.1 -> 18.6 by y5 (P/B 3.1 ->
1.85), filed margin 34.4% after credit losses against its 50% pre-loss margin (flag).

## REITs built from character and capped at target leverage (11-19)
SHOR/PLZA/ELDE/KITE state revenue, operating margin, addressable share and retained share; `ReitBusinessModel` opens at
its market cap rate (10.68%) and caps debt expansion at `min(debtTolerance, leverageTarget)` (`reit_after.jsonl` vs
`reit_before.jsonl` at 40e61299; the other 99 firms identical). Opening book D/E 1.12-1.30 -> 0.81-1.14, cash 3.4-9.2%
-> 2.3-2.5% of assets, ELDE equity 25.0 -> 19.3bn. By y10, every REIT tracks its target leverage within 0.015 (SHOR
1.48 vs 1.48, PLZA 1.20 vs 1.21, ELDE 0.87 vs 0.88, KITE 1.28 vs 1.29; before: SHOR 1.86, PLZA 2.28, ELDE 1.13 vs 0.52),
with share growth 0.15-0.17%/yr. Flagged 11 -> 10 of 103 (SHOR margin flag gone; PLZA and ELDE keep margin flags; zero
REIT leverage flags).

## REITs valued on AFFO, opened untaxed, and worn at land-adjusted rates (11-19)
Opening turnover uses the model's own effective tax rate (0 for trusts), equity-REIT industry depreciation rates are
halved (0.03 -> 0.015, 0.04 -> 0.02), and `FairValue` deducts `maintenanceReservePerShare` into AFFO while rescaling the
target payout so the dividend leg pays the same cash (`affo_after.jsonl` vs `affo_before.jsonl` at 9a8a3bab; the other
99 firms identical). Q1 revenue no longer drops 16.6-16.9% (now +0.18 to +0.53% of GDP, +0.76 to +0.91% nominal); P/B
falls from 3.17-3.48 (q0) and 3.66-4.50 (q40) to 1.91-2.13 (q0) and 2.30-2.65 (q40); P/FFO at q0 drops from 15.8-16.5 to
11.2-12.9 (dividend yield 4.9-5.9% at q0, 5.3-5.6% at q40); y10 D/E tracks `de_target` within 0.071. Flagged 10 -> 8 of
103 (PLZA and ELDE margin flags gone; zero REIT flags).

## REIT Plan B: cap rate, beta, rent growth, wear (11-20)
One cap rate (`k_A - rent_growth + upkeep`) from `IndustryBetas::DISTRICT_REIT_ASSET_BETA = 0.68` (+2.13-2.58pp over the
opening 4.62% 10Y) mapped to historic-cost book via `historicCostPropertyValueRatio()`; book/tax depreciation restored to
3% (4% hotels) with separate 1.5% upkeep/AFFO reserve; `updateDynamicRoic()` measures NOI before depreciation and
`resolveLongRunReturn()` tracks live `bookCapRate() + excess` (`RETURN_PERSISTENCE = 0.80`); `FairValue` grows REIT
dividends at `tipsBreakeven` and values the book leg on property NAV (`reit_planb_after.jsonl` vs
`reit_planb_before.jsonl` at 7e4025da; all 99 non-REIT firms bit-identical). Q1 return jump gone (`10.68 -> 12.3-12.7%`
becomes `10.1-10.8% -> 10.2-10.9%`); P/B `1.91-2.05` (q0) and `1.83-2.00` (q40) vs `1.91-2.13 -> 2.30-2.65`; P/FFO
`12.2-13.0` (q0) and `13.5-15.6` (q40); y10 D/E within 0.02 of `de_target`. Flagged 8/103 unchanged (zero REIT flags).
