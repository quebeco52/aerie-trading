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
