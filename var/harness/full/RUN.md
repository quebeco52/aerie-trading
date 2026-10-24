# Full-market harness

The question family: what the whole board does over years (prices, failures, fair value, the macro-market loop).

`var/harness/FullMarketHarnessTest.php` is the plain whole-board run: `var/harness/lib/BoardRun.php` with no topic
hooks. The tick order, the macro's board feed, the environment (`YEARS`, `TPY`, `SEED`, `OUT`, `MACRO_RECORD` /
`MACRO_REPLAY`, `POLITICS`, `SIMCMD`) and the probes (`MACRO_OUT`, `FISCAL`, `BOARD_NI`, `HEALTH`, `FVX`,
`DRIVERS_OUT`, `OPENING_OUT`, `TARGET_OUT`, `RESET_PROBE`) are in `var/harness/lib/README.md`.

## Run

    bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
      var/harness/FullMarketHarnessTest.php

`var/harness/run.sh rec|new|base <seed>` wraps it for paired replays:
- `rec` records the macro path.
- `new` and `base` replay it on two trees (`base` needs `BASE=<tree>`).
- Env: YEARS (20), TPY (360), RUNS (output dir, default `var/harness/full`).

The runtime is about 4 s per simulated year at 360 ticks a year, and about 6 s with the Diet.

## Gotchas

- `var/harness/cache/test` is compiled once with debug off and never refreshed. Delete it after a constructor or service
  change in `src/`.
- Before 2026-10-09 this harness was broken: it used the removed `MarketOperator` and `App\Service\Market\StockTracker`, and fed the
  macro only the total cap. So the fund's float cap was 0 and it could never buy. Runs older than that date had no working fund
  on the board.
