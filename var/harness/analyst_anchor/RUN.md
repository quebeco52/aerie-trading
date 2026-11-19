# analyst_anchor

Does carrying the analyst revenue anchor forward by the public quarter's revenue
(`EarningsEngine::STATE_LAST_MARKET_REVENUE`) remove persistent revenue surprises for price-taker firms?

- Harness: `AnalystAnchorHarnessTest.php` (extends `lib/BoardRun.php`). Live macro, no politics.
  - `ARM=base`: `beforeStocks()` strips `STATE_LAST_MARKET_REVENUE` from every stock each tick, so
    `MarketConsensusEngine::generateConsensus` falls back to the old expected-revenue roll. The record carries
    `cleared` (keys removed) and `left_after_clear` (must be 0).
  - `ARM=fix`: the working tree as is.
  - `reportRow()` adds `crev`, `ceps`, `reps` (consensus revenue, consensus EPS, reported EPS) to each quarter row.
- Run script: `run.sh <base|fix> <seed>`; env `YEARS` (20), `TPY` (360). Writes `runs/<arm>-<seed>.jsonl` (OUT)
  and `runs/<arm>-<seed>.macro.jsonl` (MACRO_OUT).
- Batch (4 seeds x 2 arms, ~2.5 min wall):

      bin/harness-batch var/harness/analyst_anchor/run.sh 1-4 base fix && \
        python3 var/harness/analyst_anchor/analyse.py > var/harness/analyst_anchor/anchor_4s20y.out && \
        cat var/harness/analyst_anchor/anchor_4s20y.out

- Analysis: `analyse.py [armA] [armB]`. Surprise s = rev / crev - 1 per report, first 4 reports per firm dropped.
  Per firm SD(s) and lag-1 autocorrelation, board medians, EPS beat rate (reps >= ceps), guards (output gap SD,
  pooled quarterly log price return SD split-adjusted, deaths). Paired by seed through `lib/stats.py`.
