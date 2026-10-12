# New-firm sanity run (BOWR homebuilder, FNCH gold miner)

Harness: `var/harness/new_firms/NewFirmsHarnessTest.php`, a copy of `var/harness/FullMarketHarnessTest.php` whose
per-quarter rows add `hsm` (home_sales_margin KPI), `inv` (report inventory), `invwd` (report inventory write-down)
and `hpt` (residentialPropertyIndexEma / residentialWealthTrend).

Run script: `var/harness/new_firms/run.sh live SEED` (live macro, no replay, POLITICS off; YEARS=20, TPY=360 default).
It sets BASE to this checkout, because `var/harness/bootstrap.php` hard-codes the main checkout as HARNESS_PROJECT;
without BASE a worktree run loads the main vendor and dies on a duplicate Composer autoloader.
It also writes OPENING_OUT (seed prices) beside the jsonl.

    bin/harness-batch var/harness/new_firms/run.sh 1-3 live
    python3 var/harness/new_firms/analyse.py 1 2 3 > var/harness/new_firms/sanity_3seed_20y.out

About 70 s per seed-20y at 360 ticks/yr. Outputs: `runs/live-SEED.jsonl|.open.json|.log` (ignored).
`analyse.py` needs no numpy (`minnp.py` shim). Board return is equal-weighted over all live firms' quarterly
split-adjusted returns; splits are inferred from share jumps of 1.5x or more.

Arms are only labels: `bin/harness-batch var/harness/new_firms/run.sh 1-3 <arm>` writes `runs/<arm>-SEED.*`, and
`ARM=<arm> python3 var/harness/new_firms/analyse.py 1 2 3` reads them (run without `-I`: it imports `minnp.py` beside it).
`ARM=<arm> python3 -I var/harness/new_firms/paths.py 1 2 3` prints year-by-year revenue, capacity, margin and share
paths for BOWR, FNCH and CNDR, plus the board's spread of share count and capacity growth.
The land write-down is plant (ASC 360), read from the `land_write_down` KPI as `lwd`, not from the inventory column.
