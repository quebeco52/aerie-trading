# price_formation harness

Question family: does a change to MarketEngine drift / fair value move stock price-formation moments?

- Harness: `PriceFormationHarnessTest.php` (fork of `var/harness/FullMarketHarnessTest.php`, same tick order:
  macro, operator every tpy/24 ticks, `StockTracker::updateStocks`). With `PF_OUT=<json>` it writes, per seed:
  quarterly board median log(price / perceived fair value), median trailing P/E (price / TTM EPS, EPS > 0),
  yield10y, policyRate; and per name-year sums of tick log total returns (split-adjusted via lastSplitAt and the
  share-count ratio, dividend from the CorporateReport added back) over all ticks and over earnings-report ticks.
- Bootstrap: `bootstrap.php` here (copy of var/harness/bootstrap.php; HARNESS_PROJECT defaults to this worktree,
  `BASE=<tree>` selects the tree; kernel cache goes to `cache-<tree basename>/`).
- Arms (`run.sh <arm> <seed>`):
  - `rec`    AFTER tree (this worktree), free macro with the board cap fed back; records `macro/path-<tpy>-<seed>.gz`
  - `after`  AFTER tree, replays the recorded macro path
  - `before` BEFORE tree (`BEFORE=<tree>`, default /tmp/claude-1000/b1 = worktree with src replaced by var/arms/batch1-src)
  Compare `after` against `before`, never against `rec`. Each arm opens the board from its own `app:market-seed`.
- Round 2 arms: `2a`/`2b` replay on /tmp/claude-1000/t2a|t2b (b1 skeleton + `cp -al` vendor + src = var/arms/<arm>-src;
  rebuild that way if /tmp was cleared), `c` = this worktree. Runs in `runs2/`; `2a-1..8` are copies of `runs/after-*`
  (2a reproduced bit-identically over year 1). `python3 analyze.py runs2 2a 2b` prints <Y>-<X> paired table.
  Clear `cache-<tree>/` whenever that tree's src changes (test env, debug off: the container is never rebuilt).
- Decomposition: arm `cx` (= c plus PF_X=1) adds per-name quarterly px, fv, eps, shares, NI, equity, cumulative log TR,
  cumulative dividend log term, split factor, CoE, CAPM, borrowing-rate floor, levered beta (DebtEngine
  analyzeTrailingDebtHealth, read-only: cx reproduces c bit for bit). `python3 decompose.py runs3 [cx]` prints the
  equity-premium table (A three ways, B return identity for index and names, C CAPM side, D macro).
- Growth: arm `cg` (= cx plus PF_G=1) recomputes fair value's growth each quarter from MarketPricingContext::forStock
  (analyzeDebtHealth, as StockTracker does) with the same MathUtility/strategy calls as MarketEngine::evaluateFundamentalState,
  and logs per-report NI/div/buyback/equity flows. `cgchk` adds PF_FVCHK=1, which re-strikes evaluateFundamentalState by
  reflection (2y check: median |log(FV_restruck/FV)| 0.01%, max 0.8%). `python3 growth.py runs4 [cg]`.
- Paired with all extractions: BEFORE `2c` (/tmp/claude-1000/t2c, src = var/arms/2c-src; run with `PF_X=1 PF_G=1` in the
  environment) vs AFTER `cg` (worktree). `python3 pair.py 'runs5/2c-*.json' 'runs5/cg-*.json'` combines analyze/decompose/growth
  per-seed stats (+ MAX_EXPECTED_GROWTH binding share). runs5/2c-1..8 are copies of runs4/cg-* (2c reproduced them bit for bit).
- Equity bridge: arm `cb` (= cg plus PF_B=1) swaps forwarding subclasses into StockTracker::earningsEngine,
  CapitalAllocationEngine::treasuryEngine and StockTracker::maEngine (reflection) and books each call's equity delta per
  ticker-year, plus per-tick and operator deltas and report flows (NI, div, buyback, SBC, impairment, asset-sale loss,
  revenue, capex, depreciation, industry_price_level). cb reproduces cg bit for bit; the bridge closes to 0.
  `python3 bridge.py runs6 [cb]`. AnchorStakeLedger is final, so anchor marks are not split from AFS marks.
- Batch: `YEARS=20 ./batch.sh <runs-subdir> "rec after before" 1 2 3 4 5 6 7 8` (rec first per seed, then the
  replays in parallel; php-slot caps concurrency). ~85-95 s per 20-year run at 360 ticks/year.
- Analysis: `python3 analyze.py <runs-dir>`: paired table over years 2..Y (year 1 discarded):
  (a) time-mean of quarterly board median log(P/FV); (b) OLS slope of that on (y10 - policy);
  (c) time-mean board median trailing P/E; ERP = board median annualized total return of survivors - mean y10;
  2c: board median share of annual realized variance on earnings ticks, board-mean log return on earnings ticks.
- Outputs: `runs/<arm>-<seed>.{json,log}`, tables in `*.out`, history in `price_formation_evidence.md`.
  `beats/reports` = published surprise > 0 (last entry of earningsSurpriseHistory on report ticks, years 2+).
- Caveats: replay removes the board-cap -> equity-wealth -> macro feedback inside the compared arms (it is in the
  recorded path only). Hook note: the Write tool refuses worktree paths; files here were written with Bash.
