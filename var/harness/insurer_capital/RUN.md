# insurer_capital: insurer cash backing, NAIC #440 distribution ceiling, taxed divestiture gains

Question: does the change (manager cash bias scales only the discretionary buffer, not float backing; NAIC Model Act
#440 cap on insurer dividends + buybacks; divestiture gains taxed) stop SAFE over-capitalising and keep its book
written, without breaking other firms?

Harness: `FullMarketHarnessTest.php`, fork of `margin_ratchet/` (live macro, no politics, per-firm quarters). Adds per
quarter: `style` (manager style), `ngdp` (nominal GDP index), `cdep` (customer deposits = float), `ss` (statutory
surplus, deposit-takers only), `ws`/`os` (earnings_momentum_z written share / observed capacity share); per firm
`eq0` (seed equity); per run `event_types` (StockEvent counts by type) and `divest_by` (DIVESTITURE per seller).
`bootstrap.php` unchanged from margin_ratchet (BASE autoloader; kernel cache in `cache-<snapshot>/`).
YEARS=30, TPY=360, ~3 min per run, 8 parallel.

Arms (paired by seed):
- `before` = HEAD snapshot: `git archive HEAD | tar -x -C <snap>`, then `cp -a .env* vendor <snap>/` (never symlink).
  Default `<snap>` is `.../scratchpad/ic-before` of session 1cdddd51; set `BEFORE=<dir>` to point elsewhere.
- `after` = the main checkout working tree.

Run (H must be absolute; outputs in `$H/runs/<arm>-<seed>.jsonl`):

    H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/insurer_capital \
      bin/harness-batch var/harness/insurer_capital/run.sh 1-8 before after && \
      python3 var/harness/insurer_capital/analyse.py --styles > var/harness/insurer_capital/stage8.out

Analysis: `analyse.py [runs dir] [--styles]`. Targets (SAFE): (1) log change of equity/NGDP, seed to year 30;
(2) sum EBIT / sum revenue, years 10-30; (3) mean written share, years 10-30. Side: same for DOVE, buybacks, payout,
treasury/float, failures, divestitures, median ex-fin EBIT-margin drift, flag counts (NaN, negative equity,
buyback > 10% of equity in a quarter). `--styles` prints SAFE's manager style mix per seed.
Delete `cache*/` after a service/constructor change. `smoke/` holds a 1-year check run.

## 2026-10-12 fix C (Winter 1994 capacity-constrained supply): two frozen snapshots
`run_c.sh <arm> <seed>`: `before` = scratchpad `ds-after` (HEAD 28a82bc + debt-supply), `after` = scratchpad `c-after`
(+ fix C); `SNAPS=<dir>` overrides the parent. Each runs its own vendor/phpunit and tests/bootstrap.php (bootstrap.php
now requires `HARNESS_PROJECT/tests/bootstrap.php`), cache in `cache-<snapshot>/`, outputs in `fixc/runs/`.
Extra per-quarter keys: `fr` franchise share, `or` observed capacity/demand ratio, `hm` hard-market clock, `tngdp`
trend NGDP (IndustryShareLedger::trendNominalGdp), `sam`. Premium demand D = fr x 1e12 x tngdp x sam.

    bin/harness-batch var/harness/insurer_capital/run_c.sh 1-8 before after && \
      python3 var/harness/insurer_capital/analyse_c.py > var/harness/insurer_capital/fixc_stage8.out

## 2026-10-12 fix C + insurer capital target (c2)
Arm `c2` in `run_c.sh` = scratchpad `c2-after` (fix C + target premium-to-surplus via capitalTargetRepurchase). New
per-quarter key `tps` (state:capacity:target_premium_to_surplus). `before` runs in fixc/runs reused (same harness
simulation, same seeds). Analysis takes arms: `analyse_c.py --a=c2 --ctx=after` (ctx column = fix C alone); it also
reports NAIC-ceiling binding share and seeds with hard-market share > 25%.

    bin/harness-batch var/harness/insurer_capital/run_c.sh 1-8 c2 && \
      python3 var/harness/insurer_capital/analyse_c.py --a=c2 --ctx=after > var/harness/insurer_capital/fixc2_stage8.out
