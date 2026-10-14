# Recession-regime diagnosis evidence

## 2026-10-14: why the recession regime is on ~30% of the time (US 0.20)

Question: decompose the recession regime (Sahm >= 0.5pp OR EBP stress latch, in >= 1pp, out <= 0) into its legs, real
recessions, labour-filter effects and EBP behaviour, on the current tree (HEAD 4469eed; src clean vs HEAD).
Harness: `RecessionDiagHarnessTest.php` via `run.sh D1 <seed>` on a snapshot (git archive HEAD + rsync src/config/tests/bin
+ vendor copied), live politics, 16 seeds x 21 years, TPY 360, year 1 dropped. `analyse.py D1` -> `recession_diag_s16.out`.
US: 1985-2019, FRED UNRATE/SAHMREALTIME/USREC, GDPC1, GDPPOT, GZ EBP (latch re-run monthly at 1.0/0.0).

Key rows (mean (se), n=16; US):
- union 0.305 (0.029) vs 0.202; latch_only 0.114 (0.018) vs 0.040; sahm_only 0.114 (0.014) vs 0.098; both 0.077 (0.015) vs 0.064
- sahm_t 0.191 (0.018) vs 0.162; latch_t 0.191 (0.030) vs 0.105
- Sahm episodes/century 16.3 (1.0) vs 8.6, mean 14.1 months vs 22.7; latch entries/century 11.9 (1.6) vs 8.6, 16.3 months vs 14.7
- technical recession share (exact real GDP) 0.124 (0.016) vs 0.043; episodes/century 13.4 (1.2) vs 5.7; CPI-deflated measure 0.101 (0.017)
- real GDP q/q (annualised): mean 1.91% vs 2.64, sd 1.86 vs 2.26, ACF1 0.81 (0.013) vs 0.37; negative quarters 0.132 vs 0.086
- potential growth 1.99% vs 2.58 (CBO), sd 0.63 vs 0.76; Δgap q/q sd 0.42 vs 0.52; gap sd 1.85 vs 1.62
- unemployment: Δ1m sd 0.060 vs 0.153pp, Δ12m sd 0.65 vs 0.92, ratio 10.6 vs 6.0, u sd 0.97 vs 1.51
- Sahm false trips (no technical recession within ±4q): 0.27 (0.075) vs 0.33 on GDPC1 (1 of 3: 2001), 0.0 vs USREC
- offline Sahm share (episodes/c): E0 0.191 (16.3, reproduces engine exactly), sym 2.0/yr 0.182 (15.0), gap pre-smoothed
  0.191 (15.6), NAIRU fixed 0.04 0.183 (15.9), Okun 0.45 0.118 (10.3)
- EBP mean 0.09% vs 0.10, sd 0.47 vs 0.59, quarter ACF1 0.70 vs 0.81, share >= 1pp 0.047 vs 0.067; latch entries with no
  Sahm or technical recession within ±2q 0.40 (0.08) vs 0.33 (1 of 3)

Reading: +10.3pp of union excess = latch-only +7.4, Sahm-only +1.6, both +1.3. Sahm share is near US (t~1.6) but fires
twice as often in shorter episodes; its triggers track technical recessions (false-trip share at US level), which are
2.3x US per century because real growth is far too persistent (ACF1 0.81 vs 0.37) around a lower trend (1.9 vs 2.6%).
Asymmetry, hysteresis and gap noise move the Sahm share < 1pp; only Okun 0.45 moves it, at the cost of an already-low u sd.
Latch: fewer EBP readings >= 1pp than the US but more, less persistent crossings; the excess is in entries (1.4x) not
duration. Caveats: US has 3 Sahm episodes and 3 latch entries in 35 years (Poisson se ~4.9/century), so per-century
US counts are rough; E counterfactuals hold the gap path fixed (no u -> gap feedback).

## 2026-10-14: which gap channels make growth persistent (growth ACF1 0.81 vs US 0.37)

Question: split quarterly Delta gap (quarterly averages) into the output-gap equation's channels and attribute its
variance and lag-1 autocovariance. Toy hypothesis: the demand shock AR(1) in the drift carries the persistence.

Harness: RecessionDiagHarnessTest.php with GAP_OUT (engine OutputGapProbe rolled every tick), arm G1 = HEAD 4469eed
snapshot (git archive + copied vendor), whole board + politics, 8 seeds x 21 years, TPY 360, year 1 dropped.
Analysis: gap_decomp.py G1. Pieces sum to Delta gap to 1.3e-15; probe chain matches the gap to 1.9e-14.

```
arm G1: n=8 seeds x 20 years after year 1 (79 quarterly Deltas/seed), TPY 360
checks (max abs, fractions of potential): probe chain vs gap 1.9e-14; probe gap vs tick.csv gap 5.0e-11; sum of pieces - Delta gap 1.3e-15

Growth moments (quarterly, pp; mean over seeds, se)                US
  Delta gap (quarterly avg) sd                  0.444 (0.026)  0.52
  Delta gap ACF1                                0.802 (0.011)  
  real GDP growth sd [pot x (1+gap)]            0.492 (0.031)  
  real GDP growth ACF1                          0.825 (0.013)  0.37
  potential growth sd                           0.169 (0.016)  
  potential growth ACF1                         0.969 (0.003)  
  potential: cov share of var(real growth)      0.146 (0.024)  
  potential: part of real-growth ACF1           0.172 (0.025)  
  gap level sd                                  1.969 (0.152)  1.62

channel                      sd pp       cov share       own ACF1   part of ACF1(Dgap)
automaticStabiliser         0.5318  -0.197 (0.084)   0.96 (0.01)    -0.356 (0.096)
netExports                  0.2346   0.282 (0.039)   0.89 (0.01)     0.244 (0.033)
monetaryDrag                0.2032   0.208 (0.017)   0.97 (0.00)     0.197 (0.018)
productivitySupply          0.1732   0.189 (0.036)   0.89 (0.01)     0.168 (0.032)
crisisDeleveragingDrag      0.1670   0.096 (0.073)   0.95 (0.00)     0.114 (0.079)
householdNewBorrowing       0.3537   0.042 (0.076)   0.93 (0.02)     0.099 (0.075)
premiumDrag                 0.1049   0.074 (0.021)   0.81 (0.01)     0.073 (0.019)
householdDeleveragingDrag   0.0944   0.082 (0.009)   0.97 (0.01)     0.065 (0.010)
fundStabilisation           0.1086   0.086 (0.008)   0.97 (0.01)     0.058 (0.008)
demandShock                 0.1679   0.035 (0.027)   0.89 (0.01)     0.048 (0.025)
cubicConstraint             0.0609  -0.032 (0.013)   0.91 (0.02)    -0.043 (0.015)
financeOutput               0.0822   0.073 (0.012)   0.49 (0.05)     0.037 (0.012)
housingWealthEffect         0.0932   0.022 (0.019)   0.98 (0.00)     0.029 (0.019)
fiscalStimulus              0.1834  -0.023 (0.028)   0.97 (0.00)    -0.018 (0.031)
diffusion                   0.0694   0.025 (0.006)   0.23 (0.04)     0.016 (0.004)
capitalDrag                 0.0193   0.018 (0.001)   0.98 (0.00)     0.016 (0.001)
creditFrictionDrag          0.0373   0.003 (0.006)   0.93 (0.01)     0.015 (0.006)
lendingStandardsDrag        0.0496   0.010 (0.009)   0.95 (0.01)     0.015 (0.008)
momentum                    0.0213   0.008 (0.003)   0.96 (0.01)     0.014 (0.004)
equityWealthEffect          0.0404   0.007 (0.009)   0.92 (0.01)     0.009 (0.010)
catastropheSupplyDrag       0.0543  -0.002 (0.010)   0.85 (0.01)     0.007 (0.011)
demandDisaster              0.1724   0.005 (0.023)   0.89 (0.01)    -0.004 (0.025)
policyUncertaintyDrag       0.0124  -0.002 (0.002)   0.87 (0.01)    -0.002 (0.002)
inventoryDrag               0.0846  -0.013 (0.008)   0.97 (0.00)     0.002 (0.009)
disasterCompensator         0.0120   0.001 (0.001)   0.86 (0.00)     0.001 (0.001)
freightSupplyDrag           0.0054   0.002 (0.001)   0.92 (0.01)     0.001 (0.001)
energySupplyDrag            0.0483   0.001 (0.008)   0.88 (0.01)    -0.000 (0.007)
sum                                  1.000                             0.802   [= ACF1 Delta gap 0.802]
```

Reading: the diffusion (the only white innovation) is 2.5% of var(Delta gap); every drift channel is a rate that
is a smooth function of slow states, own ACF1 0.81-0.98, so growth inherits it. Demand shock: 3.5% of variance,
0.05 of the 0.80 ACF1 -- the toy hypothesis does not hold here. Persistence carriers (|t| >= 4): netExports 0.24,
monetaryDrag 0.20, productivitySupply 0.17; automaticStabiliser -0.36 (largest piece, sd 0.53pp, offsets).
crisisDeleveragingDrag 0.11 and householdNewBorrowing 0.10 are inside 2 se. Potential leg: 15% of real-growth
variance, 0.17 of its 0.83 ACF1. A white piece with averaging ACF1 ~0.25 would need ~78% of growth variance to
pull ACF1 to 0.37. Caveat: a covariance attribution, not a counterfactual (removing a channel changes the others).

## 2026-10-14: trend growth 2.54% + month-end EBP latch (D2) vs HEAD 4469eed (D1)

Question: does the working tree (labour-force growth = natural 0.54% + Diet net migration, trend 2.0 -> 2.54%; EBP stress
latch updated at month ends on the month's average premium) bring recession moments to US values without breaking guards?
Harness: `RecessionDiagHarnessTest.php` (+ peProbe, D2 only) via `run.sh D2 <seed>` on a snapshot of the working tree,
live politics, YEARS 21, TPY 360, year 1 dropped; D1 reused from `runs/`. `compare.py D1 D2 1-16` -> `trend_latch_s16.out`
(`trend_latch_s8.out` stage 1; `trend_latch_D2_s16.out` = analyse.py D2). Stopped at 16 seeds (caller's ceiling).

Targets, n=16 paired (D1 -> D2, diff (se), t; US):
- techrec (exact real GDP) 0.124 -> 0.059, -0.065 (0.021), t -3.2; US GDPC1 0.043 (negative-quarter share US 0.086)
- union 0.305 -> 0.266, -0.039 (0.045), t -0.9; US 0.202.  sahm_t 0.191 -> 0.202, t +0.4; US 0.162
- latch_t 0.191 -> 0.109, -0.082 (0.041), t -2.0; US 0.105. entries/c 11.9 -> 7.8, t -1.9; US 8.6
- latch duration: per-seed mean 16.3 -> 12.6 months (t -1.3) but 3 D2 seeds have no entry (counted 0); pooled
  months/episode 17.4 -> 16.1, i.e. unchanged; US 14.7
- real GDP growth 1.91 -> 2.48%, +0.56 (0.12), t 4.7; potential 1.99 -> 2.53%, t 5.1; US 2.64 / 2.58
Guards: inflation 2.17 -> 2.08 (t -2.1), sd 1.03 -> 0.97; policy 3.45 -> 3.01 (t -1.3); 10Y 4.57 -> 4.29 (t -1.4);
u 4.66 -> 4.63; gap sd 1.85 -> 1.52 (t -2.1); debt/GDP end 53.4 -> 50.2; deaths/seed 0.31 -> 0.13; growth ACF1
0.81 -> 0.78 (t -3.3, unexpected; US 0.37). D2 median P/E 15.7 (0.25), board cap/NI 15.2, EPS<=0 share 0.8% (D1 lacks).
Caveats: macro live in both arms, so pairing is loose; latch counts are small (3 D2 seeds never latch). Part of the
techrec drop is mechanical: the same gap swings around a 0.55pp higher trend give fewer negative quarters.

## 2026-10-14: debt-target pacing (CONSOLIDATION_PACE 0.005 -> 5pp of debt a year), P1 vs P0

Question: does phasing a new debt target in at the EU corrective pace (anchor moves 0.005/0.10 = 5pp of debt a year)
damp primary-deficit swings without costing output gap or debt stability?
Harness: `RecessionDiagHarnessTest.php` (quarter rows now carry primary, net_tax, debt_target, anchor, spend_idx,
spend_trend) via `run_pace.sh <arm> <seed>`. P1 = working-tree snapshot (scratchpad, RUN.md recipe); P0 = same tree +
`ovr_pace0/` (CONSOLIDATION_PACE = 10.0, anchor lands in ~1 tick). Live politics, YEARS 21, TPY 360, year 1 dropped.
`pace.py P0 P1 1-16` -> `pace_s16.out` (`pace_s8.out` stage 1). Stopped at 16 (caller's ceiling); no target at |t| >= 4.

Targets, n=16 paired (P0 -> P1, diff (se), t):
- primary deficit max |4q change| 2.39 -> 1.92pp, -0.48 (0.25), t -1.9; mean |4q change| 0.56 -> 0.53, t -1.1
- gap < -2% share 0.131 -> 0.188, +0.057 (0.048), t 1.2; gap sd 1.65 -> 1.82, t 1.5; deepest gap -3.17 -> -3.78, t -1.5
- debt/GDP mean 54.1 -> 55.3 (t 0.8), sd 6.4 -> 5.7 (t -0.8), max 66.1 -> 64.5 (t -0.5)
Guards all |t| < 1.7: union 0.283 -> 0.268, sahm_t 0.179 -> 0.194, infl 2.15 -> 2.14, infl sd 0.99 -> 1.08 (t 1.6),
policy 3.49 -> 3.34, 10Y 4.61 -> 4.55, u 4.48 -> 4.64, deaths 0.06/0.06, median P/E 15.7 -> 15.5.
Context: |target - anchor| mean 0.4 -> 8.9pp (t 6.8, the mechanism is live). Target jumps >= 10pp/seed 2.5 / 2.9;
politics paths diverge in 12/16 seeds from year 4.5 on (macro feeds elections), so pairing is loose.
Largest single 4q primary swing in any seed: P0 3.95pp, P1 3.79pp; no seed reproduces the live dump's 7pp swing, even
unpaced (target ranges up to 53pp per seed). Caveat: quarter-end point rows, not quarterly averages.
