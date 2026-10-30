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
