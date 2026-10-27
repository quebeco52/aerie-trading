# Valuation predictability: where does the board's value premium come from?

Question family: cheap-minus-dear (E/P, P/F) spreads, cross-sectional slopes, momentum, the price-to-fair-value gap
g = log(P/F), its persistence and its attribution to price channels.

- Harness: `ValuationHarnessTest.php` on `var/harness/lib/BoardRun.php` (live macro, no politics) with the price_vol
  channel probe (`../price_vol/Probe.php`, read-only; its `sink` hook feeds each name's per-tick channel vector and
  perceived fair value). At every quarter end it writes per live name: split-adjusted price index, price, perceived
  fair value per share, EPS (totalNetIncome / shares), shares, float-adjusted cap, and the quarter's 15 channel sums
  (Probe::NAMES; the 14 price channels sum to the total log return incl. dividends, `dfv` = d log fair value).
  `unmatched` in the output must be 0.
- Run script: `run.sh head <seed>`; YEARS (20), TPY (360). Outputs `runs/head-<seed>.{jsonl,log,val.json}`.
- Batch: `bin/harness-batch var/harness/valuation/run.sh 1-4 head && python3 var/harness/valuation/analyse.py head 1-4`
- `analyse.py <arm> <seeds>`: year 1 skipped. (1) quintile spreads of next-year total return by E/P, -log(P/F) and
  past-year return; yearly slopes/R2 of next-year log return on log E/P and log(P/F). (2) sd and pooled quarterly AR(1)
  of g. (3) var(g) = sum of cov(piece, g) over trailing 1y/2y windows: market (mkt+jS), sector, own (idio+jI), flow
  (flow+fund), news (rep+warn+ma+pol+betw), pull, breaker, -d log F, the initial gap, and the dividend term that
  closes the identity; Fama-MacBeth slopes of next-year return on the 1y pieces. (4) var split of annual d log P into
  d log F and d g; median-firm vol of log P and log F. (5) float-weighted board log(P/F) vs next-year board return.
- Arms: `head` / `d1` = working tree, `base` = BASE tree (git archive + vendor symlink in the scratchpad; delete it and
  `var/harness/cache-<basename>` after). Snapshots now also carry the engine's live cost of equity and a financial flag;
  deal events are counted by type (`deals`).
- `d1.py <seeds> A B`: predictability, the architect's gate (yearly slope of d log F + D/F - CoE on log(E/F), split
  non-financial / financial), gap sd and half-life, board (cap, P/E, vol, defaults, B-or-worse, buybacks, issuance,
  deals), quarter-1 fair-value jumps, NaN checks; paired B - A.
- `d2` / `d2b` arms ran on working trees that were not kept (see valuation_evidence.md); their runs and scripts stay
  for comparison. `d2` arm = working tree (D2: fair-value revision passed into the price; Probe `pol` = r['fair_value_news'], older trees
  fall back to the policy repricing). The harness also logs per-name daily split-adjusted realised vol (`rv`), index
  vol (`rvIndex`), StockTracker's yield exposure EMA at years 5/10/20 (`bhat`), and the probe audit (`capHit` = own-source
  variance at the 80% cap, `ann` = announcement variance). `d2.py <seeds> A B` = d1.py per seed + those, the revision's
  annual variance and var(g) share, and board E/P and log(P/F) R2.
- `d2b` arm = working tree (D2 + no output-gap term in perpetual growth + revision variance on its own quarter-window
  EMA; probe audit `rev`). `d3.py <seeds> A B C`: three arms (d1.py + d2.py measures, median revision variance) with
  paired C - A and C - B, board E/P / log(P/F) R2, and the correlation of the board's annual d log F and d log P with
  the change in the output gap (quarter-log `gap`).
- `c1` arm = BASE tree a8207c15 + the cycle-fix patch alone (patch -p1 in the archived tree), compared with `d3.py 1-4 d1 d2b c1`.
- `f1` arm = working tree (cycle fix + F1 durable demand). `recession.py <seeds> A B` (META=<architect meta.json>):
  revenue/EBIT/NI betas on the annual gap change by group (F1 models, order-book cyclicals, other), recession episodes
  (board gap down >= 3 pp) with peak-to-trough NI and trough lag, growth, beat rate (revenue vs expected), board NI vs
  price lead-lag.
- Runtime ~30 s per seed-decade with the probe.
- `crash` arm = working tree with MACRO_OUT (runs/crash-<seed>.macro.jsonl, extended in ValuationHarnessTest::macroRow
  with y10, credit, tips, mood, mz_latent, vol_ema, ebp) and BOARD_NI. `crash.py <arm> <seeds>` (DD=0.25): index
  drawdowns with the build-up (neutral board g to peak), fall and rebound decomposed into d log F, d g and the Probe
  channels, board NI and the macro deltas; the quarterly loading of the market channel, d log F and d g on macro
  changes; next-4q returns conditional on board g, on the first 25% close and from the trough. Runs on any arm's
  snapshots (macro columns only with the crash arm).
- `b1` arm = the crash arm's set-up on the working tree with fix B (CAPM on MacroStateDTO::equityRiskFreeRate, the 10y
  term premium plus the expected rate path at an equity's duration). Base = `crash` runs (98b7d96a); arms pair on the
  macro. `crash.py b1 1-8` (crash_b1_8s.out) and `d1.py 1-8 crash b1`.
