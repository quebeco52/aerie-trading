# Broadcaster (BELL) evidence

## 2026-10-25: BELL behaviour check, live macro (arm `live`, seeds 1-4, 20y, TPY 360, no Diet)

Question: does BroadcastingBusinessModel / BELL (uncommitted, HEAD 3bf3f53b) behave sensibly, LYRE beside it.
Run: `bin/harness-batch var/harness/media/run.sh 1-4 live && python3 var/harness/media/analyse.py live`
(table `live_4seed_20y.out`), `python3 var/harness/media/diag.py` (`diag_live.out`). Seed-data probe:
`var/harness/media/make_ovr.sh && bin/harness-batch var/harness/media/open_probe.sh 1 base roic10 roic12 capex30 pay60 om16`.

BELL, mean of 4 seeds (per-seed in the .out): open revenue 85.2B, OM 20.0%, P/E 21.7, P/B 3.48, cap 284B (16th of 93);
revenue CAGR 4.3% (3.5-5.4); OM mean 20.3%, min 8.0%, max 33.4%; annual EPS growth SD 33% (LYRE 7.6%); vol 23.7%;
weekly beta 0.55; maxDD -50%; one dividend cut per seed (t 4.4-8.1); 0 distress quarters; worst rating BBB; no deaths,
no NaN. Settled P/E (y3-20 median) 18.3: opens 3.3 points high (LYRE 4.3). EPS beat rate 77.8% (74-85), median revenue
surprise +1.7% (LYRE +1.6%, board-wide).

Streams: first report (covers 2008Q4, airtime season 1.38) 0.41/0.59/0.00; last term 0.48/0.50/0.021 = design. No
negative or zero carriage/airtime quarter. KPI p5/p95: audience 0.93-0.97 / 1.05-1.08; carriage fee 0.97-0.99 /
1.01-1.03; upfront 0.91-0.97 / 1.05-1.07. Blackouts 6, 10, 6, 3 (mean 6.25 vs design 5.6).

Political share of revenue by quarter of the term: exactly 0 in quarters 0-11; 2.07%, 6.70%, 9.54%, 8.83% in 12-15.
Against plan x 2% the multiple is 0.99, 3.00, 5.06, 5.78 (design 1, 3, 5, 7); the last-quarter shortfall is the first
report (covers 2008Q4, t < 0, proximity 0): the other five final quarters run 6.7-9.2. Not a defect.

Cycle: corr(airtime yoy, gap EMA) 0.09 (0.04-0.18): the upfront follows the gap (corr 0.15-0.51), the news audience
leans against it (corr -0.00 to -0.52), and airtime noise (yoy SD ~10%) swamps both. Slump quarters (gap < -1.5%) only
in seeds 1-2 (22, 12; seeds 3-4 have 1 and 0): revenue yoy minus mean -1.1%, -2.5% (LYRE -2.4%, -1.0%); EBIT +0.8%,
-13%. Inconclusive at 2 seeds. corr(audience, log EPU) = 1.00 by construction.

Opening value: cap = P/B x book; P/E is the market's ~21.7 whatever the seed (roic 0.10 -> 20.1, margin 0.16 -> 21.65,
payout 0.60 / capex 0.30 no change). roic 0.10 gives revenue 60.8B, cap 183B; roic 0.12 gives 73.0B, 234B. The ~195B
target at 85B revenue needs a P/E near 15, which the market does not strike; pick size (scale equity/debt/treasury by
~0.69 for 195B, revenue ~58B) or keep 85B revenue at ~285B (settling ~240B at P/E 18).

LYRE: open P/E 21.59, cap 78.7B, OM 24.0%, EPS g SD 7.6%, beta 0.86, same as 10-25 consulting `r4` (unchanged).

## 2026-10-25 (b): arm `tuned` (BELL x0.69, sam 0.20, AIRTIME_VARIANCE_SCALAR 0.12, OPERATING_CYCLICALITY 1.30)

Run: `rm -rf var/harness/cache/test; bin/harness-batch var/harness/media/run.sh 1-4 tuned && python3
var/harness/media/compare.py live tuned` (table `compare_live_tuned.out`; per-seed `tuned_4seed_20y.out`, `diag_tuned.out`,
`corr_change.out`). Seeds 1-4, 20y, TPY 360. Full-market seeds do not pair across arms: differences are indicative.
Opening probe (`open_probe.sh base 1`): revenue 59.3B, P/E 21.65, P/B 3.49, cap 197.7B.

```
BELL, seeds 1-4 mean                      live       tuned
open revenue $B                          85.18       59.29
open P/E / cap tick 1 $B         21.66 / 284.2  21.65 / 197.8
revenue CAGR %                            4.29        3.70
margin mean / min / max %     20.3 / 8.0 / 33.4  20.0 / 8.8 / 34.3
EPS growth SD %                          33.26       41.21
price vol % / weekly beta         23.7 / 0.55    24.4 / 0.53
max drawdown %                          -50.04      -51.70
dividend cuts / worst rating          1 / BBB        1 / BBB
EPS beat rate %                          77.81       73.75
airtime yoy SD %                         10.59        8.89
corr(airtime yoy, gap EMA)                0.09        0.08
corr(airtime yoy, 4q change in gap EMA)   0.06        0.19
blackouts / 20y                           6.25        5.50
slump quarters (gap < -1.5%), per seed    8.75       10.25
slump rev yoy - mean % (pooled)          -0.40       -1.69
slump EBIT yoy - mean % (pooled)         -0.33      -12.55
political % q12-15, live:  2.07 6.70 9.54 8.83 (q0-11 exactly 0)
political % q12-15, tuned: 2.07 6.55 9.08 8.64 (q0-11 exactly 0)
```

Size now on target (198B). The cycle shows in slumps (EBIT -12.6pp, pooled 41 quarters vs 35 in `live`, mostly seeds
1-2 there and 2-4 here, so the arms are not like for like). Airtime yoy SD fell only 10.6 -> 8.9%: the noise term was not
most of it; the upfront and going rate are (upfront yoy SD 5.5-8.5%). corr(airtime yoy, gap EMA level) stays ~0.08
because a yoy growth rate tracks the change in the gap, not its level (0.19 on the change) and the ad shift also reads
the sentiment residual (corr with its 4q change 0.29 mean). Annual EPS growth SD rose to 41% (32-50 by seed): annual
revenue SD 5.6-7.1% times ~5x operating leverage (every dollar of price to EBIT at a 20% margin). No NaN, deaths or
distress; LYRE EPS g SD 9.7%, OM min 19.5%.

## 2026-10-25 (c): arm `final` (`tuned` + priceRevenue removed: variable cost ratio on every dollar)

Run: `rm -rf var/harness/cache/test; bin/harness-batch var/harness/media/run.sh 1-4 final && python3 var/harness/media/compare.py live tuned final` (table `compare_live_tuned_final.out`, per-seed `final_4seed_20y.out`). Seeds 1-4, 20y, TPY 360; arms do not pair, differences indicative.

```
BELL, seeds 1-4 mean                      live       tuned       final
open revenue $B                          85.18       59.29       59.29
open margin %                            20.00       20.00       20.00
open P/E                                 21.66       21.65       21.65
open cap tick 1 $B                      284.23      197.76      197.76
revenue CAGR %                            4.29        3.70        3.82
margin mean %                            20.32       20.04       21.01
margin min %                              8.02        8.80       10.81
margin max %                             33.36       34.34       30.90
EPS growth SD %                          33.26       41.21       24.85
price vol %                              23.68       24.41       24.68
weekly beta                               0.55        0.53        0.56
max drawdown %                          -50.04      -51.70      -52.50
dividend cuts                             1.00        1.00        1.00
worst rating (index)                       BBB         BBB         BBB
EPS beat rate %                          77.81       73.75       74.69
airtime yoy SD %                         10.59        8.89        9.21
corr(airtime yoy, gap EMA)                0.09        0.08        0.10
blackouts / 20y                           6.25        5.50        4.25
slump quarters                            8.75       10.25       15.75
slump rev yoy - mean % (pooled)          -0.40       -1.69       -2.03
slump EBIT yoy - mean % (pooled)         -0.33      -12.55       -7.58
political % by term quarter, live: q0-11 max 0.000; q12 2.07 q13 6.70 q14 9.54 q15 8.83
political % by term quarter, tuned: q0-11 max 0.000; q12 2.07 q13 6.55 q14 9.08 q15 8.64
political % by term quarter, final: q0-11 max 0.000; q12 2.00 q13 6.40 q14 9.00 q15 8.23
```

EPS growth SD 41% -> 25% (20-30 by seed; LYRE 11.7%), margin range 10.8-30.9%. Slumps (63 pooled quarters, all four seeds) still show: revenue -2.0pp, EBIT -7.6pp vs mean. Opening unchanged (198B, P/E 21.65). No NaN, deaths or distress; political ramp intact, zero in q0-11.
