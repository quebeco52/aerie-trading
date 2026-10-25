# Space upstart (SKYL) growth check

Question family: does SpaceSystemsBusinessModel / SKYL deliver its designed growth path on the live board, and where
does it bind (plant, capital budget, funds, debt, saturation, hurdle). GRIP beside it.

- Harness: `var/harness/space/SpaceHarnessTest.php` (forked from `nuclear/`; extends `lib/BoardRun.php`; read-only;
  `analyzeDebtHealth` is called for the hurdle read). Writes `runs/<arm>-s<seed>.json` (`SPACE_OUT`): opening, every
  quarter of SKYL and GRIP (streams, KPIs, regimes, cap, consumer price level) and for SKYL a `diag` block: invested
  capital, net/gross PPE, baseline and trailing ROIC, hurdle and applied hurdle, capex ratio and reinvestment bias, the
  model's faded and secular growth, addressable share, saturation penalty, market growth, investing/operating/free
  cash flow, EBITDA, cash, payout, inflation EMA. `failures[firm]` = launch-failure lore report times.
- Run script: `var/harness/space/run.sh <arm> <seed>` (env YEARS 20, TPY 360; live macro, no Diet).
- Opening probe: `bin/harness-batch var/harness/space/open_probe.sh 1 base`; `make_ovr.sh` writes `ovr/scale130`
  (SKYL x1.3 incl. sam; edits fixed lines 605-640 of InitialMarket.php), then `open_probe.sh scale130 1`.
- Batch at the resized seed: `OVR=<abs>/var/harness/space/ovr/scale130 bin/harness-batch var/harness/space/run.sh 1-8
  live && python3 var/harness/space/analyse.py live > var/harness/space/live_8seed_20y.out` (about 4 min wall).
- Analysis: `analyse.py [arm]` prints mean [min, max] over seeds for each year-snapshot and run statistic.
- After adding a model class in `src/`, delete `var/harness/cache/test`.
- Evidence: `space_evidence.md`.
