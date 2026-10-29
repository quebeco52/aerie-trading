# Finch Securities (FINC)

Question: does FINC, the retail broker that replaced ROOK on `BrokerageBusinessModel`, open at a sensible size and
behave sensibly on the live board?

- Harness: `FinchHarnessTest.php` (extends `lib/BoardRun.php`; read-only probe). Writes `runs/<arm>-s<seed>.json`
  (`FINCH_OUT`): FINC's opening (px, eps, bvps, revenue, equity, debt, rating, board cap) and every FINC quarter
  (rev, om, ni, eps, px, bvps, equity, debt, rating, streams, vol index, policy rate, output gap), reorgs, defaults,
  FINC's distress lines.
- Run script: `run.sh <arm> <seed>` (env YEARS 20, TPY 360; live macro, no Diet). The arm is only a label.
- Batch: `bin/harness-batch var/harness/finch/run.sh 1-4 live`
- Analysis: `python3 var/harness/finch/analyse.py [arm]`.
- After adding a model class or changing `config/`, delete `var/harness/cache/test`.
