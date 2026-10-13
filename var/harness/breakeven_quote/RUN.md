# Breakeven quote harness

Quoted breakeven (`tipsBreakevenQuoted` = expectation + EBP loading) against the expectation leg (`tipsBreakeven`).

- Harness: `breakeven_quote.php <seed> <years> <tpy> <out.jsonl>`: plain script, macro + politics loop (copied from
  `gap_floor/`), no equity market cap (equity-wealth loop off), no firms. One JSON line per quarter: quarterly
  averages of tipsBreakeven, tipsBreakevenQuoted, excessBondPremium, highYieldCreditSpread, macroCreditSpread,
  energyPriceIndex, outputGap. ~0.7 s per simulated year at 48 tpy.
- Run script: `run.sh cur <seed>` (YEARS default 40, TPY default 48; output `runs/cur_<seed>.jsonl`).
- Batch: `bin/harness-batch var/harness/breakeven_quote/run.sh 1-3 cur && python3 var/harness/breakeven_quote/analyse.py > var/harness/breakeven_quote/s3_y40.out`
- Analysis: `analyse.py [glob]`: per seed and pooled (mean of seeds; min/max over seeds), for quote and expectation:
  sd of level and of quarterly change (pp), min/max, change correlations with HY spread, IG (macroCreditSpread) and
  log energy; mean quote minus expectation; EBP mean/sd. First 2 years dropped as burn-in.

Evidence: `breakeven_quote_evidence.md`.
