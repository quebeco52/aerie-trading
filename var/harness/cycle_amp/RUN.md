# Cycle amplitude (booms and busts, one arm)

Question family: how large are the District's booms and busts (annual growth sd, negative years, crises, gap split,
unemployment, floor share), and which gap channels carry the gap's quarterly moves?

- Harness: `CycleAmpHarnessTest.php`, a copy of `../cbsmooth/RegimeHarnessTest.php` (live macro, whole board, `POLITICS=1`).
  Its `MACRO_OUT` row adds `pot` (potentialGdpIndex), `bs_i` (balanceSheetIntensity) and the engine's
  `OutputGapProbe` window for the quarter: `gc` (per-channel drift contributions), `gc_diff`, `gc_clamp`, `gc_unexp`.
  The window closes exactly on the quarter's gap change.
- Snapshot: `$SNAP/catree` = `git archive HEAD | tar -x -C $SNAP/catree` + `ln -s <repo>/vendor $SNAP/catree/vendor`.
  Other arms: OVR copies in `$SNAP/ovr<n>` (src-relative paths), arm `R<n>`. Delete `var/harness/cache-catree` after
  changing the snapshot's constructors.
- Run script: `run.sh R1|R<n> <seed>`. Env YEARS (21), TPY (360), POLITICS (1), SNAP, TREE (catree).
  Outputs `runs/<arm>-<seed>.{macro.jsonl,jsonl,log}`.
- Batch: `YEARS=45 SNAP=<dir> bin/harness-batch var/harness/cycle_amp/run.sh 1-16 R1 && SEEDS=1-16 python3 var/harness/cycle_amp/analyse.py R1 > var/harness/cycle_amp/s16.out`
- Analysis: `analyse.py [ARM]`, env SEEDS, BURN (5), YEARS (45). Real output = pot x (1 + gap); annual level = mean of the
  year's 4 quarters; year BURN is the growth base only. Crisis = a new `crisis_at`; post-crisis worst = min growth over
  the crisis year and the 2 after (pooled over crises); quiet worst = min growth in years with no crisis in y-3..y.
  Sahm starts counted on quarterly samples of the engine's indicator (>= 0.005, re-armed below it).
- Arms so far: R1 = HEAD a10a390 as is (16 seeds x 45 y, cached in runs/R1-*). R2 = R1 with
  MacroEngine::FUND_STABILISATION_GAP_RESPONSE = 0.0 (fund countercyclical spending off): `$SNAP/ovr2/Service/Macro/MacroEngine.php`
  (copy of the snapshot file, sed on the constant). Row column `fstab` = sovereignFundStabilisationToGdp (added after R1).
  `YEARS=45 SNAP=<dir> bin/harness-batch var/harness/cycle_amp/run.sh 1-16 R2 && python3 var/harness/cycle_amp/compare.py R1 R2 && SEEDS=1-16 python3 var/harness/cycle_amp/analyse.py R2`
  R3 = R1 with the mainland AR(2) refit on 1985-2019 (AssetMarketSubsystem MAINLAND_GAP_AR1 1.235, AR2 -0.300,
  SIGMA 0.00490): `$SNAP/ovr3/Service/Macro/Subsystem/AssetMarketSubsystem.php` (working-tree copy, 3 constants differ).
  `YEARS=45 SNAP=<dir> bin/harness-batch var/harness/cycle_amp/run.sh 1-16 R3 && python3 var/harness/cycle_amp/pairhp.py R1 R3`
  R4 = R3 + Swiss trade openness (working-tree MacroEngine, AssetMarketSubsystem, MacroAggregateSubsystem,
  MacroTransmission copied to `$SNAP/ovr4`, src-relative; no constructor changes, cache kept).
  `YEARS=45 SNAP=<dir> bin/harness-batch var/harness/cycle_amp/run.sh 1-16 R4 && python3 var/harness/cycle_amp/pairtrade.py R3 R4`
- Trade/mainland comparison: `pairtrade.py A B` = pairhp targets plus beta/corr of gap on fgap, tb mean and clamp share
  (per-arm clamp bounds in its CLAMPS dict), fund/GDP end, corr(nx, gap - nx - supply - fin).
- Snapshot of 10-13 lives in the session scratchpad (`.../scratchpad/snap`); scratchpads vanish, so rebuild it as above
  (git archive a10a390) and delete `var/harness/cache-catree` when the SNAP path changes (the container caches paths).
- HP cycle comparison: `pairhp.py A B` (env SEEDS, BURN 5, YEARS 45, LAM 6.25; uses `hplib.py`): HP cycle sd and min of
  annual real GDP (years BURN..YEARS-1), negative years /40, quarterly gap sd, then guards incl. `fgap` (mainland gap) sd.
- Paired comparison: `compare.py A B` (env SEEDS 1-16, BURN, YEARS): targets growth sd, negative years /40, crisis gap
  fall (gap the quarter before a new crisis_at minus min gap over the next 8 quarters, seed mean; crisis-free seeds drop),
  then guards; B-A with se, t, n.
- Cost: ~6 s per simulated year at TPY 360; 16 x 45 y with 8 slots took ~11 min.

## Household DTI drift (2026-10-13)
- Probe in `CycleAmpHarnessTest.php`: `dtiProbe()` sums each tick's terms of `calculateHouseholdCredit`'s log DTI change
  (d_hp, d_rev, d_dsr, d_co from the previous tick's inputs; d_cap, d_ltv exact from bankCapitalRequiredLast /
  ltvCutBuilt deltas; d_tot actual; cap_up/cap_dn) into each MACRO_OUT row, plus columns dti, ltv_cap, ltv_cut,
  resi_ema, resi_trend, dsr_gap, retail_def, cap_req, ccyb.
- Run script `dti_run.sh DA|DB|DT <seed>` on `$SNAP/catree` (git archive HEAD ac41afe + vendor symlink):
  DA = POLITICS=1, DB = POLITICS=0 (TPY 360), DT = POLITICS=1 at TPY 3600 (the live rate; ~100 s per simulated year).
- `YEARS=20 SNAP=<dir> bin/harness-batch var/harness/cycle_amp/dti_run.sh 1-16 DA DB && SEEDS=1-16 python3 var/harness/cycle_amp/dti.py DA DB`
- Analysis `dti.py A [B]` (env SEEDS, H=15): levels at 5/10/15/20, decomposition 0..H, context, paired A-B at H.
- Outputs: dti_s16.out (DA vs DB, 16 x 20 y), dti_tpy3600_s8.out (DT vs DA, 8 x 15 y).

## Gap persistence and policy-rate lag (2026-10-18, pers_run.sh)
- Harness `PersistenceHarnessTest.php`: a copy of `../FullMarketHarnessTest.php` (current BoardReadingDTO call; the
  older CycleAmpHarnessTest fails at HEAD 51b1d2c2 on the removed `boardBankLevy` argument). Its MACRO_OUT row adds
  `pot deflator target rstar shadow`.
- Snapshot `$SNAP/pstree` = `git archive HEAD | tar -x -C $SNAP/pstree` + `ln -s <repo>/vendor $SNAP/pstree/vendor`.
  Arm A = tree as is; B, C = OVR `$SNAP/ovrB`, `$SNAP/ovrC` holding `Service/Macro/Subsystem/MonetaryPolicySubsystem.php`
  (sed: B CB_SMOOTHING_SPEED 0.65 -> 1.4; C also TAYLOR_OUTPUT_GAP_WEIGHT 1.5 -> 1.0). Cache: `var/harness/cache-pstree`.
- Run: `SNAP=<dir> YEARS=33 bin/harness-batch var/harness/cycle_amp/pers_run.sh 1-16 A B C &&
  SEEDS=1-16 python3 var/harness/cycle_amp/pers_analyse.py A B C > var/harness/cycle_amp/pers_s16.out`
  (~11 min per 24 runs at TPY 360). Outputs `runs/P<arm>-<seed>.*`.
- Analysis `pers_analyse.py A B C` (env SEEDS, BURN 2, YEARS 33) uses `gapstats_lib.py` (the dump analysis' hp/acf/
  spell definitions): gap ACFs and spells, HP(1600) cycle of log(pot x (1+gap)), growth AR1, rate-vs-gap and
  rate-vs-rule lag argmax, 4q moves, guards; paired vs the first arm; arm A spread against the live dump's values.
