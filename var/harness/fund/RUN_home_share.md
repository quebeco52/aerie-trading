# Reserve fund home-equity share: board price impact

Harness: `var/harness/fund/HomeShareImpactTest.php` (KernelTestCase on `var/harness/bootstrap.php`).
Seeds the board with `app:market-seed`, then runs live macro + `FailureSweep` (every tpy/24 ticks) +
`StockTracker::updateStocks`, feeding the macro every board input `MarketTickerCommand` feeds, one tick behind
(total cap, float cap, price return, dividends, net issuance, stamp duty, strategic-stake cash, bank levy).
No politics: the law sits at `SovereignFundSubsystem::OPENING_HOME_EQUITY_SHARE` (0.2299).

Arms (`ARM`):
- `base`   no change.
- `closed` at `SWITCH_YEAR` (3.0) set `MacroState::reserveHomeEquityShare = HOME_CLOSED` (0.5,
  `PoliticsEngine::POLICY_PROTECTIONIST_RESERVE_HOME_SHARE`), by editing the Redis macro state once; nothing else changes.
- `open`   same, to `HOME_OPEN` (0.0): the fund sells its board holdings.

Run script: `var/harness/fund/home_share.sh <arm> <seed>` (env YEARS=8 TPY=360 SWITCH_YEAR=3.0, RUNS=<dir>, default
`var/harness/fund/runs_home/`). ~35 s per 8-year run.

Stage (6 slots):

    PHP_SESSION_SLOTS=6 YEARS=8 TPY=360 bin/harness-batch var/harness/fund/home_share.sh 1-8 base closed open && \
      python3 var/harness/fund/home_share_an.py > var/harness/fund/home_share_s8.out && cat var/harness/fund/home_share_s8.out

Analysis: `var/harness/fund/home_share_an.py [runs dir]`: paired arm - base per seed, on the chained float-weighted
board price index (log, from `board_price_return`), at end of the 20-month transition and at the last tick;
fund ownership of float; currency bought / float over the transition (sum of tick trade / float cap); annualised
per-tick return vol over the transition; mean dlog path by month after the switch. Pre-switch paths are identical
(checked: max |dlog| before switch is printed).

Gotchas:
- Fixed 2026-10-09: `var/harness/FullMarketHarnessTest.php`, `FundHarnessTest.php` and `fund/FundMandateTest.php` now
  use `FailureSweep` and `Market\Pricing\StockTracker`, and feed every board input. The full-market harness can also run
  the Diet (`POLITICS=1`; see `var/harness/full/RUN.md`).
- `var/harness/cache/test` is compiled with debug off and never refreshed: delete it after `src/` service changes.

Evidence: `fund_evidence.md`, section "Home-share law: board impact".
