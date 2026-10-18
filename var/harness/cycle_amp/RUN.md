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

## Inventory investment as a level part of GDP (2026-10-18, inv_run.sh)
- Harness `InventoryHarnessTest.php`: `PersistenceHarnessTest.php` plus row columns `inv` (inventoryInvestmentGap, 0 on
  trees without it), `inv_stock`, `inv_ema` (inventoryStockGap and its EMA), `inv_shock` (inventoryCostShock),
  `rgq` (published realGdpQuarterlyGrowth), `rec` (recessionDeclared), `W` (DOMESTIC_GAP_WEIGHT). The final-sales gap
  is computed in the analysis as (gap - supply - nx - fin - inv)/W (the row holds a DTO, not a MacroState).
- Snapshots: `$SNAP/pstree` = git archive HEAD (arm A); `$SNAP/invtree` = git archive HEAD + the working tree's
  changed src files copied over (arms B-E, so the DTO/entity constructor change gets its own cache `cache-invtree`).
  OVR `$SNAP/ovr<arm>/Service/Macro/Subsystem/MacroAggregateSubsystem.php` = working-tree file with sed on
  INVENTORY_COST_SHOCK_SD: B 0.0, C 0.003, D 0.006, E 0.012. Delete `var/harness/cache-invtree` after changing it.
- Run: `SNAP=<dir> YEARS=33 bin/harness-batch var/harness/cycle_amp/inv_run.sh 1-8 A B C D E &&
  SEEDS=1-8 python3 var/harness/cycle_amp/inv_analyse.py A B C D E > var/harness/cycle_amp/inv_s8.out`
  Outputs `runs/I<arm>-<seed>.*`. TPY 360, POLITICS 1.
- Analysis `inv_analyse.py A B ...` (env SEEDS, BURN 2, YEARS 33): growth targets (end-of-quarter and published),
  final sales growth, inventory level/contribution moments, then cycle guards; paired vs the first and the second arm.

## Inventory accelerator, step 2 (2026-10-18, inv2_run.sh)
- Same harness `InventoryHarnessTest.php`. Trees from `inv2_setup.sh <SNAP> <step1 src copies>`: `s1tree` = git
  archive HEAD + the six step-1 files (arm A); `s2tree` = HEAD + working-tree src (B). C = s2tree + `ovrC` (HEAD's
  MonetaryPolicySubsystem, no look-through); D = ovrD (INVENTORY_SURPRISE_SENSITIVITY 0.3); E = ovrE
  (INVENTORY_ADJUSTMENT_SPEED 1.6). Setup deletes `cache-s1tree`/`cache-s2tree`.
- Run: `SNAP=<dir> YEARS=33 bin/harness-batch var/harness/cycle_amp/inv2_run.sh 1-8 A B C D E &&
  SEEDS=1-8 python3 var/harness/cycle_amp/inv2_analyse.py A B C D E > var/harness/cycle_amp/inv2_s8.out`
  (~20 min per 40 runs). Outputs `runs/J<arm>-<seed>.*`. `PFX= ... inv2_analyse.py JA IC` pairs across prefixes.
- `inv2_analyse.py` = inv_analyse plus recession-quarter means (rec flag), OLS slopes of the contribution on same and
  last quarter's final-sales growth, corr(overhang, gap - inv) and the pooled recession share.

## Spending timing, a level part of GDP (2026-10-18, tim_run.sh)
- Harness `TimingHarnessTest.php`: `InventoryHarnessTest.php` plus row columns `tim` (spendingTimingGap) and `tim_stock`
  (spendingPulledForward), 0 on trees without them.
- Trees from `tim_setup.sh <SNAP>` (env REV, default 9344b2de): `t0tree` = git archive REV (arm A); `t1tree` = REV +
  working-tree src (C, SPENDING_TIMING_SHOCK_SD 0.004); `ovrB`/`ovrD` = working-tree MacroAggregateSubsystem with
  0.002 / 0.006. Setup deletes `cache-t0tree`/`cache-t1tree`.
- Run: `SNAP=<dir> YEARS=33 bin/harness-batch var/harness/cycle_amp/tim_run.sh 1-8 A B C D &&
  SEEDS=1-8 python3 var/harness/cycle_amp/tim_analyse.py A B C D > var/harness/cycle_amp/tim_s8.out`
  Outputs `runs/T<arm>-<seed>.*`. TPY 360, POLITICS 1.
- `tim_analyse.py` = inv2_analyse plus underlying final sales (gap - inv - tim) growth, the demand gap's change AR1,
  timing level/contribution moments, recession-quarter timing contribution; paired vs the first arm only.

## Why the gap averages below zero (2026-10-18, gb_run.sh)
- Harness `GapBiasHarnessTest.php` = `InventoryHarnessTest.php` + the `OutputGapProbe` window per quarter (`gc`,
  `gc_diff`, `gc_clamp`) + levers and inflation parts (`nairu`, `infl_ema`, `supercore`, `core_goods`, `net_tax`, `lfg`,
  `mig`, `draw_share`, `fund_draw`, `debt_target`, `primary`, ...) + drag inputs (`ebp`, `ebp_tr`, `cap_req`,
  `cap_built`, `epu_ema`, `cat_ema`, `stance`, `hdsg`, `ccd`; added mid-stage 2, so only some of seeds 9-16 carry them).
- Tree: `$SNAP/gbtree` = `git archive 9344b2de` + vendor symlink. `$SNAP/ovrC/Service/Macro/Subsystem/
  MacroAggregateSubsystem.php` = the tree's file with the convexDemandPressure call replaced by the linear
  (PHILLIPS_CONVEX_KAPPA / PHILLIPS_MAX_CAPACITY) * (outputGap - productivitySupplyGap).
- Arms: A POLITICS=1; B POLITICS=0; C ovrC POLITICS=1; D ovrC POLITICS=0. Outputs `runs/G<arm>-<seed>.*`.
- Run: `SNAP=<dir> YEARS=33 bin/harness-batch var/harness/cycle_amp/gb_run.sh 1-8 A B C D &&
  SEEDS=1-8 python3 var/harness/cycle_amp/gb_analyse.py > var/harness/cycle_amp/gb_s8.out` (~16 min per 32 runs).
- `gb_analyse.py` (env SEEDS, BURN 2, TARGET 0.02): moments, levers, then the mean-gap attribution (domestic channel
  k: 4*mean(gc_k)/1.44, level parts at their mean level; closure rows W*y measured vs predicted). Paired B-A, C-A, D-A, D-C.
- `gb_inputs.py` (env SEEDS 9-16): input mean/sd of each one-sided drag and its rectification part.

## Drag compensators (2026-10-18, dc_run.sh)
- Harness `DragCompHarnessTest.php` = `GapBiasHarnessTest.php` + row columns `tim`, `tim_stock`, `dti`.
- Trees from `dc_setup.sh <SNAP>` (env REV, default HEAD): `dctree` = git archive REV + working-tree src (arm B:
  compensators on, timing SD 0.004); `ovrA` = the working-tree MacroAggregateSubsystem with
  CREDIT_PREMIUM_NOISE_ADVERSE_MEAN, CATASTROPHE_ADVERSE_BURDEN_MEAN, EPU_NOISE_ADVERSE_LOG_MEAN at 0.0 (arm A =
  timing arm C). Setup prints checks and deletes `cache-dctree`.
- Run: `SNAP=<dir> YEARS=33 bin/harness-batch var/harness/cycle_amp/dc_run.sh 1-8 A B &&
  SEEDS=1-8 python3 var/harness/cycle_amp/dc_analyse.py > var/harness/cycle_amp/dc_s8.out &&
  PFX=D SEEDS=1-8 python3 var/harness/cycle_amp/tim_analyse.py A B > var/harness/cycle_amp/dc_shape_s8.out`
  Outputs `runs/D<arm>-<seed>.*`. TPY 360, POLITICS 1; ~16 min per 16 runs on 8 slots.
- `dc_analyse.py` = gb_analyse (levels, levers, mean-gap attribution with `tim` as a level part) plus share of quarters
  with gap < -3% and annual DTI-change AR1; paired B-A. Shape guards (growth skew/AR1, peak-to-trough, HP AR1,
  recessions, crises, drawdown, ELB) from `tim_analyse.py` with `PFX=D`.

## Wage downward rigidity (2026-10-18, wr_run.sh)
- Harness `WageRigidityHarnessTest.php` = `DragCompHarnessTest.php` + row columns `wg` (wageGrowth), `rwg`
  (realWageGap) and, time-weighted over the quarter, `wg_avg`, `wtgt_avg` (the wage-growth target), `wdn_share`
  (share of ticks adjusting downward), `wrf` (the factor). The averages come from static accumulators that exist only
  in the OVR copies of LaborMarketSubsystem, so every arm runs with an OVR.
- Trees from `wr_setup.sh <SNAP>` (env REV, default HEAD): `wrtree` = git archive REV + working-tree src; `ovrA/B/C`
  = working-tree LaborMarketSubsystem + accumulators, WAGE_DOWNWARD_RIGIDITY_FACTOR A 0.30, B 0.60, C 1.00. Setup
  prints checks and deletes `cache-wrtree`.
- Run: `SNAP=<dir> YEARS=33 bin/harness-batch var/harness/cycle_amp/wr_run.sh 1-16 A B C &&
  SEEDS=1-16 python3 var/harness/cycle_amp/wr_analyse.py > var/harness/cycle_amp/wr_s16.out &&
  PFX=W SEEDS=1-16 python3 var/harness/cycle_amp/tim_analyse.py A B C > var/harness/cycle_amp/wr_shape_s16.out &&
  SEEDS=1-16 python3 var/harness/cycle_amp/wr_recovery.py > var/harness/cycle_amp/wr_recovery_s16.out`
  Outputs `runs/W<arm>-<seed>.*`. TPY 360, POLITICS 1; ~12 min per 24 runs on 8 slots.
- `wr_analyse.py` = dc_analyse for arms A B C (pairs B-A, C-A) + gap ex supply, wage growth/target/difference, sds,
  downward share, gap acf8, supercore sd; `SHORT=1` stops after the main table. `wr_recovery.py`: episodes with gap
  < -2% (trough to gap >= 0, 8-quarter rise, policy - (r*+2%), inflation and wages over trough..trough+8).

## Services repricing shock (2026-10-18, svc_run.sh)
- Harness `ServicesHarnessTest.php` = `TimingHarnessTest.php` plus row columns `supercore`, `core_goods`, `wg`
  (wageGrowth), `be`, `be_q` (tipsBreakeven, quoted), `nairu`, `svc_shock` (servicesPriceShock).
- Trees from `svc_setup.sh <SNAP>` (env REV, default HEAD): `svctree` = git archive REV + working-tree src (arm C,
  SERVICES_PRICE_SHOCK_SD 0.005); `ovrA/B/D` = working-tree MacroAggregateSubsystem with 0.0 / 0.004 / 0.006 (the
  draw is taken in every arm, so seeds pair). Setup deletes `cache-svctree`.
- Run: `SNAP=<dir> YEARS=33 bin/harness-batch var/harness/cycle_amp/svc_run.sh 1-4 A B C D &&
  SEEDS=1-4 python3 var/harness/cycle_amp/svc_analyse.py A B C D > var/harness/cycle_amp/svc_s4.out`
  Stage 2: `... svc_run.sh 5-16 A C D && SEEDS=1-16 PAIRS=C:D python3 ... svc_analyse.py A C D > ...svc_s16.out` (~19 min).
  Outputs `runs/S<arm>-<seed>.*`. TPY 360, POLITICS 1.
- `svc_analyse.py` (env SEEDS, BURN 2): services/core goods/headline 4q-average moments (sd, ac4, ac1, corr with the
  gap 4 quarters earlier), shock sd, policy (sd, floor share, largest 4q cut/hike), gap, u - NAIRU, wages, breakeven
  sd, clamp share; paired vs the first arm; env PAIRS=X:Y adds Y-X for services sd and ac4.
