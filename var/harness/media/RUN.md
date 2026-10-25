# Broadcaster (BELL) behaviour check

Question family: does the broadcaster (BroadcastingBusinessModel, BELL) behave sensibly on the live board, beside the
ad agency LYRE.

- Harness: `var/harness/media/MediaHarnessTest.php` (forked from `consulting/`; extends `lib/BoardRun.php`; hooks
  onStart, afterStocks, reportRow, onEvent, onEnd; read-only). Writes `runs/<arm>-s<seed>.json` (`MEDIA_OUT`): opening,
  every quarter of BELL and LYRE with KPIs, stream revenue, EBIT, consensus and reported EPS, consensus revenue and the
  macro the model reads (gap EMA, policy uncertainty EMA, sentiment residual, totalTime), a daily price path with a
  cap-weighted board index, and `blackouts[firm]` (report times whose earnings text carries the blackout lore).
- Run script: `var/harness/media/run.sh <arm> <seed>` (one arm, `live`; env YEARS 20, TPY 360; live macro, no Diet).
- Batch: `bin/harness-batch var/harness/media/run.sh 1-4 live && python3 var/harness/media/analyse.py live
  > var/harness/media/live_4seed_20y.out` (about 2-3 min wall for 4 seeds).
- Analysis: `analyse.py [arm]` per-seed table and means, then BELL's political share by quarter of the four-year term.
- After adding a model class in `src/`, delete `var/harness/cache/test` (the container is compiled once).
- Evidence: `media_evidence.md`.
