# Output-gap bound harness

How often `MacroAggregateSubsystem::OUTPUT_GAP_FLOOR` / `_CEILING` bind, using the engine's own `OutputGapProbe`.

- Harness: `gap_floor.php <seed> <years> <tpy> <out.jsonl>`: plain script, macro + politics loop (lever_means
  pattern), no equity market cap (equity-wealth loop off), no firms or board. One JSON line per quarter: gap, floor
  clamp (pp held up), ceiling clamp, min/max pre-clamp gap within the quarter, lastCreditCrisisAt, creditCrisisDrag,
  most negative demandDisasterShock state. ~0.8 s per simulated year at 48 tpy.
- Run script: `run.sh cur <seed>` (YEARS default 100, TPY default 48; output `runs/cur_<seed>.jsonl`).
- Batch: `YEARS=200 bin/harness-batch var/harness/gap_floor/run.sh 1-3 cur && python3 var/harness/gap_floor/analyse.py > var/harness/gap_floor/s3_y200.out`
- Analysis: `analyse.py [glob]`: per-seed and pooled binding share, episodes, clamp totals, gap sd/p1/p5/min,
  min pre-clamp gap, and per-episode context (crisis, disaster) for floor episodes.

Evidence: `gap_floor_evidence.md`.
