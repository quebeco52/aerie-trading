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
- Runtime ~30 s per seed-decade with the probe.
