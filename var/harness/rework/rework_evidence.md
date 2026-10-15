# Rework evidence

## Baseline (02e4b43), 16 seeds × 100y, TPY 48
Cached in runs/old_*.jsonl, runs/oldfund_*.jsonl. No-fund: gap sd 2.32, unemp sd 1.62, infl 2.08 ± 1.08 (sd), rate dq sd
35bp, debt mean 83%, Sahm-recession share 22.5%, crises 4.7/100y. Fund: gap sd 1.98, debt mean 64%.

## Phase 1 (defects + freeze), 4 seeds, paired vs baseline seeds 1-4
Freeze: seed 7, 50y, gap path matches old to 5e-6 until the first hashed rescue draw (bankRescueShock keys on
creditToGdpGapEma to 12 decimals), after which paths decorrelate; no systematic change.
No moment moves beyond seed noise except M2 mean 4.09 → 4.55% (t 3.3; now nominal income + credit + purchases) and
EBP mean 13.8 → 8.6bp (t −3.0, n=4). No explosions (max |gap| 6.8%).
