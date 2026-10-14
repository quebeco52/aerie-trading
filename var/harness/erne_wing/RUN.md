# ERNE (communication equipment) and WING (steel) moments, base commit vs working tree

Harness: `var/harness/erne_wing/ErneWingHarnessTest.php`, a fork of `var/harness/loon/LoonHarnessTest.php` (itself
FullMarketHarnessTest + consensus EPS/revenue, nominal GDP index). Adds `gscpi` (supplyChainPressureIndexEma at the
report) to quarter rows, and records an `events[ticker]` entry `d = SHORTAGE` when the earnings description carries
the ShockEvent::SEMICONDUCTOR_FAB_SHORTAGE lore (NarrativeEngine phrases; regex in the persist stub).

Run script: `var/harness/erne_wing/run.sh a|b <seed>` (YEARS 12, TPY 360). Replays the macro paths recorded in
`var/harness/loon/macro/path-<seed>.gz` (record more with `var/harness/loon/run.sh rec <seed>`).
- `a`: `treeA_ew/` = `git archive <base>` + `cp -a vendor`, its own phpunit. Rebuild:
  `rm -rf var/harness/erne_wing/treeA_ew var/harness/cache-treeA_ew && mkdir var/harness/erne_wing/treeA_ew && git archive <rev> | tar -x -C var/harness/erne_wing/treeA_ew && cp -a vendor var/harness/erne_wing/treeA_ew/`
- `s`: `treeS_ew/` = base tree with the working-tree steel seasonality patched into its SteelManufacturingBusinessModel.
- `b`: working tree through the `treeB_ew` symlink (cache `var/harness/cache-treeB_ew`; delete it after a
  constructor/service change in src/).

Batch (about 1 min per seed-run):

    bin/harness-batch var/harness/erne_wing/run.sh 1-8 a b \
      && python3 -I var/harness/erne_wing/analyse.py 1-8 > var/harness/erne_wing/<name>.out && cat var/harness/erne_wing/<name>.out

`analyse.py <lo-hi>`: quarters after year 2; paired b - a by seed. ERNE margins, capex, D&A, revenue error sd vs
consensus, shortage share and pooled shortage rate by GSCPI bin; WING sd of q/q log revenue and EBIT margin by
covered calendar quarter; distress (B or worse) per ticker.

Rating attribution: runs carry `r_*` rating inputs for `RATING_PROBE` (default WING): Merton d2, Altman Z and zone,
trailing ICR and ND/EBITDA, time-weighted rated leverage and the leverage anchor (no base case, approximate).

    bin/harness-batch var/harness/erne_wing/run.sh 1-16 a s b && python3 -I var/harness/erne_wing/analyse_wing.py 1-16 a s b

`tariff_check.py 1-16` prints the max |tariff| fields over the recorded macro paths.
