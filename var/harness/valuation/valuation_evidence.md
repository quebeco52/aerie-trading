# Valuation evidence

## 2026-10-26: where the value premium comes from, HEAD 102d2f80, 4 seeds x 20 years, TPY 360

Question: does the board reproduce the live value premium, and is it the fair-value pull making price noise
transitory around a public fair value?

    bin/harness-batch var/harness/valuation/run.sh 1-4 head && python3 var/harness/valuation/analyse.py head 1-4 > head_4s20y.out

Reproduced (mean, se over 4 seeds): E/P Q5-Q1 next-year spread +23.6% (1.8), positive 73 of 76 years; -log(P/F)
spread +28.7% (2.0), 74 of 76; slope on log E/P 0.166 (0.009), R2 0.177; slope on log(P/F) -0.524 (0.033), R2 0.277;
momentum -2.6% (1.5). Gap sd 0.188, quarterly AR(1) 0.83, half-life 0.99 y (0.14). var(g) over 1y (2y) windows:
market +0.36 (+0.50), own +0.31 (+0.46), sector +0.06 (+0.08), unfollowed -d log F +0.40 (+0.56), news -0.08 (-0.13),
flow -0.02 (-0.03), pull -0.46 (-0.64), initial gap +0.42 (+0.18), dividends +0.01. Fama-MacBeth next-year return on
1y pieces: market -1.59 (t -3.0), sector -0.20 (t -2.4), own -0.27 (t -13.1), news +0.41 (t 3.8), pull +0.38 (t 10.7),
-d log F -0.09 (t -1.7), flow +0.47 (t 1.1). Annual d log P variance: d log F 0.38, d g 0.62; median vol log P 0.221,
log F 0.174. Board: slope of next-year return on float-weighted log(P/F) -0.29 (se 0.09), R2 0.13 (76 seed-years).
Probe unmatched 0 in all seeds.

## 2026-10-27: fix D1 (fair value on filed returns) vs b3ccdd99, 4 seeds x 20 years

    BASE=<scratchpad>/basetree-b3ccdd99 bin/harness-batch var/harness/valuation/run.sh 1-4 base d1
    python3 var/harness/valuation/d1.py 1-4 base d1 > d1_4s20y.out

base -> d1 (paired d1 - base, se): E/P spread 24.8% -> 20.3% (-4.5 pt, t -3.4), years > 0 97% -> 93%; -log(P/F) spread
28.3% -> 28.1%, all years > 0 both; slope on log E/P 0.174 -> 0.120 (t -4.8), R2 0.193 -> 0.129; momentum -2.4% ->
-3.4%. Gate slope (d log F + D/F - CoE on log E/F): non-financials 0.132 -> 0.047 (t -16.5), financials 0.067 ->
0.046 (t -2.5); target +-0.02. sd log(P/F) 0.185 -> 0.170; half-life 0.85 -> 0.85 y. Board: cap y20 42.5 -> 52.4 tn
(t 2.3), median P/E 16.1 -> 17.8 (t 3.0), vol 0.217 -> 0.216, defaults+deaths 0.75 -> 0, B-or-worse unchanged,
buybacks 23.1 -> 24.3 tn (t 1.0), issuance 1.67 -> 1.51 tn, deals 194 -> 211 per seed (t 2.0). Quarter-1 fair-value
moves > 30%: 12 -> 11 firms per seed (pre-existing). No NaN or non-positive values; probe unmatched 0.
