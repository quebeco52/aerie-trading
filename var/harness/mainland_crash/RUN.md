# Mainland crash (compensated Merton jump in the mainland gap)

Question family: does a rare shared mainland slump give the District a left tail in growth and more co-movement, and
does it move the cycle guards (gap mean/sd, recessions per decade, policy floor share)?

- Harness: `MainlandCrashHarnessTest.php`, a copy of `../comove/ComoveHarnessTest.php` (live macro, whole board,
  `POLITICS=1`); same `MACRO_OUT` row (gap, fgap, rf, policy, pot, rec, rgq, dis_n, ...).
- Tree from `setup.sh <SNAP>` (env REV, default HEAD): `mctree` = git archive REV + working-tree src (arm B: crash
  0.025/yr, mean 0.023, log sd 0.30, MAINLAND_GAP_SIGMA 0.00452). `ovrA` = working-tree AssetMarketSubsystem with
  MAINLAND_CRASH_INTENSITY 0.0 and MAINLAND_GAP_SIGMA 0.00490 (arm A: HEAD's mainland on B's random stream; the crash
  draws are made every quarter in both arms, so seeds pair). Setup deletes `var/harness/cache-mctree`.
- Run (TPY 360, POLITICS 1, ~14 min per 100-year run on a free slot):
  `bash var/harness/mainland_crash/setup.sh <SNAP> && YEARS=100 SNAP=<SNAP> bin/harness-batch var/harness/mainland_crash/run.sh 1-8 A B &&
  SEEDS=1-8 python3 var/harness/mainland_crash/analyse.py A B > var/harness/mainland_crash/s8.out`
  Outputs `runs/<arm>-<seed>.*`. SNAP can live in the session scratchpad.
- Analysis `analyse.py A B` (env SEEDS, BURN 5, WIN 120): per arm mean [min, max] over seeds and paired B-A (se, t) of
  quarterly growth `rgq` tail (skew, excess kurtosis, worst, share < -1%, count < -2% per century, worst 4-quarter),
  gap moments and share < -2%, recession starts per decade, policy floor share and sd, zigzag slumps (falls > 1.2 sd:
  quarters and sd/quarter) for gap and fgap, mainland fgap sd and quarterly-change skew/kurtosis/min, mainland crashes
  (fgap falls > 1.5pp in a quarter) per century, Fed floor share and mean, corr(gap, fgap) at 0 and peak over +-4
  (peak_k < 0: mainland leads), HP(1600) cycle corr of log(pot x (1+gap)) with fgap's, corr(rgq, d fgap) at lags
  0/-1/-2 (mainland ahead), disaster arrivals per decade.
- Evidence: `mainland_crash_evidence.md`.
