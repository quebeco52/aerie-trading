# Slump length (which mechanism makes District slumps long and deep?)

Question family: the live game shows the District gap below -2% in ~32% of quarters (US 1985-2019: 17%), longest
run below -1.5% of 29 quarters, policy at the floor 12%. Which candidate mechanism drives that?

- Harness: `SlumpLengthHarnessTest.php`, a copy of `../mainland_crash/MainlandCrashHarnessTest.php` (live macro, whole
  board, `POLITICS=1`), same `MACRO_OUT` row (gap, fgap, policy, infl, supercore, rgq, rec, pot, ...).
- Tree from `setup.sh <SNAP>` (env REV, default HEAD): `sltree` = git archive REV + working-tree src (arm A). Overrides
  mirror src/ (MAS = Macro/Subsystem/MacroAggregateSubsystem.php, MPS = MonetaryPolicySubsystem.php):
  - `ovrB` MAS: KALDOR_MONETARY_DRAG_ACCOMMODATIVE 0.5505 -> 1.2505 (easing as strong as tightening).
  - `ovrC` MPS: cyclicalGapForecast always takes the cold-start branch (today's gap, no EMA, no projection).
  - `ovrD` MPS: cyclicalGapForecast subtracts max(0, productivitySupplyGap) (diagnostics left alone: no sum assert).
  - `ovrE` MAS: mainland impulse to demandShock off; 0.5 x (fgap - fgapLag) added to the netExports contribution at
    each quarter's turn; domesticDemandGap reads outputGap - 0.5 fgap.
  - `ovrF` MAS (B+E) and MPS (C+D).
  - `ovrH` = B + D (MAS B, MPS D); `ovrG` = B + D + E (MAS B+E, MPS D). Setup asserts both by grep count.
  Setup prints a grep count for every edit, php -l each override, and deletes `var/harness/cache-sltree`.
- Run (TPY 360, POLITICS 1, ~7-8 min per 52-year run on a free slot; 48 runs ~50 min on 8 slots):
  `bash var/harness/slump_length/setup.sh <SNAP> && SNAP=<SNAP> YEARS=52 bin/harness-batch var/harness/slump_length/run.sh 1-8 A B C D E F &&
  SEEDS=1-8 python3 var/harness/slump_length/analyse.py > var/harness/slump_length/s8.out`
  Outputs `runs/<arm>-<seed>.*`. SNAP can live in the session scratchpad.
- Stage 16 for H/G (s16.out): `SNAP=<SNAP> YEARS=52 bin/harness-batch var/harness/slump_length/run.sh 1-16 H G &&
  SNAP=<SNAP> YEARS=52 bin/harness-batch var/harness/slump_length/run.sh 9-16 A` then
  `SEEDS=1-5,7-14,16 PERSEED=1 python3 var/harness/slump_length/analyse.py A H G` (H-6, H-15 crash on a NaN, see evidence).
- Stage 16 for E (s16e.out): `SNAP=<SNAP> YEARS=52 bin/harness-batch var/harness/slump_length/run.sh 9-16 E` then
  `SEEDS=1-16 PERSEED=1 python3 var/harness/slump_length/analyse.py A E G`. Runs are deterministic per seed and tree
  (E-1 rerun on the rebuilt tree was byte-identical), so earlier stages can be reused if setup inputs are unchanged.
- `dbg.sh <seed> <ovrdir>`: one run with a debug overlay (scratch `ovrHdbg` = ovrH + NaN dumps), output runs/dbg-<seed>.*.
- Analysis `analyse.py [arms]` (env SEEDS 1-8, BURN 2, YEARS 52 for the completeness check): gap mean/sd, shares
  < -2%, > +2%, < -1 sd; runs below -1.5% (longest, >= 8q per century, mean length of >= 4q); gap acf 4/13; 4q-avg
  inflation and supercore mean/sd; policy floor share (<= 0.25%) and sd; HP(1600, whole sample) cycle corr of
  log(pot(1+gap)) with fgap; corr(rgq, d fgap); corr(d gap, d fgap) (E check); worst rgq, rgq < -1% per century,
  recession starts per decade. Per arm mean [min, max], paired diff vs A (se, t), F minus the best single arm; G minus H and G minus E when both are given; env PERSEED=1 adds per-seed tables (share < -2%,
  longest run < -1.5%, gap mean) for A, E, H, G. Truncated runs are flagged but still counted: drop their seeds from SEEDS.
- Evidence: `slump_length_evidence.md`.
