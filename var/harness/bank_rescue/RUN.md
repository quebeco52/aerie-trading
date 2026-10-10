# Bank-rescue fiscal cost

Question family: what booking the bank-rescue cost (CreditFiscalSubsystem::bookBankRescue) onto gross debt does to
public debt, the debt brake (0.90) and the sovereign spread.

- Harness: `var/harness/FullMarketHarnessTest.php`, live macro, `POLITICS=1`, `MACRO_OUT` (one row a quarter: debt,
  net_debt, bank_credit, rescue_cost, rescue_assets, crisis_at, spread, ...).
- Run script: `run.sh new|base <seed>` (YEARS default 100, TPY 360). Output `runs/<arm>-<seed>.jsonl`.
  - `new`: the main working tree.
  - `base`: a worktree at `tree-base/` built from HEAD plus every modified file under src/, tests/,
    var/harness/FullMarketHarnessTest.php and .env copied from main, with `cp -a vendor` (not a symlink), and only the
    last line of `bookBankRescue()` changed to `return 0.0;`. Check with `diff -r src tree-base/src`. It runs on its own
    vendor/bootstrap/harness with `BASE=tree-base`, cache `tree-base/var/harness/cache-tree-base/`.
  - Remove with `git worktree remove --force var/harness/bank_rescue/tree-base` when done; rebuild it for a rerun.
- Delete `var/harness/cache/test` (and the tree's cache) after a constructor or service change.
- Batch (one stage of 8 seeds x 2 arms is ~20 min wall at 8 slots):

      bin/harness-batch var/harness/bank_rescue/run.sh 1-8 new base && \
        python3 var/harness/bank_rescue/analyse.py 8

- Analysis: `analyse.py [nseeds]` prints the event study (debt 5y after each crisis less the quarter before, spread over
  3y after), share of quarters with gross debt > 0.90, run-average spread and debt, paired per seed, plus the NEW rescue
  cost distribution and asset wind-down.
- `seed_path.php`: bare-macro probe (no board), quarterly gap/drag/debt/rescue for one seed.

Gotcha: the full harness is NOT deterministic run to run on the same seed (two `new` runs of seed 1 differ in the first
quarter), so the arms are not path-identical before the first crisis; the pairing is by seed only. Size n as unpaired.

## Bare-macro crisis probe (crisis -> slump, 2026-10-10)
- `crisis_probe.php new|off <seed> [years] [tpy]`: BusinessCycleRealismTest::simulateGapPath's engine; `off` subclasses
  CreditFiscalSubsystem with `bankRescueShock() = -INF` (rescue 0). Writes `runs_macro/<arm>-<seed>.jsonl` (q rows
  quarterly: gap, gross debt, tax; c rows per crisis: rescue, credit gap, debt). ~10 s per seed-century.
- `crisis.sh new|off <seed>` (YEARS 100, TPY 252), takes its own php-slot.
- Batch: `bin/harness-batch var/harness/bank_rescue/crisis.sh 1-48 new off && python3 var/harness/bank_rescue/analyse_crisis.py 1-48`
  (48 seeds x 2 arms ~2 min wall).
- `analyse_crisis.py <a-b> [years]`: per-crisis longest run gap<-4% starting within 2y, clamp quarters in 8y, rescue
  cost, debt peak in 5y, whole-run and first-40y longest run per seed, paired NEW-OFF.
