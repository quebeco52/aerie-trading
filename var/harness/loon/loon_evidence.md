# LOON evidence

## 2026-10-14 Telecom device-season rework + LOON Verizon recalibration (base c156377 vs working tree)

Question: does the working tree (device season on equipment only, equipment cost ratio 1.12, analyst
seasonalCostRatioShift, LOON seed om 0.21 / fcr 0.75 / ROIC 0.09 / capex_ratio 0.16 / dep 0.068 / equipment 0.17)
put LOON on Verizon 2024 moments (EBIT 21%, EBITDA 34.5%, capex 13%, D&A 13% of revenue), flip the Q4 margin
season negative, and avoid a recurring Q4 miss / Q1 beat?

Harness: `LoonHarnessTest.php` via `run.sh rec|a|b` (see RUN.md). Macro path recorded on the working tree and
replayed in both arms. Seeds 1-16, 12 years, 360 ticks/yr, POLITICS off. Stage 8 passed targets 1-2 at |t| >= 4;
went to 16 for the surprise contrast (percent EPS surprise blows up on seeds where consensus EPS nears zero, so the
margin-point measure (reps - ceps) x shares / revenue is the one judged). Table: `loon_s16_y12_t360.out`.

| quantity (post year 2) | A base | B tree | B-A (se) | t |
|---|---|---|---|---|
| EBIT margin % | 26.51 | 19.34 | -7.17 (0.64) | -11.2 |
| EBITDA margin % | 47.11 | 32.02 | -15.09 (0.60) | -25.1 |
| Capex / revenue % | 29.32 | 19.23 | -10.09 (0.64) | -15.8 |
| D&A / revenue % | 20.60 | 12.68 | -7.92 (0.09) | -83.5 |
| Q4 - mean(Q1-3) EBIT margin pp | +2.62 (0.25) | -1.26 (0.19) | -3.88 (0.35) | -11.2 |
| EPS surprise, margin pp, Q4 - mean(Q1-3) | +0.12 (0.17) | +0.03 (0.13) | -0.10 (0.23) | -0.4 |
| EPS surprise, margin pp, Q1 - mean(Q2-4) | +0.05 (0.13) | +0.07 (0.07) | +0.02 (0.11) | 0.2 |
| Mkt cap / nominal GDP % (y12) | 1.43 | 0.80 | -0.63 (0.06) | -9.9 |
| Net debt / EBITDA (y12) | 2.44 | 3.29 | +0.85 (0.24) | 3.6 |
| Payout div/NI | 0.78 | 0.76 | -0.03 (0.03) | -0.8 |
| Growth gate open, share of quarters | 1.00 | 0.86 | -0.14 (0.05) | -2.7 |

B EBIT margin by year (16-seed mean): 20.3 20.5 21.2 21.1 20.7 20.5 20.2 19.3 19.3 17.5 16.1 17.6 (A erodes
28.5 -> ~25 on the same paths). B capex/revenue 15.5-17.9% outside the year-5 auction (25.7%).
Deaths 0/16 in both arms; A stays BB throughout; B: seeds 13 and 15 reach B/CCC (net debt/EBITDA up to 5.3, 6.7).

Caveats: replay drops board -> macro feedback. Cap/GDP = price x shares / (12e12 x nominal GDP index). The growth
gate is rebuilt in the harness from the same pure calls (target-metrics ROIC vs applied hurdle), not read from the
engine.

## 2026-10-14 (b) Payout 0.75 -> 0.63, and capex_ratio 0.16 vs 0.05

Question: with target_payout_ratio 0.63 (Verizon 2024), does LOON's debt still drift, and should capex_ratio drop
from 0.16? Arms on the same recorded macro paths: b (previous working tree, payout 0.75; cached), b2 (working tree,
payout 0.63), c (b2 + LOON_CAPEX_RATIO=0.05 set after seeding). a cached. 12 y, 360 tpy.
Staging: 4 seeds b2+c, 8 seeds b2+c (`payout_capex_s8.out`, `payout_capex_s8_vs_b2.out`), b2 to 16 for the
distress tail (`payout_s16_vs_b.out`).

16 seeds, b2 - b: net debt/EBITDA y12 2.79 vs 3.29 (-0.50, se 0.24); gross 3.00 vs 3.52; payout realised
0.59 vs 0.76 (t -5.2); EBIT 20.7 vs 19.3 (+1.3, se 0.7); EBITDA 33.2 vs 32.0; capex/rev 17.5 vs 19.2 (-1.7, se 0.9);
B/CCC seeds 0/16 vs 2/16. Mean ND/EBITDA by year, b2: 2.85 2.91 3.02 2.85 2.87 2.81 2.79 2.97 2.79 2.68 2.76 2.79
(flat; b: 2.90 -> 3.80 at y11; a: 2.17 -> 2.44).

8 seeds, c - b2: capex/rev 16.45 vs 17.19 (-0.74, se 0.78); median-quarter capex/rev 14.6 vs 16.2 (-1.6, se 0.2);
capex/D&A 1.33 vs 1.38; ND/EBITDA 2.91 vs 2.92 (-0.01, se 0.14); revenue y12 $87.9bn vs $93.6bn (-5.7, se 3.5);
PP&E growth last 4y 2.5 vs 3.1 %/yr; EBIT/EBITDA unchanged. Utilisation is not capital-dependent in this engine
(EarningsEngine::capacityUtilizationAt is seasonal x demand shocks); capital shortfall shows as lower revenue.
