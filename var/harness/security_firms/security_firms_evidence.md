# Security-family firms evidence

## 2026-10-27: do NCKR, WALL, NJAR behave sensibly? (uncommitted seed data on main at 4a409112)

Question: sanity check of three new firms over a long whole-board run (no explosions, readable cycles, plausible
magnitudes), with WATCH and OSPR as comparators.
Harness: `SecurityFirmsHarnessTest.php` via `run.sh live <seed>` (RUN.md). Live macro, no Diet, seeds 1-4, 30 years,
360 ticks/yr. Table: `live_4seed_30y.out`.

Results (range across 4 seeds):
- No deaths, defaults or reorganisations for any of the five. Worst rating: NCKR BB-BBB, WALL BB (all seeds, from
  BBB), NJAR A, WATCH A/BBB, OSPR BBB.
- Opening P/E vs last-10y median: NCKR 17.0 vs 10.5-17.9; WALL 15.1 vs 13.4-17.3; NJAR 23.8 vs 17.3-25.0; WATCH 23.7
  vs 16.6-26.5. Opening P/B NCKR 3.1, WALL 1.5, NJAR 5.7.
- Op margin mean: NCKR 9.6-14.7% (min 1.2-7.7%), WALL 15.9-19.0% (min 4.6-10.8%), NJAR 17.4-18.1% (min 5.6-8.3%).
  Loss quarters: NCKR 0-1.7%, others 0 (OSPR 0-1.7%).
- Realised/configured vol 0.97-1.11 for all five; board median 0.98-1.08. Not worse than the board.
- Weekly beta to the cap-weighted index exceeds the configured beta for all five: NCKR 0.99-1.06 (cfg 0.71), WALL
  0.81-0.90 (0.56), NJAR 0.78-0.87 (0.66), WATCH 0.68-0.72 (0.56), OSPR 0.72-0.78 (0.56). NCKR and WALL most.
- Max drawdown of market cap: NCKR 57-78%, WALL 59-69%, NJAR 61-73%, WATCH 40-56%, OSPR 53-73%; board index 45-52%.
- Events per 30 years: cash_heist NCKR 0-3 (mean 1.0, expected 1.2); WALL geopolitical_conflict 0 in every seed
  (expeditionary weight stays 0.00); NJAR conflicts 0-2, breaches 0-1.
- NCKR cash_services share 75.1% (y1) -> 74.4% (y27): no drift. The -2%/yr cash-use drift enters only
  `getSecularGrowthRate` (whole-firm plan growth, 4.5%/yr vs NJAR 5.6%); the line weights are fixed 0.75/0.25, so the
  mix moves only with gold.
- NCKR corr(cash_volume_index, interbank spread over trend) 0.40-0.72; with domestic demand gap 0.61-0.95. The index
  spans only 0.99-1.02 (p5-p95). Vault: corr(vault_value_index, gold) 1.00; detrended elasticity of vault revenue to
  gold 0.46-0.93 (design 0.5 at gold = baseline).
- NCKR margin trough: seed 4 years 6-12 at 1.4-5% with flat revenue (~10bn/q) while WATCH ran 11-17%; same-period
  dips in seed 3. Not heists (one each); low-margin base under the shared wage-cost drag.

Caveats: 4 seeds (sensibility check); betas compare against `getBeta()` at seed, which may be pre-leverage.

## 2026-10-27 (b): seven-line security model, WALL correctional model, revenue-mix overlap rule (uncommitted on main at 4a409112)

Questions: (A) do NCKR, WALL, NJAR, WATCH, OSPR behave sensibly after the rework; (B) what does the overlap rule in
`IndustryShareLedger::mixOverlap()` change, against `mixOverlap() = 1.0` (old rule).
Harness: `SecurityFirmsHarnessTest.php` via `run.sh overlap|full <seed>` (RUN.md); `full` loads the patched ledger
copy in `ovr_full/` through `OVR`. Live macro, no Diet, 30 years, 360 ticks/yr. A = overlap seeds 1-4
(`live_4seed_30y_v2.out`); B = 16 seeds per arm, paired (`overlap_16seed_30y.out`; 8-seed stage in
`overlap_8seed_30y.out`, went to 16 because mean growth outside security had t -1.5).

A (range over 4 seeds; before = `live_4seed_30y.out`):
- No deaths/defaults/reorgs/distress quarters for the five. Worst rating NCKR BB, WALL BB, NJAR BBB-A, WATCH BBB-A,
  OSPR BBB. Board: seed 4 FALC died (y29), 14 defaults, 1 reorg.
- Open P/E: NCKR 18.3 (was 17.0), WALL 14.9 (15.1), NJAR 22.8 (23.8), WATCH 23.6, OSPR 22.9. Last-10y median P/E:
  NCKR 16.9-45.5 (seed 2: om last 5y 6.2%), WALL 11.3-15.5, NJAR 16.4-31.5, WATCH 18.9-40.2, OSPR 17.0-34.0.
- Rev growth/yr: NCKR 2.5-4.8%, WALL 3.7-5.4%, NJAR 4.0-9.0%, WATCH 4.5-6.3%, OSPR 4.4-6.1%.
- Op margin mean (min..max): NCKR 9.1-11.6% (-1.1..18.8), WALL 17.8-19.7% (7.2..28.2), NJAR 15.5-18.5% (-2.6..25.8),
  WATCH 14.6-17.3% (1.9..23.5), OSPR 19.4-22.5% (-7.3..38.3).
- rv/configured 0.93-1.12 for all five; board median 0.99-1.07. Weekly beta above configured for all: NCKR 0.92-1.05
  (0.71), WALL 0.82-0.88 (0.56), NJAR 0.78-0.97 (0.66), WATCH 0.69-0.75 (0.56), OSPR 0.71-0.85 (0.56).
- MaxDD: NCKR 52-71%, WALL 50-63%, NJAR 55-77%, WATCH 43-63%, OSPR 58-65%.
- Events/30y: NJAR geopolitical_conflict 0 in all seeds (was 0-2). WALL prison_disturbance 0-2. NJAR leak 0-2.
  WATCH (5% cash, 5% investigations) cash_heist 0-4 and leak 0-2, as often as NCKR (heist 0-3) and NJAR: the event
  hazard is not scaled by line weight (`$has()` gates only), so a 5% sideline headlines like the core business.
- NCKR cash share y1/10/20/30: 75.2/74.2/70.6/68.1% mean (y30 range 65.1-70.8%); expected ~72/71/71.
- WALL occupancy 0.911-0.985; 0% of quarters at the 85% floor or 100% cap (floor needs an unemployment gap of
  about -3.5pp, cap +4pp; the gap ran -0.7..+4.3pp). Concession index 0.997-1.011 over 30 years (gov spending index
  96.6-105.6, 10-year lag): inert. corr(occupancy, ugap lag 6q) 0.88-0.96; corr(custody detrended, ugap) 0.17-0.51,
  lagged 6q 0.30-0.65.

B (16 seeds paired, diff = overlap - full):
- Security firms: rev growth NCKR +0.49pp (se 0.45), NJAR +0.39 (0.32), WATCH +0.31 (0.24), OSPR -0.06 (0.36),
  WALL +0.28 (0.26); margins all |t|<1.7. Mean |drain| bp/q: NCKR 219->29, NJAR 225->61, WATCH 218->85, OSPR 25->11
  (all |t|>8).
- Outside security (37 firms, 16 industries): median per-firm growth diff -0.05pp/yr, mean -0.1pp (se 0.1); 1 of 37
  at |t|>=2 (chance 1.9). Mean |drain| 96.3->83.2 bp/q (t -6.6): Restaurants 88->34, Marine 104->60, A&D 139->85.
- Board: deaths 0.56->0.12 per run (t -2.4; FALC 5 of 16 vs 2, TERN 3 vs 0), defaults 9.8->7.8 (t -0.8), reorgs
  0.6->0.9 (t 1.2), strict distress lines 115->118 (t 0.7).
Caveats: deaths are rare counts with a multiple-comparison look; TERN is alone in its industry, so its deaths move
through the shared firm stream, not the rule directly. Event counts are lore-regex matches.
