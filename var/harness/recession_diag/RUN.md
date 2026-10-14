# Recession-regime diagnosis (recession_diag)

Question: why is the recession regime (Sahm >= 0.5pp OR the Authority's EBP stress latch) on ~30-35% of the time vs US
~0.20? Splits regime time by leg, measures real (technical) recessions on exact real GDP, the unemployment noise
signature, Sahm false trips, offline labour-filter counterfactuals, and EBP latch moments, each beside its US value.

- Harness: `RecessionDiagHarnessTest.php` (fork of `cbsmooth/RegimeHarnessTest.php`: live macro, whole board, politics).
  Adds `TICK_OUT`: one CSV row a tick `tick,gap,nairu,u,u_ema,sahm,ebp,latch,potentialGdpIndex`. Quarter rows in
  `MACRO_OUT` as before (CPI-deflated nominal GDP comes from these).
- Run script: `run.sh <arm> <seed>` (arm D1 = the snapshot tree as is). Needs `TREE=<snapshot>`.
- Snapshot: `git archive HEAD | tar -x -C <tree>`, then `rsync -rlpt src config tests bin <tree>/` from the working tree,
  then `cp -a vendor <tree>/` (COPY, not symlink: a symlinked vendor runs the main checkout's src). run.sh runs the
  tree's own `vendor/bin/phpunit` (the main one's autoloader clashes with the tree's).
- Batch (16 seeds x 21 years, TPY 360, ~6 min with 8 slots):
  `TREE=<tree> bin/harness-batch var/harness/recession_diag/run.sh 1-16 D1 && python3 var/harness/recession_diag/analyse.py D1 > var/harness/recession_diag/recession_diag_s16.out`
- Analysis: `analyse.py [arm]`. Year 1 dropped. US data: `us/UNRATE.csv`, `us/SAHMREALTIME.csv`, `us/USREC.csv`
  (FRED fredgraph.csv, not tracked: re-download if missing), `../us_gdpc1.csv`, `../us_gdppot.csv`, `../ebp.csv` (GZ).
  Sections: A shares and episodes; B technical recessions (exact real GDP = potential x (1 + gap), quarterly averages;
  and the old CPI-deflated measure), growth moments; C unemployment Δ1/Δ12 sd; D Sahm false trips (no technical
  recession start within ±4 quarters); E offline labour-filter counterfactuals (E0 reproduces the engine: check
  `E0_maxdu_bp`); F EBP moments, latch entries, latch entries with no Sahm or technical recession within ±2 quarters.
- Counterfactuals in E hold the recorded gap path fixed (no feedback from u to the gap); NAIRU is re-simulated.

## Gap decomposition (gap_decomp, 2026-10-14)
- `GAP_OUT` (set by run.sh) turns on the engine's own `OutputGapProbe` and rolls its window every tick:
  `runs/<arm>-<seed>.gap.jsonl`, one JSON line a tick (channel rate*dt, diffusion, clamp, unexplained, external).
- Batch (8 seeds x 21 years, TPY 360, ~2 min): `TREE=<tree> bin/harness-batch var/harness/recession_diag/run.sh 1-8 G1 &&
  python3 var/harness/recession_diag/gap_decomp.py G1 > var/harness/recession_diag/gap_decomp_s8.out`
- `gap_decomp.py` is pure Python (no numpy on this machine). Quarterly-average Delta gap splits exactly into channel
  pieces (difference of quarterly means of each channel's cumulative sum); prints cov shares and ACF1 parts.

## Paired arms (trend_latch, 2026-10-14)
- `compare.py <armA> <armB> [a-b]`: paired table (mean, se, diff, se, t) of analyse.py moments plus guards from the
  quarter rows (inflation, policy, 10Y, u, gap sd, debt at end, deaths from the log, median P/E and board cap/NI).
- Quarter rows now carry `pe_med`, `board_pe`, `neg_eps`, `live` (peProbe); D1 rows predate it, so D1 has no P/E.
- D2 = working tree (trend growth 2.54% + month-end EBP latch), tree in a scratchpad snapshot (rebuild as above):
  `TREE=<tree> bin/harness-batch var/harness/recession_diag/run.sh 1-16 D2 && python3 var/harness/recession_diag/compare.py D1 D2 1-16 > var/harness/recession_diag/trend_latch_s16.out`
- latch_dur_m is a per-seed mean that counts seeds with no latch entry as 0; use pooled months/episodes when entries are few.

## Debt-target pacing (pace, 2026-10-14)
- Quarter rows carry `primary`, `net_tax`, `debt_target`, `anchor` (debtAnchorInForce), `spend_idx`, `spend_trend`.
- `run_pace.sh <arm> <seed>`: P1 = snapshot as is; P0 = `OVR=ovr_pace0` (CreditFiscalSubsystem copy, CONSOLIDATION_PACE 10.0).
  run.sh now passes `OVR` through (was forced empty).
- `TREE=<tree> bin/harness-batch var/harness/recession_diag/run_pace.sh 1-16 P1 P0 && python3 var/harness/recession_diag/pace.py P0 P1 1-16 > var/harness/recession_diag/pace_s16.out`
  (~4.5 min per 8 seeds x 2 arms). `pace.py` prints targets, guards, target jumps >= 10pp and where target paths diverge.
