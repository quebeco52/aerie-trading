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

## Long end and breakeven (y10_run.sh)
Question: what makes the ten-year's quarterly moves, and does a learned inflation endpoint (Kozicki-Tinsley, Orphanides-
Williams gain) give the ten-year breakeven US-like variance without moving the cycle?
- Harness: the same `CbSmoothHarnessTest.php`; its `MACRO_OUT` row now always carries `tp10 rn10 be10 ig rstar target`, and
  `DECOMP=1` adds the curve's pieces (`level b1 b2 b3 base_tp tp_shock tp_regime ftp_* sov_ema pnr path ...`).
- Snapshot: `$SNAP/y10tree` = `git archive HEAD` + `rsync -rlpt src config tests bin` + `vendor` symlink (no .env copy;
  the archived one serves). `rsync -a` fails on chgrp in the sandbox. Arm Y1 = OVR copies of the changed src files in
  `$SNAP/ovr1` (src-relative paths). Delete `var/harness/cache-y10tree` after changing the snapshot's constructors.
- Run: `SNAP=<dir> bin/harness-batch var/harness/cbsmooth/y10_run.sh 1-8 Y0 Y1` (~100 s for 8 runs at TPY 360).
- Analysis: `python3 var/harness/cbsmooth/y10_analyse.py Y0 Y1` (targets and guards, paired) and
  `python3 var/harness/cbsmooth/y10_decomp.py <arm> 1,2,3,4` (the ten-year's pieces: sd, ACF4, share of var and of
  var of the 4-quarter change; closes to 1e-5).
- Pairing: the board's draws depend on state, so arms on one seed decouple within a few quarters (the term premium's OU
  path differs by quarter 10); the paired SE is close to the unpaired one.
- Tick rate: `y10_macro.php <seed> <years> <tpy> <out>` runs the macro loop alone (no board, no fund) with politics, for
  a 360 vs 36000 ticks/yr check (36000 x 20 y is ~12 min a run).
- Macro-only paired arms: `SNAP=<dir> bin/harness-batch var/harness/cbsmooth/y10_macro_run.sh 1-16 MY0 MY1 &&
  python3 var/harness/cbsmooth/y10_analyse.py MY0 MY1` (~460 s for 32 runs; deterministic, paired SE ~10x smaller).
- Clear stale `runs/Y*-*` before a staged run: the analysis globs every seed file of an arm.

## Recession-regime cut speed (crisis_run.sh)
Question: does cutting at the recession regime's speed (CB_RECESSION_CUT_SPEED, Aastveit et al. 2024) restore US-sized
easing in recessions without moving the cycle's persistence?
- Harness: the same `CbSmoothHarnessTest.php`; its `MACRO_OUT` row now carries `sahm ebp u_ema nairu stress` and per-tick
  shares since the last row: `elb_t evans_t regime_t fastcut_t switches` (the stress latch is recomputed in the harness,
  so the baseline tree reports the same regime).
- Snapshot: `$SNAP/crtree` = `git archive HEAD` + `rsync -rlpt src config tests bin` of the working tree WITHOUT the change
  + `vendor` symlink. Arm K1 = OVR `$SNAP/ovrK`: `Service/Macro/Subsystem/MonetaryPolicySubsystem.php`,
  `Service/Macro/MacroEngine.php`, `Service/Macro/MacroState.php`, `DTO/MacroStateDTO.php` (src-relative).
- Run: `SNAP=<dir> bin/harness-batch var/harness/cbsmooth/crisis_run.sh 1-8 K0 K1` (~130 s for 8 runs at TPY 360).
- Analysis: `python3 var/harness/cbsmooth/crisis_analyse.py K0 K1`. Targets: largest 4-quarter cut in seeds with a
  recession (`cut4q_rec%`), share of quarters at the floor (`elb_share`), first recession's trough gap and quarters back
  to zero (`trough_gap%`, `rec_q`). Guards: pol/gap ACF4, gap sd, inflation mean/sd, 10Y mean, crisis incidence, blow-ups.
- Clear stale `runs/K*-*` before a staged run. `SEEDS=1-16 python3 ... crisis_analyse.py K0 K1` reports one stage;
  `gap_semidev.py K0 K1` splits a gap-sd shift into downside/upside.
