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
