# Price-vol harness

Question family: does a name realise more share-price volatility than its configured sigma, at which horizon, and
which price channel carries the excess.

- Harness: `PriceVolHarnessTest.php` on `var/harness/lib/BoardRun.php` (live macro, no politics). Always logs, per
  name, non-overlapping 1/21/63-tick window sums of split-adjusted end-of-tick log price changes, the board's
  float-weighted price return and the macro's `marketVolatility`. `PROBE=1` adds `Probe.php`: it swaps the tracker's
  MarketEngine and EarningsEngine for subclasses (state copied, parent called) and splits each tick's total return into
  mkt, sec, idio, pull, brk, jI, jS, ma, pol, flow, fund, warn, rep, betw (sum = tick total return) plus d log fair
  value, with each window's channel sums and cross products, and time means of the VarianceBudget inputs.
  Stocks are matched to the engine call by tracker order and price (`unmatched` in the output should be 0).
- Read-only by construction (no draws, no setters). The run itself is not reproducible run to run on a seed (two plain
  runs on seed 1 differ), so arms never pair: compare per-arm means over seeds.
- Run script: `run.sh <arm> <seed>`; YEARS (10), TPY (360), SKIP (warm-up ticks, TPY), TAG. Outputs
  `runs/<arm>-<seed><TAG>.{jsonl,log,pv.json}`.
  - `probe` working tree with the probe; `plain` without; `bis` = `BASE=<tree>` without the probe (BoardRun must run
    on that tree: it needs BoardReadingDTO, so not before cfe93445).
  - `old` = `BASE=<98549819 tree>` with that commit's own harness and probe, tick CSV in `runs/old-*.csv`. Restore them
    first (deleted after use: they fail PHPStan against HEAD): `mkdir var/harness/price_vol/old98` and
    `git show 98549819:var/harness/price_vol/PriceVolHarnessTest.php` (and `Probe.php`) into it.
  - `compare.py <seeds>`: HEAD `probe` against `old`, raw and with the market jump at its model variance.
  - Trees: `git archive <sha>` into the scratchpad + `vendor` symlink, never inside the repo; delete the tree and
    `var/harness/cache-<basename>` afterwards.
- Batch and analysis:

      bin/harness-batch var/harness/price_vol/run.sh 1-4 probe && python3 var/harness/price_vol/analyse.py probe 1-4 '' TERN
      BASE=<tree> bin/harness-batch var/harness/price_vol/run.sh 1-4 old && python3 var/harness/price_vol/old_ratio.py 1-4

- `analyse.py <arm> <seeds> [tag] [focus]`: section 1 realised/configured by horizon and the market against
  MACRO_VOL_BASE_ANCHOR; section 2 excess variance by channel group = Cov(channel, total) less the engine's budget for
  that channel; section 3 budget inputs.
- `idle_split_check.py <arm> <seeds>`: the idle harness's `rv` with the stock splits taken out (its `rv` sums raw tick
  log returns, so a 4-for-1 split adds log(4)^2 to the sum of squares).
- Runtime: ~50 s per seed-decade with the probe, 4 seeds in parallel.
