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

## Phase 3 (monetary), 4 seeds, paired vs baseline seeds 1-4
Rule weights tried on the Phase 3 code (no fund): Charter 0.5/0.5 (total inflation 1.5) gap sd 3.71%, unemp sd 2.70,
max |gap| 10.3%, rate dq sd 19bp; balanced 0.5/1.0 gap sd 2.85, unemp sd 2.03; 1.6/1.5 gap sd 2.28, unemp sd 1.51.
Meetings and the deleted recession-regime/stress latch move little (2.18 → 2.28 at the old weights). User chose 1.5/1.5.
Final (1.5/1.5): no fund gap sd 2.28 (old 2.00, t 1.2), unemp sd 1.51, rate dq sd 29bp (old 36), recession share 8.4%;
fund gap sd 2.59 (old 2.04, t 2.4). Dissents at a two-step threshold: 5.0, 0.2, 2.7 a year on seeds 1-3 (one step gave
11 and 3.3).

## Phase 4 (real economy + credit), 4 seeds, paired vs Phase 3 runs (runs/p3_*, p3fund_*)
First build (deal share 0.239, scar as a TFP level shock): gap sd 2.28 → 2.80 (t 2.6), gap mean −0.55 → −1.26,
recession share 13.9%, 25% of quarters below −3%. Variants: deal flow off → gap sd 2.10; scar off → 2.61. Deal flow
was the whole rise (finance output sd 1.38% of GDP); 0.239 counted brokers, exchanges and asset managers, who do not
earn on deals. Deal share set to IB + PE by roster book equity, 0.098.
Scar as a TFP shock made output fall before potential (a technology pattern), deepening each crisis; changed to
hysteresis (potential takes it over 3y, the gap books it as less slack).
Final, no fund / fund: gap sd 2.28 → 2.21 / 2.59 → 2.44; gap mean −0.55 → −0.89 (t −3.3) / −0.83 → −0.64, median
−1.0; quarters below −3%: 15 → 19% / 17%; unemp mean 5.2 → 5.6 / 5.6 → 5.4; inflation 2.09 / 2.22; recession share
9.2 / 9.8%, 11 / 12 per century; debt mean 46 → 55% (debt − target +2pp). Credit drag averages 0.61pp of demand.
TED median 17bp, p99 164bp, max 241bp. Pressure index mean −0.10, sd 0.96, p99 2.05. Finance output sd 0.78% of GDP.
Supply index constants from the first build: freight log sd 0.20, deliveries sd 6.2, correlation 0.56.
Household paydown (a quarter of the crisis drag repays household debt; the rest stays a direct drag), paired vs the
build above: gap mean −0.89 → −0.83 (t 1.4) / −0.64 → −0.55 (t 4.4); quarters below −3% 19 → 19% / 17 → 15%; gap sd
unchanged. Household debt to income settles at ~0.78 (opening 1.00) by year 40: re-levering at 7.5% of the shortfall a
year balances the average repayment only at lower leverage. New borrowing still averages −0.15pp over 100y (the
transition).
Crisis drag 8y → 4y time constant (on top of the paydown), paired: gap mean −0.83 → −0.61 (t 3.7) / −0.55 → −0.54;
gap sd 2.23 → 2.18 / 2.40 → 2.31; quarters below −3% 19 → 15%; unemp mean 5.53 → 5.31; rate mean 3.07 → 3.29;
debt mean 54 → 52%. Average gap change from the quarter before a crisis (18 crises), years 0-6: Phase 3 −0.4 −2.2 …
−0.3; now −0.6 −1.7 −1.3 −1.3 −1.3 −1.2 −0.6. The tail: household borrowing −1.5 to −2.2pp for years 2-5 (house
prices and debt service; the paydown is ~0.4pp of it), bank output −0.6% as loans fall, net exports −0.9%.

## Phase 5 (politics), 4 seeds, paired vs Phase 4 (runs/p4_*)
No moment moves beyond seed noise: gap mean −0.61 → −0.71 (t −0.6) / −0.54 → −0.69; gap sd 2.18 → 2.29 / 2.31 → 2.35;
recession share 8.6 → 11.7% (t 1.1) / 9.8 → 10.3%; crises 5.6 → 5.0 / 3.1 → 4.2 per century. Household debt to income
after year 40 0.83 → 0.92: a uniform stance with caps only past the opening stance binds less often than the 17
regimes did (11 of 17 had a cap). Lever means (16 × 200y, lever_means_rework.out): persistences 0.59-0.73; public
spending's long-run shift −0.79 → −0.40% with no Council brake; dividend tax 8.8 → 7.7%; utility shortfall 3.98%.

## Phase 6 (lore shifts), 4 seeds, paired vs Phase 5 (runs/p5_*)
Openness alone (exports 0.32, surplus 4.5%): domestic weight 0.57 → 0.84, gap sd 2.29 → 2.29 (net-export gap sd 1.65
→ 0.82 offsets it); trade balance 4.7% (sd 1.2). No DEMAND_SHOCK_SIGMA change.
Floating mortgages with house prices on the policy rate: gap sd 2.29 → 2.76 (t 3.8), rate dq sd 30 → 37bp; the
ring-down rang (overshoot +0.14 to +0.20 of the kick at years 6-7). Variants: migration off 2.94 (migration
stabilises), borrowing weight 0.50 2.68. Pricing homes on the expected path (risk-neutral ten-year + spread) instead
removed the ringing (tail within 0.03) and gave gap sd 2.34, rate dq sd 30bp, recession share 11.0 → 13.8% (t 2.4).
Household debt to income holds 0.82-0.93 by decade from an opening of 0.80 (opening set to 0.85). The post-crisis
net-export dip (Phase 4) is gone: after a crisis the currency falls ~5% and net exports rise 0.2-0.5% of GDP.
BusinessCycleRealismTest left-tail bound 16 → 20% of quarters below −3% (user's choice; it read 16.07%).

## Final: 16 seeds × 100y, old (02e4b43) vs new (Phase 6), paired by seed
No fund / fund: gap sd 2.32 → 2.49 (t 0.9) / 1.98 → 2.39 (t 3.6); unemp sd 1.62 → 1.66 / 1.21 → 1.50; inflation
2.08 → 2.09 / 2.11 → 2.09 (sd 1.08 → 1.12 / 1.09 → 1.07); rate dq sd 35 → 31bp / 36 → 30bp; debt mean 83 → 53% /
64 → 50%, debt max 120 → 72% / 92 → 69%, debt − target +24 → 0pp / +3.6 → −5.8pp; recession share 22.5 → 13.2% /
20.9 → 11.8% (old: Sahm proxy; new: two falling quarters); crises 4.7 → 4.9 / 4.3 → 4.2 per century.
Lever means after Phase 6 (16 × 200y): means within their standard errors of Phase 5's; term persistences 0.66 → 0.50
(dividend tax 0.73 → 0.56), ~4 se, the sharper cycle turning cabinets over faster. PolicyCapitalization updated.

## Short end on the Authority's view (after Phase 6)
The curve read the decided rate, so an expected 25bp step moved the 2y ~13bp on the meeting tick (0.52 per bp; meeting-
day mean |Δ2y| 16.3bp against 10.2bp between). On the unrounded view (marketShortRate): 0.07 per bp, meeting days
10.4bp like any other. Surprises are rounding flips only (2 in 60 years). 4 seeds paired: no moment moves (|t| ≤ 1;
gap sd 2.35 → 2.44, rate dq sd 30.5 → 31.3bp).

## Macro-review step 1 (2026-10-16): defect fixes vs HEAD 48cbb959, 4 seeds x 100y, TPY 48

Question: do the SLOOS shortfall weight (0.87 -> 0.22), shadow-rate reads (FX, slopes, probit), broad-money credit
term and migration in nominal income behave sensibly? Harness: macro_moments.php (+ SLOOS, recession probability,
inversion duration, breakeven, meeting-tick FX), run_step1.sh, analyse_step1.py. Base = clean HEAD export.
No-fund arms pass no equity cap (equity-wealth loop off). Seeds 1-4, 10y burn-in.

```
s1base vs s1new, n=4 seeds (paired), quarterly after 10y burn-in
moment                      s1base     s1new     delta       se      t
gap mean %                  -0.904    -0.852     0.051    0.087    0.6
gap sd %                     2.443     2.355    -0.088    0.120   -0.7
unemp sd %                   1.566     1.498    -0.068    0.078   -0.9
infl mean %                  2.085     2.065    -0.020    0.029   -0.7
infl sd %                    1.126     1.182     0.056    0.046    1.2
rate dq sd bp               31.248    29.270    -1.978    2.179   -0.9
recession share %           14.375    12.639    -1.736    1.527   -1.1
crises /100y                 5.000     4.444    -0.556    0.556   -1.0
debt mean %                 48.404    48.273    -0.131    0.793   -0.2
SLOOS mean                   0.097     0.048    -0.049    0.002  -19.6 *
SLOOS p95                    0.358     0.265    -0.094    0.017   -5.5 *
SLOOS max                    0.510     0.461    -0.049    0.020   -2.5 *
SLOOS at cap %               0.000     0.000     0.000    0.000    0.0
M2 g mean %                  4.272     4.216    -0.055    0.088   -0.6
M2 g sd %                    3.063     2.794    -0.269    0.076   -3.6 *
corr(M2 g, HH borrow)        0.675     0.643    -0.032    0.017   -1.9
rec prob mean %             18.336    17.050    -1.287    1.217   -1.1
rec prob sd %               21.141    18.609    -2.532    2.421   -1.0
inv alarms /100y             7.222     6.389    -0.833    1.145   -0.7
FX dlog q sd %               2.775     2.782     0.007    0.013    0.5
FX min                      77.069    75.179    -1.890    2.025   -0.9
FX max                     120.448   121.046     0.598    0.541    1.1
|dFX| meet bp               94.285    82.592   -11.693    1.863   -6.3 *
|dFX| other bp              44.181    44.458     0.276    0.098    2.8 *
breakeven mean %             2.036     2.031    -0.004    0.007   -0.6
breakeven sd %               0.109     0.107    -0.002    0.002   -0.9
max |gap| %                  8.670     8.558    -0.113    0.168   -0.7

s1basefund vs s1newfund, n=4 seeds (paired), quarterly after 10y burn-in
moment                  s1basefund s1newfund     delta       se      t
gap mean %                  -0.658    -0.454     0.204    0.205    1.0
gap sd %                     2.535     2.331    -0.204    0.183   -1.1
unemp sd %                   1.657     1.459    -0.198    0.182   -1.1
infl mean %                  2.113     2.102    -0.011    0.009   -1.2
infl sd %                    1.007     1.033     0.027    0.024    1.1
rate dq sd bp               29.665    29.163    -0.502    0.302   -1.7
recession share %           11.528    10.556    -0.972    0.939   -1.0
crises /100y                 3.056     2.778    -0.278    0.278   -1.0
debt mean %                 43.454    42.655    -0.799    0.946   -0.8
SLOOS mean                   0.100     0.049    -0.050    0.004  -13.7 *
SLOOS p95                    0.377     0.272    -0.106    0.033   -3.2 *
SLOOS max                    0.634     0.526    -0.108    0.035   -3.1 *
SLOOS at cap %               0.000     0.000     0.000    0.000    0.0
M2 g mean %                  4.653     4.747     0.094    0.181    0.5
M2 g sd %                    3.717     3.362    -0.355    0.212   -1.7
corr(M2 g, HH borrow)        0.735     0.717    -0.018    0.024   -0.7
rec prob mean %             19.031    17.838    -1.194    0.316   -3.8 *
rec prob sd %               21.687    20.007    -1.680    0.311   -5.4 *
inv alarms /100y             6.389     6.111    -0.278    1.145   -0.2
FX dlog q sd %               2.780     2.805     0.025    0.048    0.5
FX min                      73.963    73.546    -0.417    0.228   -1.8
FX max                     120.535   120.133    -0.402    0.903   -0.4
|dFX| meet bp               93.341    84.600    -8.741    0.591  -14.8 *
|dFX| other bp              43.840    44.232     0.392    0.182    2.2 *
breakeven mean %             2.049     2.047    -0.002    0.005   -0.5
breakeven sd %               0.104     0.106     0.002    0.002    1.2
max |gap| %                  7.570     7.323    -0.247    0.214   -1.2
```

Caveats: n=4 (sensible-behaviour check). Headline moments all |t|<2. SLOOS mean falls 0.05 in every arm and seed
(t -14 to -20): the shortfall term is active in most quarters, not only rare ones. FX on meeting ticks still moves
~1.9x other ticks after the fix (94 -> 83 bp vs 44 bp).

FX follow-up (same day, no-fund arms re-run, identical moments): meeting ticks split by mainland quarter turn
(quarterCount changes that tick; every quarter turn is a meeting tick at TPY 48). Mean |dlog FX| bp, n=4:
base / new / delta (se): meeting+quarter turn 127.5 / 120.1 / -7.4 (2.8); mid-quarter meeting 61.1 / 45.1 /
-16.0 (1.6); all quarter turns 127.5 / 120.1; other ticks 44.2 / 44.5. The fix removes the mid-quarter meeting
step; the remaining excess is at the mainland quarter step. Table: step1_fx.out.

## Macro-review step 2 (2026-10-16): inventory-gap units, reimbursement levels, vs HEAD d89691cc, 4 seeds x 100y, TPY 48

Question: do the step-2 fixes (inventoryStockGap as an inventory-to-sales overhang, x1/0.15; reimbursement cut as a
level, floor on indexation only) behave sensibly? Harness: `macro_moments.php` (+ realGdpQuarterlyGrowth,
inventoryStockGap, manufacturingPmi, reimbursementRateIndex/Growth, consumerPriceLevel), `run_step2.sh` arms
s2base/s2basefund (export of HEAD at /tmp/claude-1000/base2) and s2new/s2newfund; `analyse_step2.py` (+ `step2_extra.py`).
Table: `step2.out`. Seeds 1-4, 10y burn-in, no equity-cap loop in the no-fund arm.

| quantity (no fund) | base | new | delta (se) |
|---|---|---|---|
| gap sd % | 2.355 | 2.387 | +0.032 (0.003) |
| GDP q/q sd % | 0.587 | 0.595 | +0.008 (0.0005) |
| GDP q/q acf1 | 0.774 | 0.780 | +0.006 (0.0006) |
| inv gap sd | 0.0018 | 0.0123 | ratio 6.8 (fund 6.7) |
| inv gap p5 / p95 | -0.003 / 0.003 | -0.019 / 0.021 | |
| PMI sd | 6.53 | 6.74 | +0.21 (0.006) |
| reimb growth mean % | 1.00 | 1.45 | +0.45 (0.06) |
| reimb updates <= 0 % | 33.4 | 13.2 | -20.2 (3.8) |
| log(R/P) drift, %/yr | -1.08 | -0.65 | +0.43 (0.06) |

Fund arm: same signs and sizes. Other moments move by under 1.5% of level (infl mean +0.004pp, rate dq sd +0.3bp,
max |gap| 8.6 -> 8.7%); crises, debt, recession share unchanged within noise. Remaining reimbursement drift of
-0.65%/yr is the 0.6% productivity offset by design. The stronger inventory cycle raises GDP acf1 slightly, not lowers it.
