# Credit-cycle opening harness

Question family: what lenders actually charge against what their franchise pricing assumes (line by line), and what
the opening of the macro's credit indicators (household and corporate default rates, recession forecast, with their
trends) does to lender losses and ROE, the non-financial receivables provision and the board. Paired before (a HEAD
worktree) vs after (the main working tree), live macro with the Diet.

- Harness: `LenderQuarterHarnessTest.php`, a fork of `interbank_ref/FullMarketHarnessTest.php` (env in
  `var/harness/full/RUN.md`). `LQ_OUT` adds, per lender (LAKE RIVR PLVR STRK EGRT BNTH POOL TALN), its calibration at
  seed (`calib`: earning assets, calibration yield, opex ratio, TTC provision rate, interest expense, treasury income,
  ROE target) and every report's realised lines (`rows`: revenue, opex, provision, charge-offs, gross book, allowance,
  interest, PTI, NI, tangible equity) with the credit-cycle macro fields and the model's own readings; per
  non-financial report the receivables provision (`nonfin`: t, ticker, provision, receivables, revenue).
- Run script: `run.sh before|after <seed>`. Env YEARS (8), TPY (360), RUNS (output dir), BEFORE_TREE (default
  `$TMPDIR/co-before`). Writes `runs/<arm>-<seed>.jsonl` and `runs/<arm>-<seed>.lq.json`.
- Build the before tree: `git worktree add --detach $TMPDIR/co-before HEAD && cp -r vendor $TMPDIR/co-before/`
  (copy, not a symlink: a linked vendor loads the main tree's src). Remove with
  `git worktree remove --force $TMPDIR/co-before`.
- Batch: `bin/harness-batch var/harness/credit_open/run.sh 1-8 before after &&
  python3 var/harness/credit_open/analyse.py 1-8 > var/harness/credit_open/<name>.out`
- Analysis: `analyse.py <seeds> [runs_dir] [tpy=360]`. Charge-offs over the TTC charge on the gross book (all
  lenders pooled, by year 1-5, and per lender over years 1-5); ROE = window NI x 4/n over mean tangible equity, over
  `resolveCalibrationRoe()`; receivables provision in bn a year and % of receivables; index drawdown and vol.
- Runtime: ~7 s per simulated year; 8 seeds x 2 arms x 8 years in ~4 min wall on 8 slots.
- Pairing: the arms diverge from the first quarter (the credit indicators feed the household credit channel), so
  seeds do not pair: compare arm means with their se.
