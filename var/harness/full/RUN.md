# Full-market harness

The question family: what the whole board does over years (prices, failures, fair value, the macro-market loop).

`var/harness/FullMarketHarnessTest.php` replays the whole ticker headless on the production container. It uses an in-memory Redis
(`var/harness/bootstrap.php`) and a stub entity manager. The board is seeded by `app:market-seed`.

Each tick runs in the live ticker's order (`MarketTickerCommand`):
1. The macro.
2. The Diet, if `POLITICS=1`.
3. `FailureSweep`, every tpy/24 ticks.
4. `StockTracker::updateStocks`.

The macro receives every board input the ticker sends, one tick behind:
- total cap and float cap: levels, which stand when not reported;
- price return, dividends, net issuance, stamp duty and strategic-stake cash: flows for that tick;
- the bank levy.

## Run

    bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
      var/harness/FullMarketHarnessTest.php

`var/harness/run.sh rec|new|base <seed>` wraps it for paired replays:
- `rec` records the macro path.
- `new` and `base` replay it on two trees.

The runtime is about 4 s per simulated year at 360 ticks a year, and about 6 s with the Diet.

## Environment

**Core**
- `YEARS`, `TPY`, `SEED`, `OUT=<jsonl>`: the per-seed summary.
- `MACRO_RECORD=<gz>` or `MACRO_REPLAY=<gz>`: record or replay the macro path. A replay drops the board → macro feedback inside
  the compared arms.
- `POLITICS=1`: the Diet votes, holds budget rounds and hands its laws to the macro as the ticker does.
  - Live macro only; it is ignored on replay.
  - Without it, the opening laws stand forever.
- `SIMCMD=1`: mirrors `app:market-simulate`. The macro gets no board input, and the sweep runs every 100 ticks.

**Probes**
- `MACRO_OUT=<jsonl>`: one row a quarter, with:
  - the macro and market loop: gap, cap, float, fund, `fund_own`;
  - laws: `corp_tax_shift`, `div_tax`, `home_law`, `spend_law`;
  - `debt`.
- `TARGET_OUT`, `OPENING_OUT` (with `FVX`), `DRIVERS_OUT`, `RESET_PROBE`, `HEALTH`: see the test source.

## Gotchas

- `var/harness/cache/test` is compiled once with debug off and never refreshed. Delete it after a constructor or service
  change in `src/`.
- Before 2026-10-09 this harness was broken: it used the removed `MarketOperator` and `App\Service\Market\StockTracker`, and fed the
  macro only the total cap. So the fund's float cap was 0 and it could never buy. Runs older than that date had no working fund
  on the board.
- With `POLITICS=1`, the Council's debt brake holds every tax cut while debt is above its line, and the opening debt is about
  0.93 of GDP. In the first years only spending and the laws that cost no revenue move.
