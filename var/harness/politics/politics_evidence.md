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

## Normal-vote drift (2026-10-09)

**Data:** ParlGov, Western Europe from 1945, parties averaging 5% or more of the vote. The normal-weighted half mean squared log
change keeps rising with the gap:

| Gap (years) | 20 | 40 | 60 | 70 |
|---|---|---|---|---|
| Half MSE × normal × 1e3 | 24.6 | 32.5 | 42.1 | 48.4 |

These are 5-year bins (`long_vario.py`). The Nordic series levels off at about 22.

**The walk on top of the Diet's short run** (short 1.2, lasting 19.1, φ 0.905): 0.341e-3 a year, bootstrap se 0.157, 95%
0.09–0.65. The free three-term fit gives the same walk with lasting 15.0. So `LASTING_SWING_VARIANCE` drops from 0.0118 to 0.0095,
taking what the walk adds over a term out of the lasting swing.

**Mechanism:** `OpinionPolls::walkNormals`.
- Neutral share drift (Kimura): step variance V·years·n, then renormalised. The lasting share is carried with its normal.
- The forecast pools the walk's variance and draws a walk to the vote.
- Swing sizes read the current normal.

**16 seeds × 200 years, NEW − BASE** (`walk_16.out` is the unselected version; the table below is with the selection):

| Gap | Base | New | Δ (se, t) | West |
|---|---|---|---|---|
| 4 years | 7.86 | 9.28 | +1.42 (0.36, 3.9) | 7.3 |
| 20 years | 17.85 | 22.37 | +4.52 (1.23, 3.7) | 20.3 |
| 40 years | 20.07 | 28.79 | +8.72 (1.84, 4.7) | 32.0 |
| 80 years | 19.45 | 38.41 | +18.96 (2.90) | — |
| Pedersen | 12.72 | 13.43 | +0.71 (0.30) | — |

- Over the first 76 years only, the 4-year change is +0.95 (t 1.5).
- The walk delivers V·n(1 − n) after renormalising (Wright-Fisher), a little under the fitted V·n for big parties. That is part
  of why 40 years sits at 28.8 rather than 32.

**Beyond the data:** by year 200 the effective number of parties falls from 4.53 to 4.26. The largest party averages 0.39, and in
one game of 16 it reaches 0.72. Party entry (deferred) is what refills a real system.

## Who carries the economic vote in a coalition (vote_pm_fit.py, 2026-10-17)
ParlGov established PR democracies 1971-2020 (WB growth per head and deflator, as vote_europe_fit.py), outcome split by
the outgoing cabinet's prime-minister party (ParlGov `prime_minister` flag). CR1 errors clustered by country.
- Replication: single-party growth +0.508 (0.318), as ELECTION_SINGLE_PARTY_GROWTH_SLOPE.
- Coalitions, 109 votes / 18 countries, the 2008-2020 consolidations apart: PM party growth +0.17 (0.30) pooled,
  +0.32 (0.31) country FE; partners -0.32 (0.24) / +0.02 (0.20); 2008-2020 PM party -0.86 (0.30). Duch & Stevenson's
  PM-party concentration is not visible at conventional levels; the single-party slope is also not ruled out.
- Unemployment change over the year to the vote (WB ILO, 1991-): single-party cabinets -1.91 (0.55) on 41 votes, and
  it takes growth's place (growth -0.01); coalitions +1.84 (1.40), wrong-signed noise on 65 votes. By Okun the
  unemployment and growth terms carry one signal (-1.9 x du ~ +0.8 x g), so adding it beside growth double counts.
- Kept the Diet's form pending the user's call: a PM-party growth slope would be a design choice inside the CI.

## 2026-10-20 Committee supermajority 5/7 (tree) vs HEAD 34f74b09

Question: do HAWKISH/DOVISH_MAJORITY_STANCE = +-5/7 (from 0.33/-0.13) keep macro rate moments in range?
Harness: gate.php arm on via spend.sh (head_tree worktree, cp -a vendor), STAGE=committee_4 YEARS=60 TPY=180,
`bin/harness-batch var/harness/politics/spend.sh 1-4 newon headon`; `python3 committee_an.py runs/committee_4 5`.
Seeds 1-4, 60 y, burn 5 y, quarterly rows. Table in committee_4.out (diff and se in bp; maj rows are quarter shares).
Result: rate/gap/inflation moments move <= 1 bp (mean rate -1.1 bp, se 0.3); majority mix hawk 64->47%, dove 23->8%,
none 13->45% of quarters. ELB share 1.25% both arms. Caveat: n=4, no equity cap (equity-wealth loop off), no fund.

## 2026-10-20 Debt rule: closing over DEBT_CLOSING_YEARS on debt/potential (tree) vs HEAD 00a71ce1

Question: does the reworked requiredPrimaryBalanceAt() stop debt overshooting the cabinet target without moving the cycle?
Harness: gate.php arm `on` (rows now carry dtgt, dpace), spend.sh arms newon/headon, 60 y x 180 ticks/y, burn 5 y.
Run: `STAGE=debtrule_4 YEARS=60 TPY=180 bin/harness-batch var/harness/politics/spend.sh 1-8 newon headon`, then
`python3 var/harness/politics/debtrule_an.py var/harness/politics/runs/debtrule_4 5`. Seeds 1-4 (debtrule_4.out) then 1-8:

```
n=8 seeds, burn 5y; mean over seeds (overshoot max = max over seeds)
quantity                    HEAD      tree      diff      se      t
overshoot mean pp          0.943     1.210     0.267   0.370   0.72
overshoot max pp           2.822     5.057     0.754   0.506   1.49
spells reached             1.750     1.625    -0.125   0.398  -0.31
share |dev|>2pp            0.743     0.826     0.084   0.042   1.97
mean |dev| pp             16.872    16.887     0.015   0.312   0.05
primary bal mean %        -0.156    -0.184    -0.028   0.009  -3.31
primary bal sd pp          0.764     0.771     0.007   0.017   0.41
debt/GDP mean %           42.185    42.633     0.448   0.435   1.03
debt/GDP max %            50.996    51.941     0.945   0.524   1.80
gap sd %                   1.688     1.680    -0.008   0.005  -1.64
rate sd pp                 1.793     1.782    -0.011   0.008  -1.47
inflation mean %           2.153     2.152    -0.001   0.001  -0.55
```

Caveats: targets change every ~4 y and flip between ~37% and ~80% with paces 0.2-1pp, so debt reaches its target in
only ~1.7 spells per seed in 55 y; |debt - target| averages 17pp in both arms. Overshoot rests on ~14 events per arm and
is inside its noise. No equity cap, no fund.

## 2026-10-20 Debt rule with a fixed target (0.37, pace 0.0094), tree vs HEAD 00a71ce1
Question: with the target held fixed, does the tree rule (step = clamp((debt x (1+gap) - target)/9.7, +-pace)) stop the
overshoot of the HEAD rule (step = clamp(debt - target, +-pace)), and how tightly does it hold debt?
Harness: gate.php off arm with FIXED_DEBT=0.37,0.0094 (no politics, founding levers, fixed target and pace from tick 2);
head_tree worktree as in RUN.md. `FIXED_DEBT=0.37,0.0094 STAGE=fixdebt_8 YEARS=60 TPY=180 bin/harness-batch
var/harness/politics/spend.sh 1-8 newoff headoff`, then `python3 var/harness/politics/fixdebt_an.py runs/fixdebt_8`.
Seeds 1-8, 60 years, 180 ticks/yr, no fund, no equity cap. Debt opens at ~50%.
```
n=8 seeds; window years 30-60; mean over seeds
quantity                     HEAD     tree     diff      se      t       tree range
first crossing yr          16.188   22.312    6.125   2.273   2.69     10.50..45.75
overshoot 15y pp            2.011    3.066    1.055   0.537   1.96       0.68..5.42
mean dev pp 30-60           0.253    0.931    0.678   0.838   0.81      -3.46..4.18
mean |dev| pp               1.379    2.894    1.515   0.307   4.94       1.28..4.44
sd dev pp                   1.498    1.878    0.380   0.224   1.70       1.23..2.93
share |dev|>2pp             0.229    0.632    0.403   0.066   6.12       0.20..0.87
prim bal % (all)           -0.020   -0.010    0.010   0.027   0.36      -0.15..0.21
prim bal % (win)           -0.265   -0.245    0.020   0.066   0.30      -0.62..0.05
gap sd % (win)              1.797    1.758   -0.039   0.008  -5.07       1.46..2.35
rate sd pp (win)            1.819    1.784   -0.035   0.031  -1.11       1.60..2.15
debt max % (all)           50.052   50.052    0.000   0.000   2.03     49.59..50.48
debt min % (all)           34.235   33.168   -1.067   0.488  -2.19     30.96..36.13
```
Verdict: no. The tree rule crosses later (+6y), overshoots further (+1.1pp, t 2.0) and holds debt looser: mean |dev|
2.9 vs 1.4pp, 63 vs 23% of quarters beyond 2pp (t 6). Its loop gain near target is 1/9.7 a year against about 1 a
year for HEAD, so purchase and cycle noise move debt more than the rule pulls it back. Nothing explosive (debt 31-50%,
gap SD 1.8%, rate SD 1.8pp in both). Stopped at stage 8: the overshoot interval excludes any reduction.
Decision (user, 2026-10-20): the slower rule was reverted and HEAD's rule kept. Its gain of about 1 a year holds debt within
1.4pp on average; the live game's 5-6pp overshoot sits inside its tail (worst seeds 5.4pp). Only the harness option and
the analysis scripts remain.

## 2026-10-20 Crisis drag scaled by capital built, not the requirement
Question: does scaling the crisis drag and scar by `thinCapitalDragScale(bankCapitalBuilt)` instead of
`(bankCapitalRequirement)` behave sensibly? HEAD 00a71ce1 vs working tree, gate.php arm `on`, seeds 1-4, 60 y, tpy 180.
Run: see RUN.md "Crisis severity". Table: `crisis_4.out`.
- Crises at identical ticks in both arms (10 in total; 2/0/4/4 per seed); odds untouched as intended.
- Drag jump moves by exactly scaleBuilt/scaleReq: -2.1% (s1 y17.6, CCyB 1.35% built) and -1.7% (s4 y40.5) where
  built > req; +1.3% (s1 y3.6) where built lagged a raised requirement. Mean jump 0.0365 -> 0.0362; scales 1.02-1.12.
- Gap SD 0.0169 both, gap mean -0.0022 both, 3y post-crisis gap -0.0065 both; paired differences <= 1e-4.
Caveat: 4 seeds, descriptive; built often trails a rising requirement, so the change can harden a crisis too.

## 2026-10-20 Normal vote pulled back toward founding shares (step 5)
ParlGov semivariogram (long_vario.py) against the model's, with the lasting swing taken from the total a term holds
(0.0118). Weighted RMSE over 10-55 years: walk 1.67 (sigma2 0.67e-3); with a pull back H 20/30/40/50/60 years gives
1.35/1.38/1.43/1.46/1.49 (sigma2 1.97/1.35/1.12/1.01/0.94e-3). Chosen: H 40, sigma2 1.12e-3. It is within half a point of
the walk from 5 to 40 years and follows the flattening at 45-50; the pairs past 50 years are few and survivor-biased.
normal_pull.py (the walk alone, 600 paths): ENP over 200 years 4.39 -> 4.25 against the walk's 4.39 -> 3.77.
Full game: polls/enp_16.out (16 seeds x 200y, new vs HEAD 7ba2d42b). Pooled 90th percentile of the largest vote in years
160-200 is 44% against 54%; votes giving one party a majority 2.5% against 6.2% (t about -2); every cabinet moment within
1 SE. Talks average 29 days in both arms, so FORMATION_MEAN_DAYS was re-measured from 33.7 to 29.3. The 33.7 had held
before the 34f74b09 seed edit.
