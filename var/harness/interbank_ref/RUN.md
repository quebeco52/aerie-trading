# Interbank reference-rate harness

Question family: what pricing floating debt off policy + interbank spread (`FixedIncome::floatingReferenceRate`) does
to lenders' ROE against target, lender profit in funding stress, non-financial interest burden and credit, and the
board. Paired before (a HEAD worktree) vs after (the main working tree), live macro with the Diet.

- Harness: `var/harness/interbank_ref/FullMarketHarnessTest.php`, the shared whole-board run
  (`var/harness/lib/BoardRun.php`, env in `var/harness/lib/README.md`) with hooks. Adds to each quarterly report row: `pti` (pre-tax income), `teq` (tangible
  equity), `broe` (baseline ROE), `ib` (interbank spread EMA), `pol` (policy EMA), `open`
  (`DebtEngine::primaryMarketOpenTo`); `HEALTH=1` adds `icr`, `can`. The record adds `idx` (cap-weighted board return
  per tick), `macro` (tick 1 + one row a quarter), `mcoe0` ([tick-1, default-DTO] market cost of equity) and
  `nonfinite`; a NaN/INF in the record is written with `JSON_PARTIAL_OUTPUT_ON_ERROR` and flagged `json_error`.
- Run script: `var/harness/interbank_ref/run.sh before|after <seed>`. Env YEARS (8), TPY (360), RUNS (output dir).
  `after` = main tree, BASE=main, cache `var/harness/cache-aerie-trading` (delete its `test/` after a constructor
  change). `before` = `BEFORE_TREE` (default `/tmp/claude-1000/ir-before`).
- Build the before tree: `git worktree add --detach /tmp/claude-1000/ir-before HEAD && cp -r vendor
  /tmp/claude-1000/ir-before/`. Remove with `git worktree remove --force /tmp/claude-1000/ir-before`.
- Once: `bin/php-slot php var/harness/interbank_ref/fin_map.php` (writes `runs/fin_map.json`, ticker -> financial).
- Batch: `bin/harness-batch var/harness/interbank_ref/run.sh 1-4 before after &&
  python3 var/harness/interbank_ref/analyse.py 1-4 > var/harness/interbank_ref/<name>.out`
- Analysis: `analyse.py <seeds> [runs_dir] [stress=0.004] [tpy=360]`. Lender ROE = window NI x 4/n over mean
  tangible equity; target = max(opening market cost of equity, baseline ROE) as `resolveCalibrationRoe()`.
  Non-financial = `Sectors::isFinancial` false. Downgrades = notches down between consecutive reports.
- Runtime: ~7 s per simulated year; 4 seeds x 2 arms x 8 years in ~1-2 min wall.
- Pairing: the arms share the macro path at tick 1 but diverge once the board feeds back (see the evidence file).
- Board index: the per-tick return restates a split at constant shares (from 10-24). Index vol, drawdown and return in
  runs made before then counted every 4-for-1 split as a 75% fall; firm-level figures were not affected.
