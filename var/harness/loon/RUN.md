# LOON telecom moments (base commit vs working tree)

Question family: LOON's (Loon Call Telecom) operating moments against Verizon targets, calendar-quarter margin
seasonality and consensus surprise by quarter, paired against a base commit.

Harness: `var/harness/loon/LoonHarnessTest.php`, a copy of `var/harness/FullMarketHarnessTest.php` whose quarter rows
add `ceps`/`reps`/`crev` (consensus and reported EPS, consensus revenue), `ngdp` (nominal GDP index) and, for LOON
only, the growth-capex gate rebuilt from the same pure calls EarningsEngine::calculateGrowthCapEx makes:
`g_roic` (target-metrics ROIC), `g_hurdle` (manager's applied hurdle), `g_reinv`, `g_ndE`.

Run script: `var/harness/loon/run.sh rec|a|b <seed>` (YEARS 12, TPY 360 default).
- `rec`: working tree records the macro path (`macro/path-<seed>.gz`); not analysed.
- `b`: working tree through the `treeB` symlink (own container cache `var/harness/cache-treeB`), replays the path.
- `a`: `treeA/` = `git archive <base commit>` + `cp -a vendor`, run with its own `vendor/bin/phpunit` (a symlinked
  or foreign vendor loads two Composer autoloaders). Rebuild treeA for a new base:
  `rm -rf var/harness/loon/treeA var/harness/cache-treeA && mkdir var/harness/loon/treeA && git archive <rev> | tar -x -C var/harness/loon/treeA && cp -a vendor var/harness/loon/treeA/`
- `b2`: working tree, new label. `c`: working tree with `LOON_CAPEX_RATIO=0.05` (harness sets LOON's capex ratio after seeding).
- After a constructor/service change in src/, delete `var/harness/cache-treeB` (compiled once).

Batch (about 1 min per seed-run at 12 y):

    bin/harness-batch var/harness/loon/run.sh 1-8 rec && bin/harness-batch var/harness/loon/run.sh 1-8 a b \
      && python3 -I var/harness/loon/analyse.py 1-8 > var/harness/loon/<name>.out && cat var/harness/loon/<name>.out

`analyse.py <lo-hi>`: per seed, quarters reported after year 2; covered calendar quarter =
`(floor(4t) - 1) mod 4` (EarningsEngine::coveredCalendarQuarter). `analyse.py <lo-hi> [ref arm] [arms...]` (default a b) pairs each arm against the first.
Cap/GDP uses MacroEngine::DISTRICT_GDP_USD x ngdp. Debt/EBITDA uses the last report and trailing 4-quarter EBITDA.
