# Board scorecard

Question: does the whole board behave like a real economy's listed market? One harness scores a run against standards
that real economies span, so a change is judged on the board, not on one firm.

- Harness: `ScorecardHarnessTest.php` (extends `lib/BoardRun.php`; read-only). The plain `OUT` record (every firm's
  quarter rows, deaths, reorganisations, defaults) plus each firm's industry, sector and model, and each quarter's
  nominal GDP index and board cap (`macro`); GDP in dollars is `MacroConstants::DISTRICT_GDP_USD` times the index's
  growth.
- Run: `bin/harness-batch var/harness/scorecard/run.sh 1-8 <arm>` (env YEARS 20, TPY 360; live macro, no Diet).
- Score: `python3 scorecard.py <arm> [<arm_b>]` from this directory. A `!` marks a mean outside the range; with two arms,
  the last column is the paired difference and its t.
- Ranges are deliberately wide (what a real economy could show, not one country's moments). Edit STANDARDS to tighten.

## Results 10-30 (8 seeds x 20y)
- `base` (d7c22c3c): in range except: largest firm revenue/GDP 0.16 (CASC, a refiner: crude pass-through, a hub's
  refiner can exceed this, so the standard misreads it); margin sd >10pp in 8 firms (SINK, FNCH, CNDR, ALBT, DIPR:
  E&P, gold, copper, shipping, IPP, whose margins swing like that in real cycles); P/B >10 or <0.3 in 3.4% of
  firm-quarters (JDAW P/B >10 for half the run on ROE 25-40%; TERN, FALC <0.3).
- Drawdowns must be split-adjusted (70 splits a run); unadjusted, 32 firms "fell 80%". Adjusted: 6.1 firms, mostly TERN,
  FALC, ALBT (airline, autos, shipping: real ones show -80..-95% over 20y).
- Named suspects: SAFE revenue/GDP 9.2-9.8% -> 7.6-10.0% (stable, not a problem); GULL peak goodwill/equity 0.13-0.62
  (the live spree did not recur); HONY margin sd 4-7.5pp, range e.g. 2..26% (wide for a broker, below the 10pp flag).
- Found: the levered beta reads the model's own fair equity (`DebtEngine`, `$leveredEquity`). In a trough TERN's fair
  D/E went 0.6 -> 20-50, the 2.5 cap bound, cost of equity 20-22% for 2.5y while the policy rate fell to 0.2%, fair
  value $1 on $40 book. Arms (built on `nofloor`, so compare to it): `bookde` (lever on book equity) and `maxde` (on max(book, fair)); seed 8 first trough
  -94% -> -71% with the loop cut. Board, vs nofloor: bookde opening cap -6% (t -17), P/B extremes 3.8% -> 2.3% (t -4.2),
  defaulters -0.6 (t -2.4), >80% drawdowns unchanged; maxde changes little board-wide.
- `nofloor` (cost of equity not floored at the borrowing rate): small board effect; reverted, the floor is the
  absolute-priority rule pinned by DebtEngineTest (negative-beta firms). A junk issuer's floor could read the debt's
  expected return (yield less expected loss) instead of its promised yield; not built.
- Arm `book` (beta levered on book equity, floor kept), vs base: P/B extremes 3.4% -> 2.1% (t -7.6), >80% drawdowns
  6.1 -> 4.4, opening cap/GDP 3.88 -> 3.64 (REITs -15%, staples -13%, utilities -11%). Rejected: Damodaran's asset
  betas are unlevered at market D/E, so re-levering at book overstated every levered firm above book.
- Arm `biz` (levered on the business at its asset beta less (1 - t) net debt, Valuation::operatingCapitalValue):
  opening values within +-6% by sector, but TERN still hit 20% at CCC through the floor at its promised yield.
- Shipped 10-30, arm `bizel` = biz + floor at the debt's expected return (yield less CreditRisk::expectedDefaultLossRate
  on the Merton 5y distance to default), vs base: >80% drawdowns 6.1 -> 4.8 (t -1.9), defaulters 1.1 -> 0.6 (t -1.5),
  firms whose share moved x3 1.0 -> 0.4 (t -2.4), opening cap/GDP 3.88 -> 3.88, P/B extremes 3.4% -> 3.7% (t +1.2),
  median margin sd 0.049 -> 0.053 (t +4.0). TERN worst falls -58..-97% -> -61..-93%; cost of equity 9-11.5% through
  a trough where it was 20-22%.
- 10-30 GULL live read (6 debt-funded deals in 7.9y, price 135 -> 70). Two changes, arm `accr` vs `head` (2336e876;
  BoardRun now records every "executed a $" deal, so deal counts before this line undercount):
  - Resort seasonality per line (ResortsCasinosBusinessModel::resolveSeasonalityFactors): base rent no longer
    follows the casino season. GULL's quarter-on-quarter revenue sd 0.167 -> 0.076.
  - Accretion screen on borrowed deals (AcquisitionEngine::addsToEarningsPerShare): barely bites. Debt-funded
    deals 884 -> 810 over 8 runs; all deals 188 -> 183 a run (t -0.5); GULL 9.5 -> 8.8 deals a run. Board
    unchanged within noise: reorganisations 0.5 -> 0.2 (t -1.5), defaulters 0.6 -> 0.4 (t -1.0). Why: a target
    earns ~6.4% after tax on its price (ROIC 8% / 1.25 hubris) against ~4.3% after-tax interest.
  - Board: 188 deals a run (0.16 per firm-year). Every empire builder does 5+ in most seeds (HUMM, SILC, WREN,
    TRIV, GULL, ...). Succession does not fire GULL's manager because its EVA spread is strongly positive.
- 10-30 live TERN was liquidated (Chapter 7) at t 14.1 with $42bn book equity, after four loss quarters in a fuel
  spike that reversed within a year. The viability test read trailing EBITDA; it now reads through-the-cycle EBITDA
  (getNormalEbitda: long-run return on capital + depreciation; TERN's was +6.3%), falling back to trailing before a
  long-run return exists. Arm `viab` vs `accr`: identical (no seed reached the liquidation branch; failures are rare).
- 10-30 P/B extremes: the >10x firm-quarters are JDAW, staples, tobacco, restaurants, exchanges on ROE 27-53%
  (P/B = ROE x P/E). The standard now counts only extremes ROE does not explain (>10x on ROE <20%, <0.3x on ROE
  >10%): 0.2% of firm-quarters.
- 10-30 goodwill: BoardRun rows carry `gwi` (impairment). The test read the model's fair value only, which sat far below
  the market at impairments (HUMM 191 vs 417bn cap, a $193bn write-off). It now reads the higher of the model's value
  and the quoted equity. Arm `gwm` vs `gwi` (4 seeds): impairments 52 -> 39, written $730bn -> $276bn, none while
  market P/B > 1 (was 27%). Most are full because goodwill is 1-5% of equity (ASU 2017-04 arithmetic).
- 10-30 revenue noise. Live: the median firm's deseasonalised quarterly revenue reversed (lag-1 autocorrelation -0.32,
  variance ratio 0.51). Arms: `rbase` = BASE tree dd940779; `rnoise2` = working tree with
  - demand lines at a shared persistence (StreamContext::generateDemandZ, mean life 3.5y);
  - the engine's own timing noise at a third (IDIOSYNCRATIC_REV_VOL_RATIO 0.08);
  - capacity read at normal working capital on the trailing year's revenue (OperatingCapacityEngine::operatingCapital).
  `rnoise2u` = rnoise2 with OVR EarningsEngine scaling the noise by E/(D+E). `rnoise` was a first cut whose normal
  working capital used the run-rate revenue, which moved with the quarter and so did nothing outside seasonality.
  `python3 revenue_noise.py rbase rnoise2 rnoise2u` (revenue_noise_8s.out): rnoise2 vs rbase, median sd q/q
  0.059 -> 0.039, AC1 -0.30 -> -0.16, VR 0.50 -> 0.75, sd y/y 0.088 -> 0.072, growth 0.042 unchanged, drop-and-bounce
  quarters 4.0 -> 1.9 per 100 firm-years; scorecard unchanged or better (reorganisations 0.8 -> 0.2, t -2.6). The
  unlevered scale (rnoise2u) cut y/y sd to 0.063 for little more persistence; not taken. BoardRun rows carry `cgap` and
  `spare` from here; spare plant above 10% stays at 0 in every arm.

## Moat ladder 11-19 (4 seeds x 20y, TPY 360)
- Question: does the board stay sensible with the five-step moat ladder (Moat, IndustryMoats; banks' opening books from
  a character row at cost of equity + step), and what moved? Arms: `base` = e1b30b23 as a BASE tree (git archive in
  the session scratchpad, vendor symlinked; src identical to d02ec453), `moat` = working tree. Run:
  `MOAT_BASE=<tree> bin/harness-batch var/harness/scorecard/run_moat.sh 1-4 base moat` (165 s wall), then
  `python3 scorecard.py base moat; python3 moat_extras.py base moat; python3 moat_firms.py base moat` ->
  `moat_ladder_4s.out`. ROIC in the extras is a proxy (4 x EBIT x 0.75 / (equity + debt - cash)).
- Out of range in both arms, unchanged: largest revenue/GDP 0.16 -> 0.15 (CASC), margin sd >10pp 5.5 -> 5.8,
  acquisitions 181 -> 184, 5+ deals 13.5 -> 11.8. Nothing newly out of range.
- Moved (t): opening cap/GDP 3.91 -> 3.82 (-7.0); LAKE P/B open 3.18 -> 2.66, run median 2.79 -> 2.31 (-5.5), LAKE
  run ROE 18.0% -> 14.9% (-8.1); RIVR P/B median 2.10 -> 1.66 (-4.6); BNTH open 2.36 -> 2.50 (+4.9); PLVR, EGRT
  open -0.17, -0.13 (median within noise); peak goodwill/equity p90 0.18 -> 0.14 (-2.9).
- Not moved: median firm ROIC proxy 14.5% both (y20 14.2 -> 14.5, t +0.6), ops ROE 21.5 -> 21.7, fin ROE 18.4 both,
  P/B median 3.50, P/E median 17.8 -> 17.9. The settle harness's +2pp at y10 does not show on the market board; per
  firm the move is -2..+2pp (p10..p90) with median -0.1pp.
- Noise: >80% drawdowns 7.0 -> 9.8 (+1.8; SKUA -0.42 -> -0.72, the largest return fall -3.7pp, SHOR, PTRL), defaulters
  0.2 -> 0.5, deaths 0.2 -> 0. An 8-16 seed stage would settle the drawdown count.
- 16 seeds (11-19, moat = 12eb81f7, base tree rebuilt; seeds 1-4 reproduce exactly; 32 runs, 539 s wall):
  `... run_moat.sh 1-16 base moat`, plus `python3 moat_dd.py base moat` (paired counts, per-firm attribution) ->
  `moat_ladder_16s.out`. >80% drawdowns 5.94 -> 7.25, +1.31 (se 0.79) t +1.7: heavy-tailed (s16 33/29, s15 7/16),
  scattered across ~35 firms (WING +4, SKUA +3 net seeds; SKUA worst dd -0.57 -> -0.66, t -1.1, deeper in 8/16).
  Defaulters 0.94 both (t 0), deaths 0.06 -> 0. Margin sd >10pp 5.0 -> 5.6 (t +2.3, both already out of range).
  Not settled at 16; 48 seeds would be the last stage (64 more runs, ~18 min).


## Payout policy 11-19 (4 seeds x 20y, TPY 360)
- Question: is the board sensible with annual PayoutPolicy steps (retain/low/balanced/income/harvest, reviewed at fiscal
  year end by CapitalAllocationEngine::reviewPayoutPolicy), and how often do firms move? Arms: `pbase` = dfef1d97 as a
  BASE tree (git archive in the session scratchpad, vendor symlinked), `pay` = working tree. Harness
  `PayoutHarnessTest.php` (scorecard record + `tpr`/`dspd` per quarter row, `tpr0` in meta, "dividend policy" event
  lines in `pol`). Run: `PAY_BASE=<tree> bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pbase pay` (140 s
  wall), then `python3 payout.py pbase pay; python3 scorecard.py pbase pay` -> `payout_policy_4s.out`.
- Policy moves are rare: 4.5 raises + 1.25 cuts a run, 0.028 changes per firm per decade; 100.8 of 103 firms never
  move. Only SKYL (retain -> income by y17 in 3/4 seeds), SAFE (balanced <-> income), DOVE, SILC move. Cause: the
  implied ratio is max(base, base + (0.85 - base) x severity) (CorporateMetrics:466), so it never falls below the
  opening step, and severity is near 0 for nearly every firm. A cut is only ever a return to base.
- Flip-flops within 3y: 1.25 a run (SAFE 3, SKYL 2 over 4 seeds). Harvest: 14 at y0, 14 at y20 (no firm reaches it).
- Board dividends (pay - pbase, paired): median payout of payers 0.367 -> 0.360 (t -1.5), median yield 2.1% -> 2.0%
  (t -2.9), DPS falls >10% 116 -> 101 a run (t -4.7), >0.5% 568 -> 528 (t -3.4), non-payers 2.3 -> 2.5 (t 0.3).
- Scorecard: nothing newly out of range; out in both (CASC revenue/GDP, margin sd, deals). Shares x3 0.8 -> 0 (t -3.0).

## Payout pay2 11-19 (4 seeds x 20y, TPY 360)
- Question: annual review on measured reinvestment (3y EMAs, reference frozen at 12 quarters, shift 0.5 x (ref - share))
  plus the `carry:year_end_dividend` fix. Arm `pay2` = working tree; rows now carry `ref`/`rinv`/`earn`/`rq`/`yed`
  (HEALTH=1 adds `icr`/`head`), meta `st0`. `pay` rows lack these and its streaks are invalid (wiped key).
- Run: `HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pay2` (131 s wall), then from this directory
  `python3 pay2.py pay2` (policy, reinvestment share, roll), `python3 payout.py pbase pay2`, `python3 scorecard.py pbase
  pay2` -> `payout_pay2_4s.out`.
- Policy: 28.8 of 103 firms move (pay 2.3); 34 raises + 34 cuts a run (pay 4.5 + 1.25); 18 changes in y3-10, 50 in
  y10-20. >=3 changes in 7 firms a run: REITs ELDE (9) and SHOR (8), LOON, SKYL, TERN, CROP. Harvest 14 -> 12.5,
  retain 1 -> 3.5. Share is noisy and unbounded: REIT and telecom shares run 1-2.6 over build cycles.
- Share p10/p50/p90 (operating, n 328): ref .11/.26/.56, final .11/.24/.62, shift -.11/.00/+.09; financials ~0.
- Roll among payers y0/5/10/20: listed 10.7/29/55/39%, champion+king 10.7/8.4/6.4/1.8%. Token 1% raise kept 218 of
  664 listed streak years a run (33%). Breaks 9.4/y, joins 7.0/y. Div > TTM NI 4q while listed 3/run (IBIS, GRIP,
  WELL); crisis/covenant while listed 4.3 firm-quarters/run.
- vs pbase (paired): DPS falls >10% 116.5 -> 136.8 (t 2.8), >0.5% 568 -> 678 (t 6.8; 128 of them within 1y of a
  token raise), payout 0.367 -> 0.393 (t 4.9). Only 8 of 137 large falls follow a policy cut. Scorecard: nothing newly
  out of range; largest revenue/GDP 0.15 -> 0.18 (t 2.5, CASC, out in both).

### pay3 (same seeds, the three fixes: share clamped [0,1] and 0 for pass-throughs, two same-way reviews per step,
token raise only when covered by quarterly EPS)
- Run: `HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pay3`, then `python3 pay2.py pay3`,
  `python3 payout.py pbase pay3`, `python3 payout.py pay2 pay3`, `python3 cuts.py pay3 pbase pay2 pay3` (large falls
  split by opening champions, st0 >= 25), `python3 scorecard.py pbase pay3` -> `payout_pay3_4s.out`.
- Policy (pay2 -> pay3): moved 28.8 -> 21.3, raises 34 -> 18.8, cuts 34 -> 17.5; >=3 changes 29 -> 12 firm-runs
  (max LOON 4; ELDE, SHOR quiet). Harvest 14 -> 14, retain 1 -> 3.75.
- Roll, share of payers y5/10/20 (all four tiers): 24.8/50.1/47.5%; champion+king 7.2/4.7/1.0%. Token raises still
  207 of 639 listed streak years (32%). Div > TTM NI 4q on the roll 3 -> 0 a run.
- vs pbase: DPS falls >10% 116.5 -> 104.3 (t -2.3), >0.5% 568 -> 588 (t 3.3), median payout 0.367 -> 0.373 (t 1.2).
  The pay2 rise in large falls was the 11 seeded champions: 0.75 (pbase) -> 12.5 (pay2) -> 3.25 (pay3) a run.
- Scorecard: STRK (credit services) fails in seed 1 at y13.4; distressed and paying nothing from y11 in every arm.

### pay4 (pay3 + split restates `carry:year_end_dividend`, CapitalAllocationEngine::restateDividendRecordForSplit)
- Run: `HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pay4`, then `python3 champs.py pay3 pay4`
  (seeded champions: survival of the unbroken opening record, each on-roll break with cause), `python3 pay2.py pay4`,
  `python3 payout.py pbase pay4`, `python3 payout.py pay3 pay4`, `python3 cuts.py pay4 pbase pay3 pay4`,
  `python3 scorecard.py pbase pay4` -> `payout_pay4_4s.out`.
- Seeded champions (44 firm-runs) unbroken y5/10/20: pay3 66/41/9% (11.3%/y) -> pay4 91/82/73% (1.6%/y). On-roll
  breaks 49 -> 14: cut with no crisis/covenant flag 7 (DOVE, WELL, MAGP, GRIP), covenant cut 3 (GRIP y2-3, PINT),
  flat and uncovered 3 (GRIP, SWFT, WELL), flat but covered 1 (PINT s3 y10).
- Roll, share of payers y5/10/20: 35.5/62.5/73.1% (all firms 33.7/59.0/70.6%); champion+king 10.2/9.3/8.0%.
  Token 195 of 893 streak years (22%). Breaks 6.6/y, joins 7.8/y. Div > TTM NI 4q on the roll: 0.
- vs pbase: DPS falls >10% 116.5 -> 87.0 (t -4.9), >0.5% 568 -> 540 (t -3.7), payout 0.367 -> 0.354 (t -1.9).
  Opening champions' >10% falls 0.75 -> 1.25 (t 0.8). Scorecard: nothing newly out of range; vs pay3, board cap/GDP
  at end 4.04 -> 4.20 (t 7.6, inside 1-5).

### pay5 (pay4 = dc0aeb41 + token raise only for Contenders and up, DividendTier::protectsRecord())
- Run: `HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pay5`, then `python3 pay2.py pay5` (now also
  roll exits/entries, the y15-20 window, payers raising Q4 DPS y/y), `python3 champs.py pay4 pay5`, `python3 payout.py
  pbase pay5`, `python3 cuts.py pay5 pbase pay4 pay5`, `python3 scorecard.py pbase pay5` -> `payout_pay5_4s.out`.
- Roll share of payers y5/10/15/20: 35.5/52.1/56.7/64.9% (pay4 35.5/62.5/65.6/73.1%; y20 -8.2pp (1.9), t -4.3; seeds
  53-71%). All firms 33.7/48.5/54.6/62.9%. Champion+king 10.2/9.6/8.3/7.8%.
- Flows per year, all / y15-20: roll entries 4.96/4.45, exits (streak >= 5 broken) 2.26/2.75 (pay4 4.60/3.50,
  1.50/1.85). Net +1.7/y late, so the roll still grows. Token 13% of streak years (pay4 22%).
- Seeded champions unbroken y5/10/20: 91/84/70% (1.7%/y). Payers raising Q4 DPS y/y: 85% (pay4 86%); 89% in y15-20.
- vs pbase: DPS falls >10% 116.5 -> 97.3 (t -4.3), >0.5% 568 -> 525 (t -3.9), payout 0.367 -> 0.351 (t -2.5).
  vs pay4: +10.3 (t 1.1), -15.3 (t -2.4), -0.003 (t -1.7). Scorecard: nothing newly out of range.

### pay6 (pay5 + sticky dividends: smoothing payers raise only at fiscal year end by the annual Lintner step, held
flat under MIN_ANNOUNCED_DIVIDEND_RAISE 3%; cuts any quarter)
- Run: `HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pay6`, then `python3 pay2.py pay6`,
  `python3 champs.py pay6`, `python3 sticky.py pbase pay5 pay6` (new: raise/flat/fall shares of payer firm-years Q4 on
  Q4, raise sizes, roll y20, token share, cash/assets), `python3 payout.py pbase pay6`, `python3 cuts.py pay6 pbase pay5
  pay6`, `python3 scorecard.py pbase pay6` -> `payout_pay6_4s.out`.
- Roll % of payers y5/10/15/20: 11.7/25.0/29.7/37.3% (pay5 35.5/52.1/56.7/64.9%; y20 -27.6pp, t -4.5). Entries vs
  exits y15-20: 4.85 vs 3.20 a year, still growing. Champion+king 9.9/8.6/8.1/7.6%. Token 21% of streak years.
- Payer firm-years raising 78% (pay5 85%), flat 5.6%, falling 16.3%; median raise 8.0%. Cash/assets unchanged (5.1%).
- DPS falls >0.5% 525 -> 1012 a run (t 12.5): 658 are 0.5-2% and 443 come the quarter after a raise. A year-end step
  followed by a mid-year trim (raises wait for year end, cuts do not); each trim breaks the streak. >10% falls 102
  (pay5 97, t 0.4). Scorecard: CCC firm-quarters 0.001 -> 0.003 (t 3.3, inside range); nothing newly out of range.

### pay7 (pay6 with MIN_ANNOUNCED_DIVIDEND_RAISE 2%) and the declared-DPS measure
- Measure fix: `lib/BoardRun.php` rows carry `dps` = Stock::getLastDividend() (the declared dividend per share; `div`
  is that times the shares before the quarter's buybacks). `payout.dps_adj()` split-adjusts it and falls back to
  div/shares for runs without it; pay2/sticky/cuts/payout use it. BoardRun tier getters are method_exists-guarded so
  the dfef1d97 tree runs. Re-run on the new measure: `pbase2` (= pbase, reproduces it exactly; `PAY_BASE=<git archive
  dfef1d97>`, arms named pbase* use the BASE tree) and `pay6r` (= pay6 via `OVR=<dir>` holding
  CapitalAllocationEngine.php with 0.03; reproduces pay6). pay1-pay5 lack `dps`; their DPS-fall and raise/flat
  figures above are on div/shares and are not comparable.
- Run: `HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pay7`, then `python3 pay2.py pay7`,
  `python3 champs.py pay6r pay7`, `python3 sticky.py pbase2 pay6r pay7`, `python3 payout.py pbase2 pay7`, `python3
  cuts.py pay7 pbase2 pay6r pay7`, `python3 scorecard.py pbase2 pay7` -> `payout_pay7_4s.out`.
- Roll % payers y5/10/15/20: 15.2/31.6/38.2/40.8% (pay6r 11.7/25.0/29.7/37.3%; y20 +3.3pp (4.6), t 0.7).
  Entries vs exits y15-20: 4.40 vs 3.75 a year (pay6r 4.85 vs 3.20). Year-end breaks 207 vs mid-year 27 a run.
- Declared DPS, payer-years raise/flat/fall: pbase2 77/18/5%, pay6r 65/30/5%, pay7 68/27/4.5%; median raise 9.3%.
  DPS falls >0.5% a run: pbase2 260, pay6r 285, pay7 271; >10%: 117, 101, 91 (pay7-pbase2 t -2.4).
- Scorecard vs pbase2: nothing newly out of range; cash/assets 5.0% (unchanged), cap/GDP at end 4.23.

### pay8 (8f3dd4a5 + token raise only if last year-end x 1.01 <= policy target dividend; 60 years)
- Run: `YEARS=60 HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pay8` (400 s wall), then
  `python3 longrun.py pay8 10` (survival by streak band at fiscal year ends, implied stationary shares, actual shares,
  10+ breaks against the year), `python3 sticky.py pay8`, `python3 scorecard.py pay8` -> `payout_pay8_60y.out`.
- P(continue) years 10-60: 1 .751, 2 .747, 3 .725, 4 .734, 5-9 .793, 10-24 .913, 25-49 .972, 50+ .977 (n 173-2733);
  P(streak >= 1 | payer) .683. Implied stationary share of payers >=5/10/25/50: 34.3/21.9/11.9/6.4%; actual y60
  34.9/18.1/10.4/4.3% (y40 34.7/16.7/7.4/0.3%). pay7 (20y, same script) had 10-24 at .987, implying 36% >=25.
- 10+ breaks 1.12 a seed-year; worst output-gap fifth holds 24.9% of them (corr -0.16), worst nominal-growth fifth
  16.4%: barely cyclical.
- 60y board: payer-years raise/flat/fall 68/27/4.6%, median raise 10%, DPS falls >10% 284 a run (4.7/y), median
  payout .347. No deaths. Out of the 20y-calibrated ranges: deals, 5+ deal firms, share x3 7.2, drawdown >80% 14.5,
  LAKE and CASC above 15% of GDP in all seeds.

### pay9 (opening streaks from DividendTier::openingStreak, ranked by opening payout; 20 years)
- Run: `HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pay9`, then `python3 pay2.py pay9`,
  `python3 champs.py pay9`, `python3 longrun.py pay9 1`, `python3 sticky.py pbase2 pay7 pay9`, `python3 payout.py
  pbase2 pay9`, `python3 scorecard.py pbase2 pay9` -> `payout_pay9_4s.out`.
- Roll % payers y0/5/10/15/20: 31.1/8.0/24.1/26.4/34.1% (champion+king 7.8/1.6/1.0/1.3/1.0%). The opening roll
  collapses: of 128 opening records >= 5y (4 seeds), 87 break, 38 of them in years 1-2; 82 of the 87 are flat years with
  the dividend covered by EPS (high-payout firms at their policy target: Lintner step under 2%, token blocked by the
  policy-target test). Opening champions unbroken y5/10/20: 9/6/6%.
- Entries vs exits (streak >= 5 broken) a year: 4.38 vs 3.60; y15-20 5.70 vs 4.15. Token 4.4% of streak years.
- vs pbase2: DPS falls >10% 117 -> 99 (t -10.1), >0.5% 260 -> 288 (t 1.6), payout .367 -> .362; raise/flat/fall
  64.6/30.9/4.6%. Scorecard: nothing newly out of range (drawdown >80% 10.2 vs limit 10, t 0.3).

### pay10 (pay9 + opening dividend at Valuation::lintnerSteadyDividendShare x policy target)
- Run: `HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pay10`, then `python3 pay2.py pay10`,
  `python3 champs.py pay9 pay10`, `python3 longrun.py pay10 1`, `python3 opening.py pbase2 pay9 pay10` (new: raising
  y1-3, opening yield/cap), `python3 sticky.py pbase2 pay9 pay10`, `python3 payout.py pbase2 pay10`, `python3
  scorecard.py pbase2 pay10` -> `payout_pay10_4s.out`.
- Roll % payers y0/1/2/5/10/15/20: 31.1/28.5/24.6/24.5/32.9/30.8/33.8% (pay9 31.1/17.6/11.0/8.0/24.1/26.4/34.1%).
  Champion+king 7.8/4.5/3.5/4.1/3.6/4.7/3.6%; kings 2 -> 0 in quarter 1. Entries vs exits 4.15 vs 3.92 a year.
- 30 of 128 opening records >= 5y are lost in the first quarter (pay9 68): 14 REIT (SHOR, PLZA, ELDE, KITE), 5
  consulting. The first allocation pays less than the opening dividend, so the record ends at once.
- Opening champions unbroken y5/10/20: 25/16/9% (pay9 9/6/6%). Survival 10-24 .915 (n 789), 25-49 .919 (n 307).
- Raising y1/y2/y3: 4/66/62% (pbase2 73/68/63%); y1 is low by design (raises at year end). Median raise 5/6/7%.
- vs pbase2: DPS falls >10% 117 -> 95 (t -5.6), >0.5% 260 -> 276 (t 1.6), payout .367 -> .351 (t -5.5); raise/flat/
  fall 68.8/26.3/4.9%. Opening yield 2.2 -> 2.1% (t -10.3), opening cap/GDP 3.77 -> 3.76 (t -0.5). Scorecard: nothing
  newly out of range.

### pay11 (pay10, opening dividend = policy x steady Lintner share with no legal floor; ranking by policy payout)
- Run: `HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pay11`, then `python3 pay2.py pay11`,
  `python3 early.py pay10 pay11` (new: opening records lost by the first report / year 1 by model; REIT floor, rises
  and falls in y0-2), `python3 champs.py pay10 pay11`, `python3 opening.py pbase2 pay10 pay11`, `python3 sticky.py
  pbase2 pay10 pay11`, `python3 payout.py pbase2 pay11`, `python3 scorecard.py pbase2 pay11` -> `payout_pay11_4s.out`.
- Roll % payers y0/1/2/5/10/15/20: 31.1/30.8/25.9/23.0/28.8/29.9/33.6%; champion+king 7.8/6.7/4.0/3.3/2.6/3.9/4.1%.
- Opening records >= 5y lost by the first report 30 -> 17 (REIT 14 -> 1; consulting 5, restaurant, port, broker,
  life 2 each unchanged), by end of year 1 30 -> 21 (REIT 5). REIT quarters on the 0.9 x NI floor in y0-2: none.
- Opening champions (all on 0.75 policy: SHOR, PLZA, ELDE, KITE, LOON, WALL, CLAW, PHIL) unbroken y5/10/20: 16/12/6%;
  of 30 first breaks 24 are flat fiscal years in years 1-2 with the dividend at 0.6-0.9 of EPS/4, 5 cuts (ELDE at
  y0.6, 0.65 -> 0.43 in every seed), 1 in quarter 1. Survival 10-24 .910, 25-49 .898.
- vs pbase2: DPS falls >10% 117 -> 89 (t -3.1), >0.5% 260 -> 265 (t 0.2), payout .367 -> .358; opening yield 2.1%
  (t -10.3), cap/GDP at open 3.76 (t -0.6). Scorecard: drawdown >80% 11.0 (limit 10, pbase2 9.8, t 0.7).

### books1 (77 operating rows build their opening books: CAPM + moat return, leverage target, treasury-target cash)
- Arms: `pbase3` = 4b4dd689 as a BASE tree (git archive in the session scratchpad, vendor symlinked), `books1` =
  working tree. Run: `PAY_BASE=<tree> HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 pbase3 books1`
  (141 s wall), then `scorecard.py`, `payout.py`, `champs.py`, `opening.py`, `sticky.py` on `pbase3 books1`, `pay2.py`
  and `longrun.py books1 1`, and new `python3 books.py pbase3 books1 --list` -> `payout_books1_4s.out`.
- `books.py`: over `ops77.py` (rows stating `addressable_share`, non-financial = 77): cash/assets, net D/E at y0/1/5/20,
  share change by y5, covenant breaches y1-3 (ndE > covL), opening P/E, streak >= 25 breaks and cash vs a floor
  estimated from books1's first row (k x max(4 rev, eq); 76-82% of all quarters sit under 1.2x it, so weak).
- 77 books (books1 - pbase3, paired): cash/assets y0/1/5/20 .074/.063/.049/.047 -> .054/.053/.046/.044 (t -12.7, -7.9,
  -2.9, -1.6); net D/E .42/.44/.48/.43 -> .53/.51/.49/.48. Shares y5 median -6.7% -> -7.2% (t -1.0); firms issuing >2%
  16.8 -> 13.0 (t -3.4). Covenant breach firms y1-3 4.25 -> 5.0 (t 1.0); GRIP none in either arm.
- Dividends: DPS falls >10% 88.5 -> 90.3 (t 0.3), >0.5% 265 -> 285 (t 2.0), payout .358 -> .355. Roll y0/5/10/20
  31.1/23.4/29.7/35.2%, champion+king 7.8/3.1/1.8/2.3%. Opening champions unbroken y5 16 -> 25%, y10/20 12/6% both;
  breaks 44 -> 39 (cuts 17 -> 8). Streak >= 25 breaks among the 77: 4.75 -> 3.75 a run, LOON/WALL first breaks move
  from y1-3 to y5-10.
- Scorecard: acquisitions 169 -> 197 (t 3.3), 5+ deal firms 12 -> 15 (t 7.3), both out in both arms, spread after y5
  (SKUA, BNTH, SAFE, GULL +13..23; TICK -18). Margin sd >10pp 6.0 -> 4.2 (t -3.7). Opening cap/GDP 3.76 -> 3.69 (t
  -1.7), P/E (77) 18.9 -> 18.4 (t -3.5), yield 2.1% both.
- Streak >= 25 firms y5/10/15/20: -0.25/-0.75/-1.50/-1.75 a run (t -0.3/-1.6/-3.0/-1.0). Mostly SHRK (st0 20, 0.75
  policy): broken in s2/s3/s4 at y4/y10/y2 (pbase3 s1 only), each a flat year, covered (DPS 0.67-0.76 of EPS/4),
  cash at the treasury target (1.01x), headroom true, icr 30-40: the hold is funded by debt, the raise is not
  (CapitalAllocationEngine:303-308).

### raise1 (covered year-end raise borrowed like a held dividend, `$isCoveredRaise`) and lend1 (lender rows only)
- Arms: `books1` = HEAD b42f6757 (runs reused: no src file but the five now modified is newer than them); `lend1` =
  BASE tree in the session scratchpad (git archive HEAD + the five modified src files, `$isCoveredRaise` reverted),
  `raise1` = working tree. `run_payout.sh` now also runs the BASE tree for the arm named in `PAY_BASE_ARM`. Run:
  `PAY_BASE=<tree> PAY_BASE_ARM=lend1 HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 lend1 &&
  HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 raise1` (131 s per arm at 4 slots), then new
  `python3 lend.py <a> <b> [--firms]` (roll and champion+ share of payers, opening champions, breaks flat/cut,
  dividend-borrowing events, SHRK; `--firms` TALN/STRK/POOL ROE, margin, eq/GDP, D/E, breaches), plus sticky, payout,
  books, scorecard -> `payout_raise1_4s.out`.
- raise1 - lend1 (4 seeds, 20y, TPY 360): champion+ % payers y0/5/10/20 7.2/2.9/2.1/2.8 -> 7.0/5.2/3.2/4.0 (t -1.0/
  2.1/2.4/1.0); roll % 28.9/19.6/28.0/33.5 -> 28.4/23.1/30.0/34.6 (t <= 1.1). Opening champions unbroken y5 22 -> 44%
  (t 1.7), y20 9 -> 6% (t -1.0); breaks flat 7.0 -> 7.25, cut 1.0 -> 1.75 a run. Fiscal-year raise share .671 -> .693
  (t 4.8), flat .281 -> .261 (t -8.0); token share of streak years .043 -> .070 (t 3.7).
- SHRK: unbroken through y20 in 4/4 seeds (streak y20 10.8 -> 40, t 8.0), net D/E y20 0.20 both.
- Debt: logged dividend borrowing 12.5 -> 17.3 events, $13.4 -> 17.6bn a run (t 1.3/1.5); net D/E (77) unchanged at
  every year (|t| <= 1.1); covenant breach firms y1-3 4.75 -> 6.5 (t 2.0), firm-quarters 11.5 -> 20.3 (t 3.0), of the
  new breach firms only LOON, OSPR (6q, 3.0x covL), FIRE logged a dividend borrowing in y1-3; GRIP 0 -> 0.5.
- Dividends: DPS falls >10% 94.8 -> 96.8, >0.5% 284 -> 279, payout .353 -> .352 (all |t| < 0.5). Scorecard: nothing
  newly out; acquisitions 186 -> 183.
- lend1 - books1, lenders: TALN ROE y0 27 -> 29%, eq/GDP 1.06 -> 0.95%, D/E 6.0 -> 6.3, y20 ROE 16 -> 20%; STRK ~same
  (ROE y0 32%); POOL ROE y0/5/10/20 30/28/23/29 -> 19/18/15/17%, eq/GDP 0.92/0.97/1.04/1.09 -> 0.92/0.84/0.77/0.61%,
  D/E ~3.9. No death, default, covenant quarter; ratings A/A/BBB unchanged.
- raise2 (raise1 + `&& debtToEquity < leverageTarget`; POOL row drops `moat`, same default): `HEALTH=1
  bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 raise2` -> `payout_raise2_4s.out`. vs lend1 / vs raise1:
  covenant breach firms y1-3 5.25 (t 1.7 / -2.0), firm-quarters 13.5 (t 1.4 / -2.2); LOON, FIRE out, OSPR 2q at 1.04x
  (s2), GRIP still 1q in s1 and s2 (no dividend borrowing logged for any of them in y1-3). Champion+ % y5/10/20
  4.1/2.9/3.5 (vs lend1 t 1.2/3.1/0.8; vs raise1 t -3.0/-0.5/-0.8); opening champions unbroken y5/y20 34/6% (lend1
  22/9, raise1 44/6). SHRK unbroken in s3, s4 only (s2 breaks y3 and y9 at net D/E 0.57-0.65, s1 streak 16 at y20);
  streak y20 26.5 (lend1 10.8, raise1 40). Dividend borrowing 18.5 events, $18.6bn a run (lend1 12.5 t 2.1, raise1
  17.3 t 0.2). Raise share .677 (lend1 .671 t 1.5, raise1 .693 t -3.2); token share .043 = lend1. Scorecard: nothing
  newly out (largest revenue/GDP 0.17, out in all arms).

### reit1 (REIT debt capacity capped at min(tolerance, leverage target); four trusts open from character) 11-19
- Arms: `rhead` = HEAD 40e61299 via `OVR=<scratchpad>/ovr_head` (HEAD copies of InitialMarket, ReitBusinessModel,
  StandardCorporateBusinessModel at src-relative paths); identical to `raise2` row for row. `reit1` = working tree.
  `PayoutHarnessTest::healthRow` now also records `deH` (recap D/E) and `lt` (leverage target). Run:
  `OVR=<dir> HEALTH=1 bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 rhead && HEALTH=1 bin/harness-batch
  var/harness/scorecard/run_payout.sh 1-4 reit1` (109 s per arm), then new `python3 reit.py rhead reit1` ->
  `reit1_4s.out` (per-REIT D/E vs target, ndE, ROE, P/B, FFO payout, shares, eq and rev/GDP, covenant, ratings, cuts;
  board breaches, deals, non-REIT log equity/revenue scan) and `scorecard.py rhead reit1` -> `scorecard_reit1_4s.out`.
- D/E y20 (gross) SHOR 2.09 -> 1.41, PLZA 2.01 -> 1.34, ELDE 1.08 -> 1.00, KITE 1.88 -> 1.45 (t -7.2/-3.0/-0.4/-3.1),
  each within 0.13 of its target (1.12/1.40/1.02/1.51). ndE y20 4.1-5.2x (HEAD 5.3-6.6). REIT covenant quarters
  97 -> 0.5 a run; DPS cuts 33 -> 2.75; ratings BBB throughout both arms; no REIT death or default.
- ELDE: P/B 1.69 -> 3.47 at y0 and stays ~3.4-3.8; ROE 10-14% -> 17-20%; FFO payout .27 -> .50; eq/GDP .41 -> .32%.
  All REITs P/B 3.1-5.2 in both arms (above 1.5-2.5); FFO payout .43-.59 (below 70-75%). Share growth ~3.5%/20y both.
- REIT acquisitions 0 -> 3.75 a run (all after y4, debt-funded, large goodwill); board deals 183.5 -> 183.0.
- Board: covenant breach firms y1-3 6.25 -> 4.25 (t -1.6), firm-quarters 275 -> 172 (t -19.9; REITs are 97 of it).
  Scorecard nothing newly out. STRK fails in reit1 s1 at y13.1 (BB and losing in rhead too): path divergence.

### affo1 (REITs open at their own 0% tax, REIT depreciation halved, FairValue on AFFO = FFO - maintenance reserve) 11-19
- Arms: `ahead` = HEAD 9a8a3bab as a BASE tree (git archive in the session scratchpad, vendor symlinked; reproduces
  reit1 row for row), `affo1` = working tree. `PayoutHarnessTest` now also records `reit_trace` (REIT t, price,
  perceived fair value; ticks 1-30 then every 9th). Run: `PAY_BASE=<tree> PAY_BASE_ARM=ahead HEALTH=1
  bin/harness-batch var/harness/scorecard/run_payout.sh 1-4 ahead && HEALTH=1 bin/harness-batch
  var/harness/scorecard/run_payout.sh 1-4 affo1` (112 s per arm at 4 slots), then new `python3 affo.py ahead affo1`
  (reit.py tables + P/FFO, P/AFFO, yield, 85% floor, goodwill, trace: opening move, drawdown, log(px/fv)) ->
  `affo1_4s.out`, and `scorecard.py ahead affo1` -> `scorecard_affo1_4s.out`.
- P/B y0 3.35-3.58 -> 1.93-2.20 (t -31..-98), y20 3.8-5.2 -> 2.36-2.98. P/FFO y0 15.8-17.8 -> 9.5-12.2 (PLZA 9.5),
  y20 18.8-25.3 -> 14.0-16.5. Yield y0 3.8-4.4 -> 6.1-7.4%, y20 4.7-5.4%. eq/GDP +20% at y0, +25-35% at y20.
- FFO payout y0 .70-.74, y10 .78-.86 (+.09-.15, SHOR above 75%), y20 .74-.79. 85% floor binding yrs/20 SHOR/PLZA/ELDE/
  KITE 1.3/3.3/5.0/4.0 (HEAD .8/1.5/3.3/.8; KITE t 5.2). D/E y20 within .1 of target for SHOR/ELDE/KITE, PLZA 1.06 vs
  .83. ndE 3.5-4.4x y0, 3.8-5.3x y20 (unchanged). Shares +4.5-5.8% by y20 (+1.2-2.1pp, t 6.6-13.5).
- REIT deals 3.75 -> 4.25 a run, goodwill $8.1 -> 6.3bn. Covenant qtrs SHOR .5 -> 3.25, KITE 0 -> 1.75; DPS cuts SHOR
  1.25 -> 3.75, KITE 0 -> 1.5. Ratings BBB throughout, no REIT death/default, icr never < 1.
- Price path: opening move tick 1-30 within 4%, fv/px at tick 1 = 1.00. Mispricing |log(px/fv)| .09 -> .12-.15 for
  PLZA/ELDE/KITE (t 3.4-4.5), time >25% off fv 3-6% -> 10-17%, mean px under fv by 6-7% (SHOR, KITE). AFFO's fixed
  reserve levers FFO swings: 1y sd of log fv ELDE .12 -> .20 while price .12 -> .16. Drawdowns 60-88% for SHOR/KITE in
  both arms (board cycle; 9-10 firms past 80%).
- Board: scorecard nothing newly out beyond drawdown>80% firms 9.2 -> 10.2 (t 0.6, edge 10). Non-REIT mean |log eq
  diff| y10 .05, y20 .10 (largest STRK, fails in ahead s1): path divergence. Defaulters .5 -> 1.25 (FALC both, TERN, BOWR).
