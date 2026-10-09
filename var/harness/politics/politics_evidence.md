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
