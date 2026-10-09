# biotech harness (IBIS, BiotechBusinessModel)

- Harness: `BiotechHarnessTest.php`, a fork of `../FullMarketHarnessTest.php` (whole-ticker replay of the current tree,
  live macro, no replay). Adds an `EarningsReportedEvent` listener that records one row per IBIS report (ctx utilization,
  seasonal factor, actual/expected/consensus revenue, EBIT, eventType, earnings shock, reported and stock operating
  margin, margin ceiling, the biotech `state:*` keys, nominal GDP index, reimbursement shift, reinvestment ratio and
  operating margin before/after `applyAssetDepreciationDecay`), plus IBIS price and a cap-weighted board index ex-IBIS
  every tick.
- `bootstrap.php`: copy of `../bootstrap.php`, so the kernel cache is `biotech/cache/` (fresh, not the stale shared one).
  Delete `cache/` after any constructor/service change in src.
- `ovr/Service/Model/Sector/BiotechBusinessModel.php`: OVR copy of the src model with one instrumentation line in
  `applyAssetDepreciationDecay` (writes `$GLOBALS['IBIS_DECAY']`). Re-copy from src and re-apply if the model changes,
  or the run measures a stale model.
- Run script: `run.sh <seed> [years]` (360 ticks/year, ~6.5 s per sim-year, ~95 MB), output `runs/s<seed>.jsonl`.
- Batch (background, 8 at once, ~7 min for 16 x 30y):
  `seq 1 16 | xargs -P 8 -I{} var/harness/biotech/run.sh {} 30`
- Analysis: `python3 ibis_an.py > ibis_profile.out` (prints the final table only).
- Arms: none yet (descriptive). For an A/B, add a second OVR dir per arm and pass the same seeds.
