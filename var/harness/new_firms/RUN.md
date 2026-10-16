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

Round 2 (PELI, EGRT): `bin/harness-batch var/harness/new_firms/run.sh 1-3 <arm>`, then
`ARM=<arm> python3 var/harness/new_firms/analyse_round2.py 1 2 3`.

Round 3 (SKUA, DIPR, KTWK beside LOON): rows for those four tickers add `kpi` (every reported KPI), `reg` (the
`state:regime:*` clocks: price_war, unit_outage, dock_strike), `ddg`/`fog`/`wag` (domestic, foreign, world gaps).
Needs HEALTH=1 for the `ndE`/`covL` leverage columns:

    HEALTH=1 bin/harness-batch var/harness/new_firms/run.sh 1-3 r3v1
    ARM=r3v1 python3 var/harness/new_firms/analyse_round3.py 1 2 3 > var/harness/new_firms/round3_3seed_20y.out

Prices in round 3 are from tick 1 (`px1` in open.json), not the seed: the seed command splits some titans at tick 1.
`updateMacroState` no longer takes `boardBankCredit`; the harness passes the levy only.

Round 3 paired baseline (HEAD vs working tree): extract `git archive HEAD` into
`base/<src-sha>-<harness-sha>-s4-y20-t360/head-<src-sha>/` with a `vendor` symlink, run
`HEALTH=1 bin/harness-batch <that tree>/var/harness/new_firms/run.sh 1-4 head`, copy its `runs/head-*` here, then
`python3 -I var/harness/new_firms/compare_r3_pricewar.py head <arm> 1 2 3 4` (SKUA/LOON war vs peace, first war paired).
Old arms (r3v2) predate later commits; check `git log` against their mtime before pairing.

Round 3 v4 (SKUA vol 0.18 vs 0.24, plant-sale cascades): `r3v3` 5-8 come from `base/r3v3-tree/` (working tree with
SKUA volatility put back to 0.24; run its own `var/harness/new_firms/run.sh`, copy `runs/r3v3-*` here). Then
`python3 -I var/harness/new_firms/analyse_r3v4.py > var/harness/new_firms/round3v4_8seed_20y.out` (reads head 1-4,
r3v3 1-8, r3v4 1-8). Runs are deterministic per seed: same tree + seed gives a byte-identical jsonl.
Divestitures are not in the jsonl events (filter at NewFirmsHarnessTest.php:480) nor the .log; they are inferred from
ppe drops (>15%, shares <1.3x prior, with debt down = a sale).
Do not `pkill -f` a pattern that is also in your own shell's command line: it kills the call, not the batch.
