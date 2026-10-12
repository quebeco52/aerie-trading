# earnings_b1: earnings-engine fix batches (2be6a6f), arm by arm

Harness: `FullMarketHarnessTest.php` fork (adds per-quarter Altman zone, consensus/reported EPS, deflator, nominal GDP,
cash/book tax, goodwill impairment and the report's price gap; event regex adds "Guidance cut|emergency|stock
offering"). `bootstrap.php` fork requires the main checkout's tests/bootstrap.php; a snapshot arm loads its own src
through the BASE autoloader, kernel cache in `cache-<snapshot>/` beside this file.
Live macro, no politics, YEARS=15, TPY=360. ~70 s per run, ~20 MB per run.

Arms (snapshot = `cp -a` of a checkout with vendor, under `$SNAP`, default /tmp/claude-1000):
- `before` main checkout src; `after` = batch 1 (eef-b1); `b2` = batch 2 (eef-b2); `b3` = batch 3 with
  replacement-cost capacity (eef-b3); `b3b` = the committed tree, capacity revalued on the price-level surprise only (eef-b3b).

Run (runs land in `$H/runs/`, H defaults to this directory):
    bin/harness-batch var/harness/earnings_b1/run.sh 1 b3b && bin/harness-batch var/harness/earnings_b1/run.sh 2-16 b3b
    ARMS=b3,b2,b3b python3 var/harness/earnings_b1/analyse3.py
Seed 1 first warms a new snapshot's kernel cache. `analyse.py` is the batch-1 table (before vs after), `analyse2.py` batch 2.
`sectors.json` (ticker -> isFinancial / requiresAlternativeZScore / industry; untracked data) is dumped from the seeded
Stock rows; the Z'' share uses alt=false firms only. Delete cache*/ after a constructor or service change in either tree.
