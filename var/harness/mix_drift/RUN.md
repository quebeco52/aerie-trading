# Revenue-mix drift (StreamContext weights)

Question: how far do firms' revenue-line weights drift from their plan, and what does it do to revenue? The old
`resolveActiveStreamWeights()` moved each weight 0.15 toward last quarter's realised share and 0.08 back to target, so a
line's market terms were counted in its revenue and again in its weight (runaway past 1.53x its normal).

- Harness: `MixDriftHarnessTest.php` (read-only probe). Writes `runs/<arm>-s<seed>.json` (`MIX_DRIFT_OUT`): per firm, its
  model and every quarter's `weight:*` and `share:*` keys, revenue, net income and price.
- Run: `bin/harness-batch var/harness/mix_drift/run.sh 1-4 <arm>`; fixed macro path: `replay.sh` (arm `rec` records
  `runs/macro-s<seed>.gz`, any other arm replays it).
- Analysis (from this directory): `analyse.py <arm>` (weight drift per line), `impact.py <arm>` (revenue at the opening
  weights against actual, from each quarter's weights and shares), `compare.py <a> <b>` (arms by seed).
- Arms run 10-29: `before`/`after` (live macro) and `before_r`/`after_r` (macro replayed; `before_r` ran HEAD's
  StreamContext, TimeSeries and FinancialConstants, with Biotech already recording its actual mix).
- Findings: before, 25/84 firms had a line >25% off plan, but revenue distortion was <2% mean except TIER (fees
  inflated up to +76% in carry-free quarters, mean 1.5-14%). After: board 20y revenue -0.8% (SE 0.28) live, -0.5%
  (SE 0.36) replayed; firm-level arms diverge (±10-20% on firms the fix cannot reach) even on a replayed macro, so
  per-firm effects are read from `impact.py` on `before`, not from the arm difference.

## Line drifts (Stage 1), 10-30
- Arm `lines_r` (macro replayed, seeds 1-4): security moved onto `TimeSeries::driftedLineShares`/`lineMixExcessGrowth`,
  advertising lines drift (media -2%, martech +4%). `lines.py after_r lines_r`.
- Pilot weights match the closed form to the 5-decimal rounding; every non-pilot firm on a fixed plan keeps identical
  weights (the ones that differ keep segment books, or BRKW, whose weights follow its holdings).
- Revenue: LYRE last 2y +13.5% ±12.5 (ledger plan +12% at 20y); security firms -5..+11%, all within noise; board
  +0.55% ±0.79. Security revenue is identical through q1 and every firm, CORV and FINC included, splits at q2-q4: board
  divergence. The firm effect is pinned by unit tests, not by the arm difference.
