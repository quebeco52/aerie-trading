# District-mainland co-movement (common demand factor)

Question family: how closely does the District's cycle follow the mainland's (level and HP-cycle correlation, lead-lag),
and what does a shared demand factor cost in gap amplitude, recessions, policy-rate floor time and FX?

- Harness: `ComoveHarnessTest.php`, a copy of `../cycle_amp/ServicesHarnessTest.php` (live macro, whole board,
  `POLITICS=1`). Its `MACRO_OUT` row adds `rf` (foreignPolicyRate), `dis` (demandDisasterShock), `dis_n` (cumulative
  disaster arrivals: ticks where demandDisasterShock moves by > 1e-3, far above its per-tick decay), `dshock`
  (demandShock), `dmain` (demandMainlandShock, 0 on trees without it), `fund_draw` (sovereignFundDrawToGdp). Already in
  the row: gap, fgap, policy, fx, nx, supply, fund, rec, rgq, pot.
- Trees from `setup.sh <SNAP>` (env REV, default HEAD): `cotree0` = git archive REV (arm A); `cotree1` = REV +
  working-tree src (arm D: DEMAND_SHOCK_MAINLAND_LOADING 0.95, DEMAND_SHOCK_SIGMA 0.025, DEMAND_DISASTER_INTENSITY 0.04).
  OVR copies of MacroAggregateSubsystem: `ovrB` loading 0.80, `ovrC` 0.90, `ovrE` = D with
  TFP_POTENTIAL_ABSORPTION_SPEED 0.75 -> 1.0. Setup prints checks and deletes `cache-cotree0`/`cache-cotree1`.
  No new draws in the working tree, so seeds pair across all arms.
- Run (TPY 360, POLITICS 1; 40 runs x 100 y took 64 min on 8 slots):
  `bash var/harness/comove/setup.sh <SNAP> && YEARS=100 SNAP=<SNAP> bin/harness-batch var/harness/comove/run.sh 1-8 A B C D E &&
  SEEDS=1-8 python3 var/harness/comove/analyse.py A B C D E > var/harness/comove/s8.out`
  Outputs `runs/<arm>-<seed>.*`.
- Analysis `analyse.py A B ...` (env SEEDS 1-8, BURN 5, WIN 120 quarters, PAIRS X:Y,...): per arm mean [min, max] over
  seeds of corr(gap, fgap), lead-lag corr(g_t, f_{t+k}) k = -4..4 and its argmax, HP(1600) cycle of log(pot x (1+gap))
  vs the HP cycle of fgap (`hp_mean`, `hp_sd` across non-overlapping 30-year windows; `hpraw_mean` vs fgap itself), gap
  moments, quarterly real growth AR1/sd, recession starts and disaster arrivals per decade, policy sd/floor share
  (<= 0.25%)/corr with the mainland rate, sd log FX, nx share cov(nx,gap)/var(gap), fgap sd, fund/GDP min and mean,
  mean fund draw when gap < -2%. Paired table vs the first arm and E-D.
