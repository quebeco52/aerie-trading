# Harness library

Shared code for the harness topics: `BoardRun.php`, the whole-board run, and `stats.py`, the paired and unpaired
summaries. A topic adds its own logging through hooks instead of copying the loop.

## BoardRun: the whole-board run

`BoardRun` is an abstract `KernelTestCase`. It runs the whole ticker headless on the production container, with the
in-memory Redis of `var/harness/bootstrap.php`, a stub entity manager and the board seeded by `app:market-seed`.

Each tick runs in the live ticker's order (`MarketTickerCommand`):
1. The macro, or the replayed path.
2. The Diet, if `POLITICS=1`. It reads this tick's economy, and the economy reads its laws next tick.
3. `FailureSweep`, every tpy/24 ticks.
4. `StockTracker::updateStocks`.

The macro gets every board input the ticker sends, one tick behind:
- total cap and float cap: levels, which stand when not reported;
- price return, dividends, net issuance, stamp duty and strategic-stake cash: flows for that tick;
- the bank levy.

`var/harness/FullMarketHarnessTest.php` is the plain run. `var/harness/run.sh` wraps it for paired replays, and
`var/harness/full/RUN.md` covers that.

### A topic harness

    <?php

    declare(strict_types=1);

    require_once __DIR__ . '/../lib/BoardRun.php';

    final class MyTopicHarnessTest extends BoardRun
    {
        protected function afterStocks(array $result): void { /* read $this->tick, $this->currentMacro, $result */ }
    }

Run it as the plain one:

    bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
      var/harness/<topic>/MyTopicHarnessTest.php

The file name must match the class name. Each hook defaults to doing nothing. In the order they run:

| hook | when | use |
|---|---|---|
| `onStart()` | after the seed and the services, before tick 1 | calibration reads on the seeded board |
| `macroFor($m)` | after the Diet and the recording | force a field on the DTO the board reads |
| `beforeStocks()` | after the failure sweep, before the stock update | state as the tick's update will read it |
| `afterStocks($result)` | after the stock update | per-tick series, extra output files |
| `boardReading($reading, $result)` | after `afterStocks` | what the macro reads next tick |
| `macroRow($row, $m)` | each `MACRO_OUT` row | return the row with the topic's columns |
| `reportRow($row, $report)` | each quarterly report | return the quarter row with the topic's columns |
| `quarterRows()` | each report | false drops the standard quarter rows from the record |
| `onEvent($event)` | each stock event after the seed | event counts, report-day moves |
| `record($record)` | the `OUT` record | return it with the topic's fields |
| `onEnd()` | after the `OUT` record, before `RESET_PROBE` | write the topic's own output file |

Hooks read the run state: `$seed`, `$years`, `$tpy`, `$dt`, `$ticks`, `$tick`, `$stocks` (by ticker), `$list`,
`$seedPrice`, `$currentMacro`, `$currentTime`, `$politicsState`, `$reading`, `$dead`, `$debtEngine` and `$tracker`.
They can also use `static::getContainer()`.
- `quarterRow()`, `fvRow()`, `healthRow()` and `boardNetIncome()` build the standard rows.
- `insertAfter($row, $key, $fields)` places a topic's columns beside the ones they belong with.

A hook that draws a random number, or changes a stock, changes the run. Keep a probe read-only, and check that it is:
one seed with the probe on and off must give the same `OUT` record.

### Environment

**Core**
- `YEARS` (1), `TPY` (360), `SEED` (1). `SEED` seeds the firms' shared stream; `MACRO_SEED` and `POLITICS_SEED`, the
  economy's and the Diet's own streams (`config/services.yaml`), are derived from it unless set.
- `OUT=<jsonl>`: one record per run. It holds deaths, reorganisations, payment defaults, and each firm's quarter rows,
  final price and volatility. It also keeps the treasury's distress lines from the event text. A NaN in the record
  is written as 0, and the record is flagged `json_error`.
- `MACRO_RECORD=<gz>` or `MACRO_REPLAY=<gz>`: record or replay the macro path. A replay drops the board's feedback to
  the macro. The recording is the macro's own path, before `macroFor()`.
- `POLITICS=1`: the Diet votes, holds budget rounds and hands its laws to the macro, as the ticker does. It works on a
  live macro only and is ignored on replay. Without it, the opening laws stand.
- `SIMCMD=1`: mirrors `app:market-simulate`. The macro gets no board input, and the sweep runs every 100 ticks.

**Probes**
- `MACRO_OUT=<jsonl>`: one macro row a quarter. It holds the macro-market loop (gap, cap, float, fund, `fund_own`),
  the laws (`corp_tax_shift`, `div_tax`, `home_law`, `spend_law`), debt and the sovereign spread.
  - `BOARD_NI=1` adds the board's trailing net income.
  - `FISCAL=1` adds the primary deficit, the net-tax rate and the spending index, and a founding row at tick 1.
- `HEALTH=1`: the trailing debt-health reading on each quarter row.
- `FVX=1`: the fair value the market would strike, and its inputs, on each quarter row, and at tick 1 in
  `OPENING_OUT`.
- `DRIVERS_OUT=<jsonl>`: each report's stream details.
- `OPENING_OUT=<json>`: each firm's EPS, price, shares and fair value at seed and after tick 1.
- `TARGET_OUT=<json>`: the page's analyst target before the first publish, then the published target at 1 week to
  12 months.
- `RESET_PROBE=<json>`: each firm's price after reopening the board as `app:market-reset` does.

### Gotchas

- The container is compiled once into `var/harness/cache[-<basename of BASE>]/test`, with debug off, and is never
  refreshed. Delete it after a constructor or service change in `src/` or any change to `config/`.
- `BASE=<tree>` runs another tree's `src` (a `git archive` copy with its own copied `vendor`). The harness and this
  library come from the path given to phpunit.

## stats.py: paired and unpaired summaries

    import sys
    from pathlib import Path
    sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
    from stats import paired, mean_se

- `mean_se(xs)` returns `(n, mean, se)`, with se = sd / sqrt(n) (sample sd, n - 1).
- `paired(a, b)` returns `(n, diff, se, t)` for the difference b - a, seed by seed.
  - With two dicts, it pairs the seeds that are in both.
  - With two lists, they must be the same length and in seed order.
  - `t` = diff / se. It is nan when se is 0 or n < 2.
- `fmt(p)` prints a `paired()` result as `+0.012 (0.004) t 3.0 n 16`.
- `python3 var/harness/lib/stats.py` runs its self-check.

The economy draws from its own stream, so arms on one seed meet the same cycle: two 10-year runs on one `MACRO_SEED`
with entirely different firm draws differed by 0.01pp sd in the output gap, against 1.65pp on another seed. What
still differs comes only through what the economy reads from the board (household wealth, the reserve fund). Both
arms need a tree from after the stream was split; an older `BASE` tree draws its economy from the shared stream and
does not pair.
