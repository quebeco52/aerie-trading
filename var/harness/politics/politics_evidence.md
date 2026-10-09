# Politics gate evidence

## 2026-10-09 Diet public-spending lever (publicSpendingShare) vs HEAD 8a6a862

Question: what the new `publicSpending` lever (purchases revert to 0.21 x exp(0.181 x state / 1.3) of GDP; Council brake
also holds spending rises above 90% debt; demand and fiscal curvature read purchases against a 10-year trend) does to
partisan purchases, the output gap, debt and the brake.
Harness: `gate.php` (working tree) vs `head_tree` worktree of 8a6a862 with its own vendor and bootstrap, via `spend.sh`, arms
newon/newoff/headon/headoff, paired seeds. 100 years, TPY 180, 10-year burn-in, no fund, no equity cap (equity-wealth loop off).
Stages: 8 seeds, then 16 (stopped at 16 as asked; brake rounds was not settled).

Paired NEW - HEAD, 16 seeds (`spend_16.out`):

| arm | quantity | HEAD | NEW | diff | se | t |
|---|---|---|---|---|---|---|
| on | gap sd | 0.0210 | 0.0212 | +0.0002 | 0.0003 | +0.8 |
| on | gap ACF4 | 0.7201 | 0.7243 | +0.0041 | 0.0070 | +0.6 |
| on | debt mean | 0.9144 | 0.8547 | -0.0597 | 0.0114 | -5.2 |
| on | brake rounds/yr | 0.435 | 0.260 | -0.174 | 0.071 | -2.4 |
| on | debt > 0.90 share | 0.567 | 0.250 | -0.317 | 0.059 | -5.4 |
| on | tax shift sd | 0.0271 | 0.0263 | -0.0007 | 0.0007 | -1.0 |
| off | gap sd | 0.0194 | 0.0195 | +0.0002 | 0.0002 | +0.9 |
| off | gap ACF4 | 0.6986 | 0.7009 | +0.0024 | 0.0043 | +0.6 |
| off | debt mean | 0.9531 | 0.9521 | -0.0010 | 0.0011 | -0.9 |

NEW-on (`spend_16_new.out`): spending share mean 0.2040 sd 0.0148 min 0.1918 max 0.2299; purchases log sd 0.0512 (se 0.0025)
on vs 0.0188 (se 0.0007) off; partisan purchases long run per Diet unit +0.115 pooled (rho 0.816), +0.112 across seeds
(se 0.005) vs real 0.044 (se 0.072) and 0.110 pre-1990; tax shift sd 0.0290; debt above 90% in 25% of quarters.

Caveats: brake rounds counted as distinct `lastCouncilBrakeAt` values on quarterly rows. Gap moments are negligible on
both arms (2-se bands within +-0.0008 sd, +-0.015 ACF4). The trend-read change alone (off arms) moves nothing measurable.

## 2026-10-09 Consolidation mix (tax 37.7% / purchases 62.3%) + Council median debt-brake line vs HEAD 5e4ea84

Question: does splitting the Bohn surplus between tax and purchases (Diet lever `consolidationTaxShare`) and drawing the
Council's brake line as the median of per-councillor lines move debt, brake rounds or the gap? Harness `gate.php` via
`spend.sh` (RUN.md "Working tree vs a baseline commit"), arms newon/newoff/headon/headoff, seeds 1-16, 100 y, 180 tpy,
burn 10 y. `spend_an.py` + `mix_an.py` (new). Stage 8: debt t -4.1, brakes t +3.2, gap negligible -> ran 16; stopped.
Tables: `mix_8.out`, `mix_16.out`. Targets named before the run (on arm, NEW - HEAD, n 16):

| target | HEAD | NEW | diff (se) | t |
|---|---|---|---|---|
| T1 debt mean | 0.8556 | 0.8501 | -0.0055 (0.0018) | -3.0 |
| T2 brake rounds/yr | 0.2715 | 0.3312 | +0.0597 (0.0112) | +5.3 |
| T3 gap sd | 0.0214 | 0.0212 | -0.0002 (0.0003) | -0.5 |
| off: debt mean | 0.9521 | 0.9515 | -0.0006 (0.0004) | -1.7 |
| off: gap sd | 0.0195 | 0.0197 | +0.0002 (0.0001) | +1.9 |

New tree, on arm: Council line share of quarters 0.66 1.9%, 0.85 7.2%, 0.865 22.6%, 0.87 27.3%, 0.90 38.9%, 0.95 2.0%;
mean 0.877 (se 0.003, seed range 0.856-0.900). Purchases share 0.2032 (se 0.0011). cts 0.355 (se 0.004, quarter sd 0.055).
Debt above 90%: 25.7% -> 21.7% of quarters (t -4.1); off arm 68.5% -> 66.7% (t -5.8).

Caveats: T1 moved -0.55pp with t -3.0 at 16 (2-se band -0.2 to -0.9pp, small next to 0.86); not taken to 48 per the brief.
The 8-seed stage had gap sd +0.0003 (t +2.0), which flipped to -0.0002 at 16: noise. A brake line of 0.66 occurs (1.9%).
