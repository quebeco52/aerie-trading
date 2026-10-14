# margin_ratchet: removing the plant-modernization margin ratchet

Question: does removing `applyAssetDepreciationDecay`'s stored-margin channel (StandardOperatingPhysicsTrait) stop the
board-wide drift in non-financial operating margins, and does it change firm failures?

Harness: `FullMarketHarnessTest.php`, fork of `earnings_b1/` (live macro, no politics). Adds per-quarter `som`
(stored `Stock::operatingMargin`) and per-firm `om_final`, `ind`, `isfin` in `final`. `bootstrap.php` is the
earnings_b1 bootstrap unchanged (BASE autoloader for snapshot trees; kernel cache in `cache-<snapshot>/`).
YEARS=30, TPY=360, ~2-4 min per run, 8 parallel.

Arms (paired by seed):
- `before` = HEAD 00ead0c snapshot: `git archive HEAD | tar -x -C <snap>`, then `cp -a .env* vendor <snap>/`.
  Default `<snap>` is this session's scratchpad `.../scratchpad/mr-before`; set `BEFORE=<dir>` to point elsewhere.
- `after` = the main checkout working tree (ratchet removed, uncommitted at the time).

Run (H must be absolute; outputs in `$H/runs/<arm>-<seed>.jsonl`):

    H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/margin_ratchet \
      bin/harness-batch var/harness/margin_ratchet/run.sh 1-8 before after && \
      python3 var/harness/margin_ratchet/analyse.py --sectors > var/harness/margin_ratchet/stage8.out

Analysis: `analyse.py [runs dir] [--sectors]`. Targets: (1) ex-fin ex-REIT aggregate EBIT margin (sum EBIT / sum
revenue per year), mean of last 5 years minus years 0-2, pp; (2) median over surviving ex-fin firms of stored
operatingMargin final minus opening, pp; (3) bankruptcies + reorganizations per run. `--sectors` adds a per-industry
table (stored-margin change and own EBIT-margin drift, by arm). Delete `cache*/` after a service/constructor change.

2026-10-14 reuse (sector-drift SAM, HEAD 2f8bc54 vs tree): the harness now also records per-quarter `ipl`
(kpis['industry_price_level']) and final `sh` (shares, for cap shares). `run.sh` defaults BEFORE to that session's
scratchpad `sam-before`; rebuild the snapshot or pass `BEFORE=<dir>`. Analysis: `analyse_sam.py [runs dir]`
(WEAV price level, mean |price level - 1|, EBIT drift, WEAV cap share and margin, per-firm movers, oddities).
`analyse_tier.py [runs dir]` treats failures as unpaired (Fisher exact, rate differences) and tags TIER's
dying-divestiture quarters (wholesale debt down 14% or more, equity down 20% or more, small NI).
`secular_growth.php T1 T2 ...` prints each firm's SAM secular growth factor at years 10/20/30 (no container).

2026-10-14 TIER bisect (why the sector-drift SAM arm killed TIER 22/48 vs 3/48): arms `pen` (penalty + marginal return
only), `led` (EarningsEngine ledger share only), `ma` (M&A divestiture gate only), `plc` (placebo: HEAD with every scale
ratio x (1 + 1e-6), a path perturbation with no economics). Snapshots = `sam-before` plus the one file(s), in that
session's scratchpad. The harness rows now also carry `hy`, `vix`, `erp`, `deal`, `sloos`, `prate` (macro readings
TIER's PE physics takes). `analyse_bisect.py` (TIER deaths and ROE per arm, unpaired), `analyse_macro.py [runs dir]`
(paired macro readings by 2-year bin; `macro16/` holds a 16-seed before/after rerun with those fields).

2026-10-14 WEAV refit + JDAW: arm `jdaw` (= the working tree, kept apart from the cached `after` runs).
`bin/harness-batch var/harness/margin_ratchet/run.sh 1-8 jdaw && python3 var/harness/margin_ratchet/analyse_jdaw.py 1-48 1-8`
(WEAV rev/GDP y1/16/30, cap shares, JDAW size/margin/failures; unpaired vs cached `after`).
