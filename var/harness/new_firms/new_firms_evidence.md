# New firms evidence

## 2026-10-12 BOWR + FNCH sanity, 3 seeds x 20y, 360 ticks/yr, live macro, POLITICS off

Question: are the new homebuilder (BOWR) and gold miner (FNCH) grossly broken on a full board? Sanity check, not tuning.
Harness and commands: see RUN.md. Table: `sanity_3seed_20y.out`. Tree: worktree-homebuilder-gold-miner, uncommitted src.

- BOWR op margin p5/p50/p95 pooled 6.2/15.9/24.5%; loss quarters 3.3%. home_sales_margin 5-35% (median 24%)
  against DHI 11-29% ex-impairments: median and max sit about 5 points high.
- No inventory write-down in any seed. The house price/trend low was 0.89-0.95, and the impairment fires only when
  land cost exceeds price less construction (home_sales_margin below 0), so the path never ran. Not tested here.
- corr(hsm, house px/trend) 0.56-0.77.
- BOWR price y20/seed 14-38x (board median about 5x). There were no splits: buybacks cut its shares from 1000M to
  116-318M by year 16 (NVR-like). The price outlier comes from buybacks, not a valuation blow-up.
- FNCH op margin p5/p50/p95 -6/18/33%; loss quarters 11%. corr(YoY rev, YoY gold) 0.21-0.45 against 0.04 for
  industrial metals. Return corr with the EW board 0.34 (CNDR 0.31). Price y20/seed 1.15 (board median about 5x).
- Board failures 0/0/0 (baseline 0.23 per seed); no deaths.
- FNCH shrinks: revenue at y20 is 0.52-0.66x year 1, the gold EMA ends at 81-89 from about 100 in every seed
  (mean-reverting, not inflation-indexed?), shares fall to 0.31-0.60x and equity to 0.46-0.88x. Worth a check
  that the gold index is meant to carry no nominal drift.

## 2026-10-12 follow-up: arms v2-v4 (same seeds, `ARM=<arm> python3 analyse.py 1 2 3`, `ARM=<arm> python3 -I paths.py 1 2 3`)

- v1 drifts traced: (1) BOWR inherited the standard plant modernization physics; with almost no plant every dollar of
  capex read as over-investment and lifted its margin toward the 28% ceiling. Fixed: decay and gain rates 0. (2) FNCH
  `steward` at 10% ROIC never cleared its hurdle and paid out: now `operator`, ROIC 12%.
- v2/v3 (land bank as inventory, capex_ratio 0.45 / 0.20; identical runs, so capex never bound): BOWR expected revenue
  ran to 7.3x by y8 (seed 1) and 10.9x by y19 (seed 3) on share issuance. Cause: capacity = turnover x invested capital,
  and invested capital holds the trade cycle, so a firm whose capital is mostly inventory feeds its own capacity
  (loop gain = inventory / invested capital = 0.92 for the builder, ~0.2 for a typical firm).
- v4 (final): land bank on the plant ledger, trade cycle = homes under construction (loop gain ~0.2), land write-down
  booked against plant (EarningsEngine::applyPlantImpairment), capex_ratio 0.45.
  - BOWR op margin p5/p50/p95 3.6/11.7/18.5%; loss quarters 3.7%; home_sales_margin 8.5/21.8/30.9% (min/med/max) vs
    DHI 11-29%; corr with house px/trend 0.59; price y20/seed 4.4-5.3x; expected revenue y16/y0 1.65/1.48/0.97 (board
    median 2.0). No land write-down: house px/trend low 0.89-0.91.
  - FNCH op margin -1/21/45%; loss quarters 10%; corr(YoY rev, gold) 0.55 vs metals 0.18; board corr 0.23 (CNDR 0.33);
    price y20/seed 15.4/0.31/3.8. Seed 2: gold 70-79 from y4, buybacks 2.5-3.5x NI while cheap, revenue 0.27x by y19.
  - Board failures 0/0/0.

## 2026-10-12 round 2: PELI (health plan) + EGRT (custody bank), 3 seeds x 20y, live macro

`ARM=<arm> python3 var/harness/new_firms/analyse_round2.py 1 2 3` (rows gained mcr, reb, ugap, reimb, ewr, fund).

- r2v1: PELI medical cost ratio median 77.7%, rebates in 80/80 quarters (3.9% of premium). Two causes: the engine strikes
  the variable/fixed split on cash costs net of depreciation (1.6% of revenue), and the standard plant modernization lifted
  the margin on 0.4x revenue of systems and goodwill. Fixed: decay/gain 0 for the plan, fixed_cost_ratio 0.127 -> 0.112.
  Board: TIER died in seed 3 at y17.5 (the known PE leverage defect).
- r2v2 (final):
  - PELI medical cost ratio 77.3/82.7/91.1% min/median/max (UNH 79.1-89.1%, 82.5% in 2019); rebates in 26/80 quarters,
    0.2% of premium; op margin 0/5.3/8.6% p5/p50/p95; loss quarters 7.5%; corr(reimbursement update, MCR) +0.18;
    board corr 0.54; price y20/seed 2.8-3.7.
  - EGRT ROE 8.7/13.7/19.7% (STT ~10-12%); no loss quarters; corr(YoY log revenue, YoY log equity/trend) 0.27; board
    corr 0.60 (LAKE 0.78); price y20/seed 3.4-8.3.
  - Board failures 0/0/0.

## 2026-10-16 round 3: SKUA (challenger carrier), DIPR (independent power), KTWK (port terminal), 3 seeds x 20y

Working tree as is (89 firms; draws shift for every firm, so no pairing with rounds 1-2). Live macro, POLITICS off,
360 ticks/yr, arm `r3v1`: `HEALTH=1 bin/harness-batch var/harness/new_firms/run.sh 1-3 r3v1 &&
ARM=r3v1 python3 var/harness/new_firms/analyse_round3.py 1 2 3`. Table: `round3_3seed_20y.out`. Means of 3 seeds:

| | SKUA | LOON | DIPR | KTWK |
|---|---|---|---|---|
| op margin p5/p50/p95 | 7.1/15.7/23.4% | 14.7/21.6/27.2% | -1.3/16.8/32.3% | 32.6/38.5/43.7% |
| loss quarters | 6.7% (0/19/1) | 0 | 21.2% | 0 |
| max ND/EBITDA (covenant) | 4.1/5.6/3.8 (4.5) | 4.1/3.5/3.4 (4.5) | 6.8/14.8/9.3 (4.0) | 2.9/2.9/3.2 (4.5) |
| quarters over covenant | 0/4/0 | 0 | 17/17/20 of 80 | 0 |
| price y20/tick 1 (board median 4.5/4.3/3.4) | 60.2/1.8/7.9 | 2.7/4.4/1.5 | 3.4/2.1/1.8 | 4.4/4.6/3.0 |
| revenue y20/y0 (4q sums) | 4.48/0.41/2.25 | 1.80/1.61/1.42 | 0.56/0.84/0.62 | 1.86/1.86/2.53 |

- No new firm died or restructured. DIPR is in recurring distress: shut out of the bond market 7/3/3 times, emergency
  dilutive equity in seeds 2-3, one missed maturity cured (seed 2), PP&E sold off in 1-2 quarters per seed (seed 3 y17:
  PP&E -66%, revenue 1.37 -> 0.44 of y0). The covenant breach itself raises no event line.
- Telecom: wars 3/0/0 per seed (~1.7 expected at 2.3%/quarter); war quarters 10%/0/0 for both carriers; the clocks
  differ only in the onset quarter of the joining carrier (2 quarters in seed 1, by design). SKUA ARPU 0.952 in war vs
  1.001. SKUA revenue share y0 0.26-0.29 -> y20 0.47/0.09/0.39 (seed 2's fall is asset sales at y12-14 plus a 1:10
  reverse split, not churn). Seed 1 SKUA: shares cut to 0.28x by y12 through buybacks, price x60.
- DIPR: corr(margin, gas EMA) +0.80, corr(margin, power EMA) +0.90; outages 2/6/1; hedge gain/revenue p5 -17.6%,
  p95 +18.8%; clean spark spread never negative (min 0.12). Gas EMA spans 28-256.
- KTWK: throughput 0.76-1.09, dwell 0.50-1.61 with the 0.50 floor binding 2/17/9 of 80 quarters; strikes 4/1/0;
  corr(YoY log revenue, YoY change): foreign gap +0.34, world gap +0.26, domestic gap -0.02.
- Board: failures 0/0/1 (TIER y18, the known PE leverage defect), reorgs 1/0/0 (FALC y2.9): 0.33/seed vs ~0.23,
  inside 3-seed noise. No zero/negative/NaN revenue. Split inference fails on firms with many splits (FALC x64), so
  the board shares-min column is unreliable; CASC (no splits) ends at 0.18x shares.
- Harness fix: `updateMacroState` lost its `boardBankCredit` argument; the harness no longer passes it.

## 2026-10-16 round 3 v2: fixed tree (DIPR/SKUA reseeded, DIPR seasonal ladders, KTWK dwell/strike rework), 4 seeds x 20y

Arm `r3v2`, same settings: `HEALTH=1 bin/harness-batch var/harness/new_firms/run.sh 1-4 r3v2 &&
ARM=r3v2 python3 var/harness/new_firms/analyse_round3.py 1 2 3 4`. Table: `round3v2_4seed_20y.out` (adds min trailing
EBIT/interest and default/missed/shut-out/emergency counts, hedge gain by calendar quarter, throughput at strikes).

| mean of 4 (per seed) | SKUA | LOON | DIPR | KTWK |
|---|---|---|---|---|
| op margin p5/p50/p95 | 0.3/12.2/22.9% | 11.6/20.9/26.5% | 7.4/28.1/43.0% | 32.7/37.6/42.0% |
| loss quarters | 14.7% (16/1/6/35) | 1.3% | 8.4% | 0 |
| max ND/EBITDA (cov) | 7.1/3.1/6.7/9.7 (4.5) | 4.1/3.1/4.5/4.9 (4.5) | 6.7/7.1/8.6/5.5 (4.0) | 2.5-2.8 (4.5) |
| quarters over covenant /80 | 1/0/2/8 | 0/0/0/3 | 14/8/14/15 | 0 |
| min EBIT/interest | -3.3/2.5/-0.9/-0.6 | 1.2-3.3 | 0.2-1.0 | 3.4-4.3 |
| shut-out / emergency equity | 0/0/4/7, 0/0/0/3 | none | 1/1/1/0, 0/0/2/0 | 0/0/0/0 |
| price y20/tick 1 (board 3.8/3.1/2.4/2.5) | 1.5/25.4/7.9/1.0 | 1.5/3.1/1.4/1.1 | 9.1/2.2/0.9/2.3 | 2.3/1.8/1.9/1.5 |
| revenue y20/y0 | 0.97/4.09/2.10/0.75 | 1.46 | 1.12 | 1.37 |

- No default, missed maturity, death or reorg among the four. Board: failures 0/0/0/0, reorgs 1/0/0/0 (FALC y17.4).
- DIPR much improved (loss quarters 21% -> 8%, shut-outs 13 -> 3 in total), still over its 4.0x covenant in 16% of
  quarters. Hedge gain/revenue p5 -18.0%, p95 +15.2%; mean by calendar quarter +0.5/+0.7/+0.2/-0.4% (r3v1: Q2 +10%):
  the fixed seasonal pattern is gone. corr(margin, gas) +0.75, corr(margin, power) +0.88; outages 2/0/2/0.
- SKUA got worse: plant sales that halve revenue in a quarter (seed 1 y15.6, seed 3 y6.3, seed 4 y10.3), share of
  SKUA+LOON 0.25 -> 0.21/0.49/0.36/0.12. Wars 2/2/2/4, war quarters 12.5% both; clocks differ 3-6 quarters a seed
  (the joining carrier's report lag at onset and end). ARPU 0.946 in war vs 0.999.
- KTWK: dwell 0.53-1.54 (no floor), throughput 0.84-1.20; strikes 1/0/4/1; throughput at onset 0.83-0.90 of the prior
  quarter, +1q mostly 1.09-1.10 (deferred boxes released); one seed-3 strike held 3 quarters (0.83/0.79/0.79).
  corr(YoY log revenue, YoY change in the foreign gap) +0.31, domestic +0.16.

## 2026-10-16 round 3 v3: telecom PRICE_WAR_PENALTY (8 margin pts in war quarters) removed, 4 seeds x 20y

Question: how SKUA and LOON behave once the war's cost is only the 5% ARPU cut, churn uplift and SAC.
Arms: `head` = HEAD 48cbb959 (penalty in), from a `git archive HEAD` tree with a vendor symlink at
`base/0a85c411-71bb497c-s4-y20-t360/head-0a85c411/`, run with its own `var/harness/new_firms/run.sh`; `r3v3` = working
tree. HEALTH=1, seeds 1-4, 20y, 360 ticks/yr. `python3 -I compare_r3_pricewar.py head r3v3 1 2 3 4`.
r3v2 is NOT a valid baseline for this: it predates 48cbb959 (BOARD_OPENING_CAP_USD 19.4T, not 20.3T) and diverges
from r3v3 at quarter 3. head and r3v3 are identical up to each seed's first war onset, so the first war pairs exactly.
Table: `round3v3_4seed_20y.out`.

- First war, paired: SKUA margin +8.4 pts (se 1.1), LOON +8.3 (se 0.3), n=4. The penalty passes through 1:1.
- War cost left (first war, r3v3 minus the 4 quarters before): SKUA 0 to -5 pts, LOON -3.6 to -6 pts.
- Pooled war-minus-peace mean margin: SKUA -10.8 -> -3.0 pts, LOON -13.8 -> -3.7 pts.
- SKUA distress is mostly a peace-time story: seed 3 collapses from y1 (margin goes negative with no war) in both
  arms. Seed 2 head defaults (14 qtrs over covenant); r3v3 does not (4 qtrs). Totals over 4 seeds: covenant-qtrs
  23 -> 21, shut-outs 10 -> 12, emergency raises 3 -> 0, default lines 5 -> 4. Within noise after the paths split.
- LOON: no covenant quarters (5 -> 0), min ICR 1.65 -> 2.26, no defaults in either arm.
- Wars 8 -> 6 onsets, war share 0.072 -> 0.116: the draw is unchanged, but the paths split at the first war, so later
  onsets are new draws (Poisson noise, not a mechanism change).
- Board: TIER fails in r3v3 seed 2 only (after the split at q20); other firms mean |d median om| 0.003, max FNCH 0.027.
Caveat: after the first war the arms do not pair; 4 seeds cannot size distress-rate changes.
Plant sales: the harness does not capture DIVESTITURE lines; the table counts PP&E drops >10% a quarter as a proxy.

## 2026-10-16 round 3 v4: SKUA seed volatility 0.24 -> 0.18; plant-sale cascades, 8 seeds x 20y

Question: (1) what 0.18 does to SKUA's subscriber noise, distress and stock vol; (2) are divestiture cascades
cyclical troughs or structural decline. Arms: `r3v3` (penalty off, vol 0.24; seeds 5-8 from `base/r3v3-tree/`),
`r3v4` (working tree, vol 0.18), `head` 1-4 for reference. HEALTH=1, POLITICS off, 20y, 360 ticks/yr.
`python3 -I analyse_r3v4.py`; table `round3v4_8seed_20y.out`. Arms unpaired after divergence: se from both variances.

SKUA r3v3 -> r3v4 (means over 8 seeds): gross-adds noise sd 0.408 -> 0.266 (t -5.6), sub index sd 0.078 -> 0.050
(t -4.0), min 0.81 -> 0.91, op margin p50 0.116 -> 0.168 (t 2.6), loss quarters 16% -> 1%, peak ND/EBITDA 5.9 -> 3.4,
qtrs > 4.5x 26 -> 1, reorgs 3 -> 0, shut-outs 17 -> 2, plant-sale quarters 19 -> 0, realized vol median 0.25 -> 0.21
(r3v3 mean 0.56 from seed 3's collapse, 2.37). LOON: margin p50 0.212 -> 0.241 (t 3.8), its own noise unchanged.
Noise e = (s_t - s_{t-1}(1-churn_t)) / (s_{t-1} baseChurn) - 1, peace quarters; includes rival drain and price shift.

Cascades (plant < 40% of pre-drop within 3y): 24 episodes, 19 firm-runs, in 20 runs (head 8/4 runs, r3v3 9/8,
r3v4 7/8): SKUA 7 (all seed 3, head and r3v3 chained), FALC 5, CASC 5, DIPR 3, one each SINK, ALBT, FNCH, WADE.
Going in: op margin thin (median ~0.03, 9 of 24 negative), ND/EBITDA mostly 5-15, 3-4 loss quarters of 4. For the
commodity firms (CASC crack, DIPR power, SINK crude, ALBT freight, FNCH gold; |rho| 0.8-0.9) the index sat at
0.5-0.9 of its run median and was back at 0.7-1.7 by y19-20: cyclical troughs. Plant stays within 0.1 of the trough
in 20 of 24 (recovered: FALC r3v3-4, ALBT r3v3-6, CASC r3v4-4, SINK r3v4-6). FALC, SKUA, WADE have no commodity
driver (|rho| < 0.6). WADE (regulated utility, r3v4-1) to 0.04 in 6 sales is the outlier to check.
