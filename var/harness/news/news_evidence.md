# News census: stories and headlines per simulated year

## 2026-10-06: how many stories and headlines, by kind, under the NewsDesk rule

**Question.** How many newswire stories and how many headlines (NewsDesk::isHeadline, uncommitted working tree)
does the simulation produce per simulated year, by category? Is "every M&A deal is a headline" too loose?

**Harness.** `NewsCountHarnessTest.php` (KernelTestCase, `HarnessKernel` from `bootstrap.php` here: the shared
in-memory Redis plus a tap that keeps every `lPush('market_events_list', ...)`, i.e. every wire copy
MarketEventPublisher builds, with its presented category and headline verdict). Seeds the board through
`app:market-seed` on a stub EM, then replays the live ticker's per-tick order: macro (with board cap, float, returns,
dividends, issuance, stamp duty, stake cash, bank levy and the politics policy) -> politics -> operator every tpy/24 ->
StockTracker::updateStocks -> SystemicEventReporter -> index reconstitution (INDEX story when membership changes) and
quarterly fund distributions -> EtfTracker::updateIndex -> Glasswater Row roster (DISTRICT stories).
Not replayed: players (no fund flow, no trades), option/bond desks (they publish nothing).
M&A size = first "$X.XB" in the description (purchase price, or a divestiture's sale price) over the acquirer's
(seller's) price x shares at the end of the previous tick.

Run (8 at a time): `BASE=<snapshot tree> YEARS=10 TPY=3600 ./run.sh 1 2 3 4 5 6 7 8`, then
`python3 news_an.py 0 10 > news_16x10.out` (args: from-year to-year [seed,list]). The snapshot tree was a copy of the
working tree (src, config, tests/bootstrap.php, vendor symlink) taken 2026-10-06 11:43, so all 16 seeds ran one code
version. ~600 s per seed-decade at 3600 tpy with 8 in parallel.

**Seeds/years.** 16 seeds (1-16) x 10 years, 3600 ticks/year (production .env says 14400; events are Poisson
lambda*dt or calendar-driven, so counts are dt-neutral).

**Result** (`news_16x10.out`; years 1-10 only in `news_16x10_ex_year1.out`):

| category | stories/yr | headlines/yr | headline share |
|---|---|---|---|
| earnings | 316.0 | 83.6 +- 1.0 | 26.5% |
| analyst | 134.2 +- 2.0 | 0.4 +- 0.1 | 0.3% |
| shock | 51.0 +- 0.5 | 10.8 +- 0.3 | 21.3% |
| debt | 39.9 +- 0.6 (22.0 after year 1) | 3.9 +- 0.2 | 9.8% |
| mna | 10.7 +- 0.3 | 10.7 +- 0.3 | 100% |
| government | 8.5 +- 0.2 | 8.5 +- 0.2 | 100% |
| economy | 0.6 +- 0.1 | 0.6 +- 0.1 | 100% |
| bankruptcy / reorganization | 0 / 0 | 0 | - |
| district 16.1, income 16.0, governance 5.3, split 4.2, index 4.1, general 0 | | 0 | 0% |
| TOTAL | 606.7 +- 2.5 | 118.6 +- 1.2 | 19.6% |

M&A acquisitions (n=1605): deal / acquirer cap q10 1.2%, q50 4.3%, q90 12.9%; 16.9% of deals >= 10% of cap.
Divestitures (n=109): sale / seller cap q10 8.4%, q50 17%, q90 89%; announcement moves q50 +9.8%, q90 +49%, max +158%.
Earnings: 26.5% clear |z| >= 1.96 (18.7% at 2.58, 15.1% at 3.0). Shocks: 21.3% move more than 10%.
Alternatives: M&A headline only at deal >= 10% of cap: 2.2 +- 0.1/yr (5% of cap: 5.1); all headlines then 110.2 +- 1.1.
Earnings at z >= 2.58: 59.2 +- 0.8/yr; at 3.0: 47.6 +- 0.7/yr.

**Caveats.** No bankruptcies or reorganizations in 160 seed-years, so their headline load is ~0 here. Year 1 carries a
re-rating transient (about 200 rating changes, then ~22/yr) and the seed splits. The divestiture announcement return
(MergerAndAcquisitionEngine.php:1123) against a sale price floored at a fraction of book (line 1000-1006) gives
moves up to +158% for sellers trading below book: worth a separate look.

## 2026-10-06 (2): divestiture floor fix + tighter headline rule, BEFORE vs AFTER

**Question.** After (1) the divestiture fire-sale floor moved from 40-80% of book disposed to
divestedFraction x market cap x (1 - 0.14) (Pulvino 1998), and (2) NewsDesk made M&A a headline only at deal /
pre-deal cap >= 0.10 and raised the earnings/analyst bar to |SAR| >= 2.576: what happens to divestiture announcement
returns, sellers' book equity, failures, and the headline load?

**Harness.** Same `NewsCountHarnessTest.php`, now also recording the seller's/acquirer's book equity at the end of the
previous tick (`eq0`), after the deal (`eq1`) and the stated gain/loss on sale (`gain`, parsed, $0.1B resolution).
BEFORE = frozen tree `scratchpad/newstree` (11:43), AFTER = frozen tree `scratchpad/newstree_after` (12:16).
`ARM=before|after BASE=<tree> ./run.sh <seeds>` -> `runs_<arm>/`; `python3 news_cmp.py 0 10`;
`RUNS=runs_after python3 news_an.py 0 10`. 16 seeds x 10 years each arm, 3600 tpy.

**Caveat on pairing.** These runs did NOT set POLITICS_SEED, so the politics stream (config/services.yaml
`app.politics_draws`) was seeded from entropy: the same seed does not reproduce its path (re-running BEFORE diverged
from `runs_v1` within the first month). Arms are compared as distributions, which the divestiture change forces anyway
(one fewer draw per divestiture). `run.sh` now passes POLITICS_SEED=<seed>.

**Result** (`divest_before_after_16x10.out`, `news_after_16x10.out`):

| quantity | BEFORE | AFTER |
|---|---|---|
| divestitures / yr | 0.52 +- 0.06 | 0.57 +- 0.05 |
| announcement return, mean (se) | +6.4% (2.9) | -3.4% (0.4) |
| p10 / p50 / p90 / max | -13.4 / -2.2 / +48.4 / +99.7 | -6.4 / -4.0 / -2.1 / +15.5 |
| share > +10% | 27.7% (n=83) | 2.2% (n=91) |
| gain on sale % pre-sale book, mean (se) | -6.2 (1.0) | -9.9 (1.6) |
| gain % book p10 / p50 / p90 | -15.4 / -7.3 / +2.5 | -29.7 / -7.5 / +8.1 |
| sales booking a loss | 80.7% | 64.8% |
| sales leaving negative book | 0 | 0 |
| bankruptcies + reorganizations | 0 | 0 |
| AFTER headlines/yr: earnings 60.9 +- 1.5, shock 11.1 +- 0.3, government 8.7 +- 0.2, debt 3.7 +- 0.2, mna 2.1 +- 0.1, economy 0.6, analyst 0.5; total 87.7 +- 1.7 (was 118.6 +- 1.2) | | |
