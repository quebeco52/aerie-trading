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
- `a1` arm = working tree with fix A on b15e4a54 (B committed): the market factor's unexpected log return reprices fair
  value by beta (MacroStateDTO::equityValuationShift, 5-year half-life), the drift earns its fade, beta levered on fair
  value without it. Base = `b1`. `crash.py a1 1-8` (crash_a1_8s.out) and `d2.py 1-8 b1 a1` (board E/P R2 at the end).
- Momentum (10-27): `momentum.py <seeds> <arm> ...` = momentum 12-0/12-1, PEAD, news/noise slopes, E/P spread, gap sd,
  vol, defaults, blow-ups, and from VAL_OUT `agent_rows` (momentum agents' share) and `franchise_rows` (franchise power,
  belief gap). Arms in run.sh: `mbase`/`mbase2` = BASE tree b29b723f, `mom*` = working tree or `ovr-*` overlays of one
  constant (rebuild an overlay from src after src edits). BoardRun fixes the outlook salt from 10-27, so arms pair on firms.
- Franchise fix (10-28): `fbase` = BASE tree 2b345931, `ffix` = working tree (power steps on the value elasticity at
  the current power, news share capped by FRANCHISE_POWER_VOLATILITY 0.35, run costs scaled), `ffix50` = ffix with
  OVR=<dir> holding MarketEngine.php at 0.50. `BASE=<tree> bin/harness-batch $V/run.sh 1-8 fbase ffix && OVR=<dir>
  bin/harness-batch $V/run.sh 1-8 ffix50`, then `momentum.py 1-8 fbase ffix ffix50` and `franchise.py 1-8 fbase ffix
  ffix50` (power sd / max / count > 2, SAFE, P/E max and p90, top cap share, P/F max at the end). ~8 min for 24 runs.
- Franchise life (10-30): `fnow` = working tree at 625e9ca8 (10-year half-life, power vol 0.35), `fhl3` = working tree
  with a 3-year half-life and vol 0.20. Both arms ran on the working tree, `fnow` before the edit. Run
  `bin/harness-batch $V/run.sh 1-8 fhl3`, then `franchise_life.py 1-8 fnow fhl3` (margin and ROE drift from structure,
  firms under 5% ROE, SWAN ROE and margin, cash/equity, low-return hoarders), `franchise.py` and `momentum.py` on the
  same arms. The hoarder count also flags lenders whose cash is their balance sheet; read the names before acting on
  it. ~2.5 min an arm.
- Board multiple (10-30): `mv1` = working tree on ac0ae3ae with ManagementValuation::fairValuePE repriced by the market
  factor at the stock's beta (MacroStateDTO::equityRepricing()), as fair value is; base `fhl3`. `market_board.py 1-8 fhl3
  mv1`: buyback yield and its correlation and slope on V (the factor rebuilt from the probe's mkt + jS channels at the
  5-year fade), median split-adjusted share count y20/y1, board P/E min / max / sd log, sd V, deals.
- Mainland fade (10-30): `fx0` = BASE tree of 17923936, `fx1` = working tree where the foreign equity index gives its
  market-factor move back at the home half-life (MacroState::foreignEquityValuationShift); both write quarterly macro
  rows (`v`, `fidx`, `fshift` added to macroRow). `BASE=<tree> bin/harness-batch $V/run.sh 1-8 fx0 fx1`, then
  `mainland.py 1-8 fx0 fx1` (mainland return, vol, variance ratios, board-mainland 5-year drift and correlation, fund
  over GDP) and `market_board.py 1-8 fx0 fx1`. ~5.5 min for 16 runs.
- Recession pass-through (10-30): `rn0` = BASE tree of 66379eba (the revenue-noise fix, frozen before it was committed),
  `dr1` = that tree + the market-wide discount-rate move priced at once. In dr1, each tick fair value is struck again
  at the hurdle the last tick's equity riskless rate and premium gave, at the name's levered beta, and the price takes
  the ratio beside the policy repricing (no variance budget). The trial was not kept; its patch was saved outside the
  repo. The Probe adds the move to `pol` and keeps `drTick`; macro rows carry `erf`, `drq` (the board's quarterly
  discount-rate log move) and `drv` (its daily-read variance, annualised).
  - Command: `BASE=<tree> bin/harness-batch $V/run.sh 1-8 rn0 && bin/harness-batch $V/run.sh 1-8 dr1`, then
    `recession_dr.py 1-8 rn0 dr1`.
  - dr1 vs rn0, 17 recessions:
    - median excess recession return +1.9% -> -2.8% (t -1.6);
    - annual corr(return, gap change) 0.13 -> 0.31 (t 4.3);
    - corr(return, last quarter's gap change) 0.00 -> 0.10 (t 7.5);
    - index vol 15.2 -> 19.3% daily and 15.7 -> 20.0% annual.
  - The move is 96% the riskless rate: duration about 16, quarterly sd 6%, daily-read vol 12%.
  - A premium-only pass-through, estimated on rn0, gives:
    - excess -15%, falling in 79% of recessions;
    - annual corr 0.48;
    - predictability 0.23, because the premium reads the 0.25-year gap EMA.
- `dr2` = working tree on 66379eba:
  - only the premium's move is priced at once (beta x d ERP);
  - the habit premium reads the current output gap, not its 0.25-year EMA;
  - the market factor gives back the premium's measured variance (StockTracker reads it on trading days, as a 1-year
    EMA; MacroState::riskPremiumVariance carries it to AssetMarketSubsystem; VarianceBudget gives it back too).
  - `recession_dr.py 1-8 rn0 dr1 dr2` (recession_dr_8s.out), dr2 vs rn0:
    - median excess recession return +1.9% -> -17.0% (t -8.8); fell in 48% -> 79% of recessions;
    - annual corr(return, gap change) 0.13 -> 0.61; predictability from last quarter 0.02 (t 1.0);
    - index vol 15.2 -> 16.0% daily and 15.7 -> 18.0% annual (VR 0.89 -> 1.02).
  - `d1.py` / `momentum.py`: momentum 12-1 -3.0% -> -7.3% (t -3.5); median P/E -0.5; deals +16 (t 1.6).
  - corr(premium, next-year board excess return) 0.11 -> 0.43, slope 9.7: fair value capitalises the premium as
    permanent while it reverts with the gap, so the price overshoots and the rebound is predictable.
  - Scorecard arm `sdr2` (var/harness/scorecard, base `rnoise2`): median worst drawdown -47% -> -51% (t -5.7), firms
    with drawdowns over 80% 3.0 -> 4.5 (t 1.9), defaults 0.5 -> 1.0 (t 1.9), the rest flat.
  - dr2 was reverted (10-30); its patch was saved outside the repo.
- Factor on economic news (10-30): `fc0` = BASE tree of 66379eba plus the mainland's quarterly surprise accruing tick
  by tick (same distribution, same macro draws as fc1); `fc1` = working tree:
  - the market factor's shock loads 0.6 on the economy's news: the demand disturbance's draw and the step in the
    mainland's accruing surprise, each weighed by its mean effect on the output gap over two years
    (AssetMarketSubsystem::economicNewsZ), read the tick after the draws;
  - one premium: the District's ERP is the one the market's price implies (V), firms' hurdles (WACC, financials' CoE,
    divestitures, REIT cap rate) read it, and valuation (fair value, drift, ROIC anchor, management valuation, the
    levered beta's business value) is struck at the base premium, since V reprices value itself;
  - the variance shock loads on the fresh leg (news + tail), so bad news raises vol.
  - Command: `BASE=<tree> bin/harness-batch $V/run.sh 1-8 fc0 && bin/harness-batch $V/run.sh 1-8 fc1`, then
    `recession_dr.py 1-8 fc0 fc1`, `d1.py 1-8 fc0 fc1`, `momentum.py 1-8 fc0 fc1`.
  - `fc1r8` = fc1 with the loading at 0.8 (OVR copy of AssetMarketSubsystem); the working tree now has 0.8.
  - `recession_dr.py 1-8 fc0 fc1 fc1r8` (recession_fc_8s.out; `erp->next y` added), paired on identical macro draws:
    - corr(quarterly return, gap change) -0.02 -> 0.05 (t 3.7) / 0.09 (t 4.4); annual 0.04 -> 0.06 (t 0.8) / 0.13
      (t 2.8); lead -0.05 -> +0.05 / +0.03 (t 1.0-1.7);
    - recession excess -7.8% -> -4.8% / -6.3% (t 0.2-0.4): no measurable change. The duplicate premium's delayed fall
      left as the news came in, and the shocks explain only ~20% of a year's gap change (R2 on the probe, 26% with a
      year's lag). fc0 itself falls -7.8% in 66% of recessions: rn0's +1.9% on other macro paths was largely the draw;
    - predict 0.13 -> 0.03 (t -3.9); vol in recessions over expansions 1.20 -> 1.29 / 1.31 (t 4.3); daily index vol
      15.3 -> 15.6 / 15.9%; premium -> next-year return 0.04 -> 0.32 (the premium now reads V, whose fade the drift
      earns).
  - Guards (d1.py, momentum.py): momentum 12-1 -5.6% -> -4.2% (t 1.2) / -3.4% (t 2.7); median P/E +1.0 / +1.4;
    defaults and deals flat. Scorecard `sfc0`/`sfc1` (var/harness/scorecard): every standard flat (|t| < 1.5).
- Economy's events (10-30 late): `fc8` = frozen tree of the fc1 build (loading 0.8), `ev1` = working tree:
  - the domestic news weight reads demand's own pull (OutputGap::demandGapPath, StochasticProcesses::cascadeMeanPath):
    the District's share of the stream 0.49 -> 0.15;
  - demand disasters, mainland crashes and credit crises add compensated news of the gap's 2-year path
    (MacroState::economicEventNews); the factor prices all gap news at one price (pricePerGapPath 17.4 per point), the
    stream and the events sharing the 0.8^2 news budget (stream loading 0.585), the events released over
    EVENT_REPRICING_TAU_YEARS 0.02 through marketJumpMultiplier; Kou crash days kept;
  - the sector price shock loads 0.8 on the sector demand factor's innovation (MacroEngine, same draw order).
  - Command: `BASE=<tree> bin/harness-batch $V/run.sh 1-16 fc8 && bin/harness-batch $V/run.sh 1-16 ev1`, then
    `recession_dr.py 1-16 fc8 ev1` (recession_ev_16s.out), `momentum.py 1-15 fc8 ev1` (momentum_ev_15s.out: ev1 seed 16
    has reorganisations whose split-adjusted price jumps 2-3 log points, a harness adjustment artefact that wrecks its
    momentum sort), `d1.py 1-16 fc8 ev1`.
  - ev1 vs fc8, 16 seeds paired: recession excess -9.9% -> -16.1% (t -1.6), fell 55% -> 73% (t 2.1), raw -0.2% ->
    -6.6%; by depth tercile (excess) deepest -7.9 -> -15.8%, middle -11.0 -> -18.2%, shallowest +8.4 -> -6.2%; corr y
    0.21 -> 0.32 (t 4.0), corr q 0.17 -> 0.20 (t 1.9); lead -0.03 -> -0.01 (unchanged, as the lead strand predicted);
    daily index vol 15.9 -> 14.2% (t -13: the events' share of the budget lands over a week), quarterly 17.1 -> 17.2,
    annual 17.3 -> 17.7, VR 1.04 -> 1.09; vol rec/exp 1.27 -> 1.30 (t 1.5); momentum 12-1 -3.7 -> -3.3% (t 0.7, 15
    seeds; the sector piece moved it little); PEAD 2q 5.0 -> 4.3% (t -2.3); defaults+deaths 0.75 -> 1.19 (t 1.7);
    P/E, deals, issuance, final cap flat.
  - The harness now names the first non-finite value in VAL_OUT and writes it as 0 (ev1-16: TERN's breaker channel at
    its year-2.42 reorganisation, in both arms' slump).
