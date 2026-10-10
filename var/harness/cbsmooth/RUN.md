# Policy-rate smoothing speed (CB_SMOOTHING_SPEED)

Question family: does the partial-adjustment speed of the policy rate set the length and persistence of the cycle?

- Harness: `var/harness/cbsmooth/CbSmoothHarnessTest.php`, a copy of `var/harness/FullMarketHarnessTest.php` (live macro,
  whole board, `POLITICS=1`) whose `MACRO_OUT` row adds `y10` (`MacroState::yield10y`).
- Isolation: every arm runs on a frozen snapshot tree `$SNAP/cbtree` (`git archive HEAD` + rsync of the working tree's
  src/ config/ tests/ bin/ .env, plus a `vendor` symlink to the main checkout), passed as `BASE`. Arm differences are
  override copies of `Service/Macro/Subsystem/MonetaryPolicySubsystem.php` in `$SNAP/ovrB`, `$SNAP/ovrC`, passed as `OVR`
  (see `var/harness/bootstrap.php`). The snapshot lives in a session scratchpad; rebuild it for a new session:

      git archive HEAD | tar -x -C <SNAP>/cbtree; rsync -a src config tests bin .env <SNAP>/cbtree/
      ln -s <repo>/vendor <SNAP>/cbtree/vendor
      sed 's/CB_SMOOTHING_SPEED = 4.0;/CB_SMOOTHING_SPEED = 0.65;/' <SNAP>/cbtree/src/<F> > <SNAP>/ovrB/<F>

  then run with `SNAP=<SNAP>`. Delete `var/harness/cache-cbtree` after changing the snapshot's constructors.
- Run script: `var/harness/cbsmooth/run.sh A|B|C <seed>` (A = 4.0, B = 0.65, C = 1.4). Env YEARS (21), TPY (360),
  POLITICS (1). Outputs `runs/<arm>-<seed>.{macro.jsonl,jsonl,log}`.
- Batch: `bin/harness-batch var/harness/cbsmooth/run.sh 1-8 A B C && python3 var/harness/cbsmooth/analyse.py`
- Analysis: `analyse.py` drops year 1, computes per-seed quarterly ACF(4) of gap, policy rate and 10Y, plus guards
  (gap sd, inflation mean/sd, unemployment sd, ELB share), paired against arm A.
- Cost: about 2 min per 21-year run at 360 ticks/year with the Diet; 8 runs at once.

## Fund debt effect (macro only, SovereignFundDynamicsTest)
- Harness: `fund_debt.php <seed> <fund 0|1> <out.json>`: the test's `simulate()` (80 y, 24 ticks/yr, synthetic board,
  no politics) plus a per-tick decomposition of gross debt/GDP into public-accounts lines and late-window flow means.
- Run script: `fund_run.sh A1|A0|B1|B0 <seed>` (A = 4.0, B = 0.65 via `$SNAP/ovrB`; 1 = fund, 0 = none), same snapshot.
- Batch: `bin/harness-batch var/harness/cbsmooth/fund_run.sh 1-48 A1 A0 B1 B0 && python3 var/harness/cbsmooth/fund_analyse.py 1-48`
  (~0.7 s a run, 192 runs in ~30 s). Outputs `runs/fund-<arm>-<seed>.json`, table in `fund_stage48.out`.
