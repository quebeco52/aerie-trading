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
