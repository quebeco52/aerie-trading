# Valuation harness

Question family: how a change in `Valuation` (fair P/E, sector prior, return fade) moves rate sensitivity, fair value
and price across sectors on the whole board.

- Harness: `var/harness/valuation/ValuationHarnessTest.php`, a fork of `var/harness/FullMarketHarnessTest.php` (env in
  `var/harness/full/RUN.md`). Extra per-run output, every tick: `v_px`, `v_fv` (perceived fair value), `v_eps`, `v_sh`
  (shares, for split adjustment) per ticker, `v_r10` (yield10y - tipsBreakeven), `v_idx` (cumulative board log return);
  per report `v_rep` = [ticker, tick, roicTtm, longRunReturn, strategy true return (roeTtm for financials)];
  `v_model` (business model short name), `v_ind`.
- Baseline tree: `bash var/harness/valuation/make_base.sh` builds `/tmp/claude-1000/valuation-base` (rsync of the
  worktree without .git/vendor/var, src diff reversed, vendor symlinked). Rebuild after any src change.
- Run script: `var/harness/valuation/run.sh <arm> <seed>`; YEARS (15), TPY (360).
  - `rec`: baseline tree, live macro, records `macro/path-<seed>.gz`.
  - `base`: baseline tree replays it. `new`: this worktree replays it. Both pass BASE=<tree> (own src, own cache).
- Batch: `bin/harness-batch var/harness/valuation/run.sh 1-8 rec && bin/harness-batch var/harness/valuation/run.sh 1-8 base new
  && python3 var/harness/valuation/analyse.py 1,2,...,8 > var/harness/valuation/<name>.out`
- Analysis: `analyse.py <seeds>` (pure Python, no numpy on this machine). `VW=<ticks>` sets the return window (21).
  Tables: rate beta market-held and rate-only, fair-value rate beta, log P/E slope on the real 10Y, sanity, return-gap
  persistence (omega, within-firm, valuation impact at r=8%), post-report drift.
- Runtime: about 75 s per 15-year run; 16 runs at once take about 2.5 min.
- Micro draws do not pair across arms (only ~11% of early quarterly revenues match): the seed-paired difference shares
  the macro path only.
- Splits happen (LARK 4:1 in one arm only), so per-share series are split-adjusted from `v_sh` jumps.
- The Write/Edit guard keys on the staples-step1 checkout; edit copies in the scratchpad and `cp` them in.
