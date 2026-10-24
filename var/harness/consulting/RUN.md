# Consulting firms (GDWT, TSTN) behaviour check

Question family: do the consulting firms (ConsultingBusinessModel) behave sensibly on the live board, beside CLAW and
LYRE.

- Harness: `var/harness/consulting/ConsultingHarnessTest.php` (extends `lib/BoardRun.php`; hooks onStart, afterStocks,
  reportRow, onEvent, onEnd; read-only). Writes `runs/<arm>-s<seed>.json` (`CONSULT_OUT`): opening, every quarter of
  the four firms with KPIs, stream revenue and the macro the bookings read (gap EMA, credit spread EMA over trend, deal
  and default deviations), a daily price path with a cap-weighted board index, industry/sector revenue per quarter.
- Run script: `var/harness/consulting/run.sh <arm> <seed>` (one arm, `live`; env YEARS 20, TPY 360).
- Batch: `bin/harness-batch var/harness/consulting/run.sh 1-4 live && python3 var/harness/consulting/analyse.py live`
  (about 90 s per seed-20y).
- Analysis: `analyse.py [arm]` per-seed table and means; `slopes.py <arm>...` revenue-on-gap slopes (NW SE).
- After adding a model class in `src/`, delete `var/harness/cache/test` (the container is compiled once).
- Evidence: `consulting_evidence.md`.
