# Slump diagnostic, round 2 (what is left of the long District slumps after the B + D + E package?)

Round 1 (`../slump_length/`) found the package G = B + D + E; it was committed as 2c3c87c8. Round 2 tests five further
candidates against G, with the mainland's path paired across arms.

- Harness: `SlumpRound2HarnessTest.php`, a copy of `../slump_length/SlumpLengthHarnessTest.php` (live macro, whole
  board, `POLITICS=1`); the MACRO_OUT row records `mgap` (mainlandCycleGap, 0 on the old tree) instead of `dmain`.
- Trees from `setup.sh <SNAP>` (env OLD, default 85b1458c; REV, default HEAD): `r2head` = git archive OLD (arm A, the
  old model), `r2new` = git archive REV + working-tree src (arm G and the candidates). Cache dirs `cache-r2head`,
  `cache-r2new` are deleted on each setup.
- Pairing overlay (every arm, AssetMarketSubsystem): `stepMainlandQuarter()` and `mainlandCrash()` draw from a lazily
  built `MathUtility::ownStream(SEED * 7919 + 1)`, nothing else does. The fgap series then correlates ~0.99+ across
  arms on a seed (not 1: the District gap moves the mainland's mean through the import spillover).
- Overlays (`ovr<arm>` in SNAP, src-relative paths), each = pairing + one edit on r2new:
  - `P1` MAS PHILLIPS_MAX_CAPACITY 0.035 -> 0.060, PHILLIPS_CONVEX_KAPPA 0.0038 -> 0.00651 (slope at zero kept).
  - `P2` MAS PHILLIPS_DOWNWARD_RIGIDITY_FACTOR 0.50 -> 1.00.
  - `H2` AMS RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY 2.5 -> 0.0, RESIDENTIAL_INCOME_ELASTICITY 1.0 -> 2.75.
  - `N1` LaborMarketSubsystem::calculateUnemployment: `$nairuBefore` saved before the scarring step; after the clamp
    `crisisScarPending -= (nairu - nairuBefore) / OKUNS_COEFFICIENT` (potential takes the pending scar unclamped, both
    signs, through absorbProductivityShocks).
  - `S1` MacroEngine STRESS_MULTIPLIER_GAP_SENSITIVITY 10.0 -> 0.0.
  Setup prints a grep count per edit, php -l per overlay and diff line counts.
- Run (TPY 360, YEARS 52, POLITICS 1; ~7 min a run, 8 slots; keep each background call under ~30 min):
  `bash var/harness/slump_round2/setup.sh <SNAP>`
  `SNAP=<SNAP> YEARS=52 bin/harness-batch var/harness/slump_round2/run.sh 1-8 A G P1 P2`
  `SNAP=<SNAP> YEARS=52 bin/harness-batch var/harness/slump_round2/run.sh 1-8 H2 N1 S1`
  `SEEDS=1-8 PERSEED=1 python3 var/harness/slump_round2/analyse.py > var/harness/slump_round2/s8.out`
  Runs are deterministic per seed and tree: a stage-16 extension runs only seeds 9-16 of the arms it needs.
- Analysis `analyse.py [arms]` (env SEEDS 1-8, BURN 2, YEARS 52; PERSEED=1 adds per-seed target tables). Targets:
  share gap < -2%, longest run < -1.5% (q), gap mean. Watch: u - nairu, 4q inflation mean/sd, supercore sd, floor
  share, recession starts/decade, credit crises/century (new lastCreditCrisisAt after BURN), gap acf 13,
  corr(infl, gap 4q earlier), share > +2%, gap sd, fgap mean. Prints the fgap pairing check, per-arm mean [min, max],
  and G - A plus <arm> - G as paired mean (se) t and the mainland-adjusted intercept (paired difference regressed on
  the paired difference of fgap means).
- Evidence: `slump_round2_evidence.md`.

## Mainland-echo check (arm X, 2026-10-19)
Fix under test: `mainlandNextQuarter`'s spillover mean reads `domesticDemandGapEma`, not `outputGapEma` (which holds
half the mainland gap through the regional factor and the mainland-driven exports: a loop gain of ~0.10).
- Arms: G = round 2's G runs (HEAD 2c3c87c8 + pairing overlay, reused, seeds 1-16 exist); X = `r2echo` (git archive
  HEAD + working-tree src) + the same pairing overlay (`ovrX`). Setup greps the overlay and the new spillover line.
  `bash var/harness/slump_round2/setup_echo.sh <SNAP>`
  `SNAP=<SNAP> YEARS=52 bin/harness-batch var/harness/slump_round2/run.sh 1-8 X`
  `SEEDS=1-8 python3 var/harness/slump_round2/echo_analyse.py G X > var/harness/slump_round2/echo_s8.out`
- `echo_analyse.py [A B]`: mainland fgap mean/sd/acf4/acf8, longest and mean same-sign run, share |fgap| > 2%, rf
  mean/max/share > 6%/floor (<= 0.25%), zigzag slump length (falls > 1.2 sd, as ../mainland_crash); District gap
  mean/sd, share < -2%, longest run < -1.5%, corr(gap, fgap), HP(1600) cycle corr (whole sample), 4q inflation
  mean/sd, policy floor share. Flags non-finite values, short runs, logs without OK; prints run times.
- Extend to 16 seeds (`1-16 X`, `SEEDS=1-16`, `echo_s16.out`) if a mainland run-length t sits in 1.5-2.5.
