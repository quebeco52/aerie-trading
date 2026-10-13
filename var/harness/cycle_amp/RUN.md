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
