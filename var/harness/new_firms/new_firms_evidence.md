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
