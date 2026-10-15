# Rework evidence

## Baseline (02e4b43), 16 seeds × 100y, TPY 48
Cached in runs/old_*.jsonl, runs/oldfund_*.jsonl. No-fund: gap sd 2.32, unemp sd 1.62, infl 2.08 ± 1.08 (sd), rate dq sd
35bp, debt mean 83%, Sahm-recession share 22.5%, crises 4.7/100y. Fund: gap sd 1.98, debt mean 64%.

## Phase 1 (defects + freeze), 4 seeds, paired vs baseline seeds 1-4
Freeze: seed 7, 50y, gap path matches old to 5e-6 until the first hashed rescue draw (bankRescueShock keys on
creditToGdpGapEma to 12 decimals), after which paths decorrelate; no systematic change.
No moment moves beyond seed noise except M2 mean 4.09 → 4.55% (t 3.3; now nominal income + credit + purchases) and
EBP mean 13.8 → 8.6bp (t −3.0, n=4). No explosions (max |gap| 6.8%).

## Phase 2 (fiscal), 4 seeds, paired vs baseline seeds 1-4
No fund / fund: debt mean 80 → 44% (t −4.8) / 61 → 50% (t −10.7); debt max 112 → 60% / 91 → 68%; debt sits
−6.1 / −0.4pp from the cabinet's target (old engine +23 / +5 from its debt target). Sovereign spread 0.4 / 0.7bp mean.
Recession share (old: Sahm proxy) 23.5 → 7.8% (no fund), 21.0 → 9.2% (fund); 10 / 9 recessions per century.
Gap sd 2.00 → 2.18 (t 0.9) / 2.04 → 2.39 (t 2.2: fund stabilisation gone, stabiliser 0.35 → 0.113). Inflation mean
+0.08 / +0.07pp. No explosions (max |gap| 7.3%).
