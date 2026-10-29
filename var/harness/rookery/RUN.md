# Rookery Holdings (ROOK)

Question: does ROOK, the exchange group on `ExchangeOperatorBusinessModel` (Aerie Exchange + Commodity Exchange, 20%
District stake), open at a sensible size and behave sensibly on the live board? Do its streams read the measured
board turnover (`boardTurnoverEma`/`Trend`), equity wealth and the commodity basket's realised variance?

- Harness: `RookeryHarnessTest.php` (extends `lib/BoardRun.php`; read-only probe). Writes `runs/<arm>-s<seed>.json`
  (`ROOKERY_OUT`): ROOK's opening (px, eps, bvps, revenue, equity, debt, rating, board cap) and every ROOK quarter
  (rev, om, ni, eps, px, bvps, equity, debt, rating, streams, vol index, policy rate, output gap, turnover EMA/trend
  and deviation, equity wealth and deal activity deviations, board cap, persisted commodity variance and trend,
  public float), reorgs, defaults, ROOK's distress lines.
- Run script: `run.sh <arm> <seed>` (env YEARS 20, TPY 360; live macro, no Diet). The arm is only a label.
- Batch: `bin/harness-batch var/harness/rookery/run.sh 1-4 live`
- Analysis: `python3 var/harness/rookery/analyse.py [arm]` (run from this directory).
- After adding a model class or changing `config/`, delete `var/harness/cache/test`.
- Arms run 10-29: `live` (first build: commodity trend opened on one sample, 10-17x spikes; roic .25) and `fix`
  (running-mean opening, elasticity 0.30, roic .20). `fix` is the shipped state.
