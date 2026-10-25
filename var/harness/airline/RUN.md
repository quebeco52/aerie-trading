# Airline (TERN) behaviour check

Question family: does the airline (AirlineBusinessModel, TERN) behave sensibly on the live board, beside the logistics
firm CANV.

- Harness: `var/harness/airline/AirlineHarnessTest.php` (forked from `media/`; extends `lib/BoardRun.php`; read-only).
  Writes `runs/<arm>-s<seed>.json` (`AIRLINE_OUT`): opening, every quarter of TERN and CANV (KPIs, streams, EBIT,
  consensus and reported EPS, gap EMA, `ecp` = energyCostPushLag, sentiment residual, totalTime), a daily price path
  with a cap-weighted board index, `strikes[firm]` (report times whose earnings text carries the strike lore), and the
  run record's `reorgs`, `defaults` and treasury `distress_lines`.
- Run script: `var/harness/airline/run.sh <arm> <seed>` (env YEARS 20, TPY 360; live macro, no Diet).
- Batch: `bin/harness-batch var/harness/airline/run.sh 1-4 live && python3 var/harness/airline/analyse.py live
  > var/harness/airline/live_4seed_20y.out` (about 2 min wall).
- Opening probe: `bin/harness-batch var/harness/airline/open_probe.sh 1 base` (0.1 year; `opening` and `cap1` in
  `runs/open_base-s1.json`). Seed-data variants: `make_ovr.sh` writes `ovr/<variant>/Data/Company/InitialMarket.php`
  (it edits fixed line numbers of TERN's block: check them after InitialMarket changes); run `open_probe.sh <variant> 1`.
- After adding a model class in `src/`, delete `var/harness/cache/test` (the container is compiled once).
- Evidence: `airline_evidence.md`.
