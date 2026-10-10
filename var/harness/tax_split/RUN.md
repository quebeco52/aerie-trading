# Tax split (corporate rate vs netTaxRate)

Question: with the automatic stabiliser and debt stance moved from the corporate rate firms pay onto `netTaxRate`,
what changes on the board (gap, debt, earnings cyclicality, market vol)? NEW = working tree, OLD = the same tree with
`CreditFiscalSubsystem.php` restored to the pre-split rules (`patch_old.py`).

- Harness: `var/harness/FullMarketHarnessTest.php`, live macro, `POLITICS=1`, `MACRO_OUT` quarterly rows, plus the
  env-gated `BOARD_NI=1` probe (`board_tni` = sum of trailing net income over firms not bankrupt, `board_n`,
  `gap_ema`, `net_tax`).
- OLD tree: `var/harness/tax_split/make_old.sh <dir>` (git worktree at HEAD, copies modified src/tests/var/harness,
  copies vendor, applies `patch_old.py`, `diff -rq src`, `php -l`). The OLD arm runs with `BASE=<dir>` so the
  bootstrap loads that tree's `App\` classes. Remove after: `git worktree remove --force <dir>`.
- Run script: `var/harness/tax_split/run.sh new|old <seed>` (YEARS default 60, TPY 360). ~6 s per simulated year.
- Batch: `OLD=<dir> bin/harness-batch var/harness/tax_split/run.sh 1-8 new old && python3 var/harness/tax_split/analyse.py 1-8`
- Analysis: `analyse.py <lo-hi>` prints the paired NEW-OLD table (gap mean/sd, debt, corp rate sd, YoY board NI growth
  beta and corr on the gap, annualised quarterly log vol of float and total cap). First year dropped.
- Outputs: `runs/<arm>-<seed>.macro.jsonl` (ignored), `stage*.out` tables.
- Delete `var/harness/cache/test` in a tree after a src service/constructor change.
