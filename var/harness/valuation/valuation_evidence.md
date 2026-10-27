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

## 2026-10-27: fix D2 (fair-value revision passed into the price) vs D1 (a8207c15), 4 seeds x 20 years

    BASE=<scratchpad>/basetree-a8207c15 bin/harness-batch var/harness/valuation/run.sh 1-4 d1 && bin/harness-batch ... 1-4 d2
    python3 var/harness/valuation/d2.py 1-4 d1 d2 > d2_4s20y.out

Means d1 -> d2 (paired d2 - d1, se): E/P slope 0.125 -> 0.123 (-0.002, 0.006); R2 0.123 -> 0.110 (-0.013, 0.017);
E/P spread 19.2% -> 18.3% (-0.9 pt, 1.7); -log(P/F) spread 27.5% -> 26.2% (-1.3 pt, 3.0); sd log(P/F) 0.183 -> 0.156
(-0.028, 0.013, t -2.1); half-life 0.98 -> 0.86 y (t -1.3); momentum -5.2% -> -7.8% (-2.6 pt, t -3.2); F gate
non-financials 0.057 -> 0.047 (t -1.7), financials 0.053 -> 0.047. Board next-year return on board E/P: R2 0.181 ->
0.111; on board log(P/F) R2 0.291 -> 0.310. Vol: median name 0.225 -> 0.219 (t -0.7), index 0.157 -> 0.157. Own-source
variance at the 80% cap: 2.8% -> 28.6% of name-ticks (t 16.5); median announcement variance 0.00067 -> 0.0047 (x7).
Revision: annual variance per name median 0.020; share of var(g) +0.01 (se 0.03). b_hat -13.6..-17.0 (y5), -16.1..
-17.0 (y20). Board: cap y20 58.8 -> 58.1 tn, P/E 19.1 -> 18.8, defaults 0.75 -> 0.25, buybacks +1.3 tn (t 3.1),
deals 179 -> 205 (t 2.4), quarter-1 F moves > 30% 10.8 -> 12.8 firms (t 4.9); no NaN; unmatched 0.

## 2026-10-27: d2b (D2 + no cycle term in perpetual growth + revision variance on its own EMA), 4 seeds x 20 y

    bin/harness-batch var/harness/valuation/run.sh 1-4 d2b && python3 var/harness/valuation/d3.py 1-4 d1 d2 d2b > d2b_4s20y.out

d1 / d2 / d2b (paired d2b - d1; d2b - d2): E/P slope .125 / .123 / .106 (-.019, t -1.2; -.017, t -1.4); R2 .123 /
.110 / .117; E/P spread 19.2 / 18.3 / 17.5%; -log(P/F) spread 27.5 / 26.2 / 23.9% (t -1.1 both); sd gap .183 / .156 /
.149 (t -2.9; -1.3); half-life .98 / .86 / .85 y; momentum -5.2 / -7.8 / -10.1% (t -5.5; -2.8); F gate non-fin .057 /
.047 / .030 (t -2.7; -1.6), fin .053 / .047 / .018 (t -5.1; -3.1). Board next-year return R2 on E/P .181 / .111 / .116,
on log(P/F) .291 / .310 / .376. Corr with the change in the output gap: board d log F +.43 / +.28 / -.18, d log P +.05
/ +.50 / +.36; sd board d log F .144 / .140 / .118. Vol: median name .225 / .219 / .215 (d2b - d2 -.005, t -2.2),
index .157 / .157 / .147 (-.009, t -2.3); cap hits 2.8 / 28.6 / 31.5% of name-ticks; median announcement variance
.00067 / .0047 / .00061, revision variance 0 / 0 / .0052; revision share of var(g) 0 / .01 / .14 (t 7.8 vs d2). Board:
cap y20 58.8 / 58.1 / 59.3 tn, P/E 19.1 / 18.8 / 18.7, defaults .75 / .25 / 1.0, deals 179 / 205 / 192, q1 F moves >
30% 10.8 / 12.8 / 12.5; no NaN; unmatched 0.

## 2026-10-27: c1 = a8207c15 + the cycle fix alone (no D2 pass-through, no budget change), 4 seeds x 20 y

    BASE=<scratchpad>/basetree-c1 (git archive a8207c15 + cycle_fix.patch) bin/harness-batch var/harness/valuation/run.sh 1-4 c1
    python3 var/harness/valuation/d3.py 1-4 d1 d2b c1 > c1_4s20y.out

d1 / d2b / c1 (paired c1 - d1): E/P slope .125 / .106 / .115 (-.010, se .004, t -2.5); R2 .123 / .117 / .125 (+.002);
E/P spread 19.2 / 17.5 / 19.3% (+0.1 pt); -log(P/F) spread 27.5 / 23.9 / 28.4% (+0.9 pt); sd gap .183 / .149 / .170
(-.013, t -0.9); half-life .98 / .85 / .83 y (-.15, t -4.2); momentum -5.2 / -10.1 / -6.5% (-1.3 pt, t -1.0); F gate
non-fin .057 / .030 / .043 (-.015, t -1.2), fin .053 / .018 / .013 (-.040, t -3.3). Board R2 on E/P .181 / .116 /
.171, on log(P/F) .291 / .376 / .299. Corr with the change in the output gap: board d log F +.43 / -.18 / +.02, d log
P +.05 / +.36 / +.04; sd board d log F .144 / .118 / .134, d log P .144 / .181 / .149. Vol: name .225 / .215 / .227,
index .157 / .147 / .158; cap hits 2.8 / 31.5 / 2.9%. Board: cap y20 58.8 / 59.3 / 55.2 tn, P/E 19.1 / 18.7 / 17.2 (c1
- d1 -1.9, t -2.5), defaults .75 / 1.0 / .25, deals 179 / 192 / 197; no NaN; unmatched 0.

Decision (10-27): the D2 pass-through and d2b's revision budget are not kept. They leave the cross-section anomaly
about where it was, deepen the reversal (momentum -5.2 -> -10.1%) and make the board more predictable (log(P/F) R2
.29 -> .38). The cycle fix (c1) is kept. The d2/d2b arms describe trees no longer in the repository.

## 2026-10-27: F1 (durable demand on the change in the lagged gap) vs c1, 4 seeds x 20 y

    bin/harness-batch var/harness/valuation/run.sh 1-4 f1
    META=<scratchpad>/recession/meta.json python3 var/harness/valuation/recession.py 1-4 c1 f1 > f1_recession_4s.out
    python3 var/harness/valuation/d3.py 1-4 c1 d2b f1 > f1_4s20y.out

Betas c1 -> f1 (median per seed, paired): F1 firms with cyc >= 1 revenue 0.89 -> 2.00 (t 5.5), EBIT 1.9 -> 6.5, NI 2.1 ->
7.8 (t 4.2); all F1 revenue 1.05 -> 2.18, NI 3.2 -> 8.1; order-book revenue 2.13 -> 2.37 (t 1.3), NI 5.6 -> 9.0 (t
3.1); others revenue 0.71 -> 0.56, NI 2.4 -> 2.9 (t 1.1). Episodes (gap down >= 3 pp; 5 / 6): F1 NI peak-to-trough
-3.6% -> -22.8% (range -3.7..-64.7%), cyclical aggregate -26% -> -20% (range 0..-62%); NI trough 0 -> +1 quarter after
the gap trough. Revenue growth /yr: F1 5.17 -> 5.02% (t -0.4), order-book 4.64 -> 3.56% (t -3.4), others 4.86 -> 4.36%
(t -4.5), board nominal 4.90 -> 4.07% (t -4.4); mean output gap over reports -0.78 pp (t -1.9). Revenue beat rate
(rev > expected revenue; EPS consensus not logged) 52.7 -> 47.8% (t -7.2). Defaults 0.25 -> 0.5, B-or-worse 0.73 ->
0.77%. Board: corr(d log NI, d log P) 0.16 -> 0.30; NI_t with P_t+1 +0.03 -> -0.03, P_t with NI_t+1 +0.04 -> -0.28;
corr(board d log F, d gap) +0.02 -> +0.30, d log P +0.04 -> +0.09. Valuation: E/P slope 0.115 -> 0.131 (t 2.3),
sd gap 0.170 -> 0.177 (t 2.6), board R2 E/P 0.171 -> 0.182, log(P/F) 0.299 -> 0.309, index vol 0.158 -> 0.166 (t
4.0); no NaN; unmatched 0.

Correction (10-27, the architect's follow-up; scripts in the session scratchpad, recession/f1): the board gates above
read the macro path, not F1. Arms are not paired on the macro: macro and firms share one random stream, so c1 and f1
split within two quarters, and all four f1 runs ended in a slump (end gap -1.5..-2.3% vs c1 -0.6..+2.0%). Replaying
one recorded macro path in both arms (MACRO_RECORD / MACRO_REPLAY, 4 seeds): board growth +0.07 pp/yr (t 0.6), beats
-0.1 pp, order-book growth +0.03, industry price level unchanged; F1 firms' revenue beta 1.2-1.5 -> 1.75-3.9 in every
seed. 12 fresh seeds per arm, unpaired: mean gap +0.15 pp (t 0.6), board growth +0.07 (t 0.5), beats +0.4 pp, index vol
0. The equity wealth feedback is too weak to matter (elasticity 0.0206: +-0.05 pp of gap). F1 is kept. Firm-level gates
belong on replay pairs; macro-level gates need ~16 unpaired seeds per arm, and growth is read as a log-trend slope, not
first year to last. The "paired" t of every macro-dependent board quantity in this file (P/E, cap, defaults, deals,
growth) is a 4-seed unpaired comparison.

## 2026-10-27: crashes without an earnings fall (live read item 3), HEAD 98b7d96a, 8 seeds x 20 y

    bin/harness-batch var/harness/valuation/run.sh 1-6 crash && bin/harness-batch var/harness/valuation/run.sh 7-8 crash
    python3 var/harness/valuation/crash.py crash 1-8 > crash_8s20y.out   # also f1 1-4, c1 1-4 (no macro rows)

`crash` arm = working tree with MACRO_OUT/BOARD_NI and the macro rows extended (y10, credit, tips, mood, mz_latent,
vol_ema, ebp). Board = float-cap weights at the quarter start; channels are the Probe price channels; d g = index log
price return - d log F per share; `idio` carries the expected-return drift (+0.036 a quarter unconditionally, with jS
-0.014 a quarter as the jumps' mean). Drawdowns >= 25% on the index, year 1 skipped.

Frequency: >= 20% 14, >= 25% 6, >= 30% 4 episodes in 152 seed-years; worst per 19-y run median -26% (-20..-35%). Board
log(P/F) sd 0.154, quarterly AR(1) 0.76 (half-life 0.63 y).

Means over the 6 crash-arm episodes (f1: 4, c1: 6 episodes agree in sign and size):
- build-up (neutral g to peak, 3.2 q): index +0.15, d log F -0.06 (y10 +0.7 pp; F about -0.12 against its trend), d g
  +0.20 = market (mkt+jS) +0.09 + drift above F + F falling, pull -0.07; board NI +6%.
- fall (peak to trough, 6.5 q): index -0.39, d log F +0.08 (y10 -0.6 pp), d g -0.49 = mkt -0.24, jS -0.31 (-0.22 beyond
  the jumps' mean), drift +0.22 (normal), pull -0.01; board NI -12% (-37..+9%); gap -1.5 pp; vol +0.12.
- rebound (trough + 4 q): index +0.36 = d log F +0.17 (NI +12%) + d g +0.17 (mkt +0.17, selected by the trough; pull
  +0.06); jS normal.

What the common component loads on (quarterly, 584 board-quarters): market channel vs d(y10, credit, gap, vol, erp,
mood): R2 0.42, all from vol (corr -0.64, the leverage effect it causes); corr with d y10 -0.02, credit 0.00, gap +0.07,
erp -0.01, mood 0.00. Board d log F: y10 -17.1, erp -14.8 per unit, R2 0.97, corr with the market channel ~0. Board d g:
y10 +15.7, erp +15.4, R2 0.67, corr(d g, d y10) +0.57. Share of quarterly board d g variance: market channel 0.56,
-d log F 0.43. Annual index return vs d y10: corr -0.05. Annual d y10 AR(1) -0.24.

Rebound reliability without look-ahead (next 4 q index log return; unconditional +0.075, 70% > 0): board g >= 0.20 below
its seed median +0.205, 91% > 0 (n 69; c1 +0.267, 100%, n 26); from the quarter the index first closes 25% below its
peak +0.32, 6 of 6 > 0 (c1 +0.14, 4 of 6). Slope of next-4q return on board g -0.40, R2 0.22 (c1 -0.60, 0.31).

Reading: the crashes are round trips of the board gap. Market noise (diffusion and clustered market jumps) has no macro
counterpart and never reaches fair value (FairValue discounts at CAPM on y10 with the habit ERP of the output-gap EMA),
so every market fall opens a gap that the 1-y pull closes; fair value's own discount-rate swings (y10, ERP), which the
price ignores, widen the build-up (rates up, F down) and the trough (rates down, F up). Earnings dip mildly. Real
references (US): 1-y R2 of returns on D/P 0.04-0.09 (Cochrane); ~1 in 4 drawdowns of 30%+ without a recession (Man
Group), and those are short (~3 months) and shallow (median -22%, CFA Institute); 1987 fell 34% with forward EPS +6%
on a P/E cut from 14.8 to 10.5 (Yardeni); average 1-y return from a 25% fall +19% (RBC), after drawdowns > 20% +17.6%
(Gilbert) vs ~+9-10% unconditional, with 1929, 1973-74, 2001 and 2008 lower a year later.

## Fix B: equity's riskless rate off the expected path (10-27, 8 paired seeds x 20 y, b1 vs crash)

Cost of equity struck on the 10y term premium plus the expected rate path read at an equity's ~18-year duration, in
place of the spot 10y. Macro-only probe (4 seeds x 40 y, a market-beta perpetuity): d log PV per pp of 10y -19.9% ->
-13.7%, level -1.4% (the full curve would give -12.0% but -8.5% on the level, the long-end term premium counted on
top of a premium measured over the 10y). Board, paired:
- quarterly d log F on d y10 -17.1 -> -14.2; corr(d log F, d y10) -0.90 -> -0.75; sd quarterly d log F .072 -> .065;
- sd quarterly d g .104 -> .100; corr(d g, d y10) +.57 -> +.46;
- next-4q return on board g: slope -0.40 -> -0.39, R2 0.22 -> 0.20; drawdowns >= 25%: 6 -> 5;
- median CoE 8.13% -> 8.22%; median P/E 19.05 -> 18.96 (t -0.7); vol, defaults, B-or-worse, E/P slope, momentum n.s.;
  cap y20 -5.5% (t -2.0).
A modest gain: the market channel (fix A) carries the rest.

## Fix A: market moves reprice fair value as discount-rate news (10-27, 8 paired seeds x 20 y, a1 vs b1)

The market factor carries no cash-flow news, so by the present-value identity its moves are discount-rate news: its
unexpected log return accumulates in a macro state v that fades with a 5-year half-life; fair value and the analyst
targets carry exp(beta v), the drift adds beta times v's fade, and the beta is levered on fair value without the
repricing. The first run levered beta on fair value with it: a distressed name's (IBHI, seed 1) beta and value
spiralled (fair value 226 -> 1996 in a year), and B-or-worse rose 1.4pp; levering on the fundamentals fixed it.
- Board next-year return on board E/P: R2 0.135 -> 0.066 (real 0.04-0.09); on board log(P/F) 0.187 -> 0.008.
- Drawdowns >= 25%: 5 -> 14 in 152 seed-years (one per ~11 years; post-war US ~one per 7-10). In a fall d log F
  -0.45 and d g -0.03 (B: F +0.04, g -0.50).
- From the first close 25% below the peak, next 4 q: +0.171, 77% positive, n 13 (B: +0.306, 100%, n 5; real +17-19%,
  about 2 in 3).
- Paired, n.s.: E/P slope, momentum, gate slopes, cap y20, P/E, vol (daily index and name, quarterly), issuance,
  deals; defaults -0.5 (t -1.5). B-or-worse +0.7pp (t 2.3): the Merton distance to default reads market equity, which
  now stays down through a bear market as real equity does.

## Momentum: franchise news and slow analysts (10-27, 8 seeds x 20 y)

    BASE=<scratchpad>/mbase bin/harness-batch var/harness/valuation/run.sh 1-8 mbase2 mom3 mom3w
    python3 var/harness/valuation/momentum.py 1-8 mbase2 mom3 mom3w > momentum_paired_8s20y.out

Paired on firms too from this round (BoardRun now fixes the outlook salt; earlier arms paired on the macro only).
mbase2 = b29b723f; mom3 = franchise power (log-odds margin shift, 10y fade, news share .5, book-levered); mom3w = mom3
with news share .65. mbase2 -> mom3 (paired, se): mom 12-1 -3.6% -> -0.7% (t 2.5), positive years 35 -> 49%; noise
slope -.32 -> -.22; E/P spread 19.5 -> 14.5% (t -3.2); gap sd .173 -> .153; PEAD 1q x-rep 1.2 -> 0.6% (t -3.1); vol
flat, index vol -.2pp; momentum agents' median share .473 -> .478; defaults .25 -> 1.1 per run (t 2.5, TERN 3 seeds).
mom3w: momentum +2.0pp only, defaults +1.5 (t 4.6). Unpaired earlier arms: margin-points v1 (mom1) blew up at the
fair-value floor; step 2 at s = .5 (mom3s) -1.6% with daily vol held, (mom3c, no rescale) +0.4% with median vol -1.4pp.
