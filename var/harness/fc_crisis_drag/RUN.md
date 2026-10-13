# fc_crisis_drag: long-run mean of creditCrisisDrag vs KALDOR_CRISIS_DRAG_COMPENSATOR

- Harness: `var/harness/CreditMacroProbeTest.php` (macro only, container MacroEngine, `updateMacroState($dt, null)`:
  no equity market cap, so the equity-wealth loop is off). Writes quarter rows (now with `drag_avg`, the tick average
  of creditCrisisDrag over the quarter) to `$OUT` and crisis events (impulse, credit gap before/at onset) to `$OUT.crises`.
- Run script: `var/harness/fc_crisis_drag/run.sh <arm> <seed>` (one seed, own php-slot; env YEARS=120, TPY=180).
  Only arm `base` (working tree). Outputs in `runs/` (untracked), logs in `logs/`.
- Batch (36 seeds x 120 y took 39 s wall; 48 seeds ~55 s):
  `bin/harness-batch var/harness/fc_crisis_drag/run.sh 1-48 base && python3 var/harness/fc_crisis_drag/analyse.py > var/harness/fc_crisis_drag/drag_mean_48.out && cat var/harness/fc_crisis_drag/drag_mean_48.out`
- Analysis: `analyse.py [arm] [burn=5]` reads the compensator from MacroAggregateSubsystem.php and prints mean drag,
  net push, t vs the constant, crises/century, impulse per crisis, credit gap at onset, and the renewal check
  rate x impulse / 0.12.
- Results: `fc_crisis_drag_evidence.md`.
