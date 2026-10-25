# Grocer (SPRW) behaviour check

Question family: does the grocer (GroceryBusinessModel, SPRW) behave sensibly on the live board, beside SGRB (packaged foods)
and LARK.

- Harness: `var/harness/grocery/GroceryHarnessTest.php` (forked from `airline/`; extends `lib/BoardRun.php`; read-only).
  Writes `runs/<arm>-s<seed>.json` (`GROCERY_OUT`): opening, every quarter of SPRW, SGRB, LARK (KPIs, streams, EBIT,
  consensus and reported EPS, gap EMA, `ecp` = energyCostPushLag, `agri` = agri index EMA, `ddg` = domestic demand gap EMA, sentiment residual, totalTime), a daily price path
  with a cap-weighted board index, `wars[firm]` (report times whose earnings text carries the price-war onset lore), and the
  run record's `reorgs`, `defaults` and treasury `distress_lines`.
- Run script: `var/harness/grocery/run.sh <arm> <seed>` (env YEARS 20, TPY 360; live macro, no Diet).
- Batch: `bin/harness-batch var/harness/grocery/run.sh 1-4 live && python3 var/harness/grocery/analyse.py live
  > var/harness/grocery/live_4seed_20y.out` (about 2 min wall).
- Opening probe: `bin/harness-batch var/harness/grocery/open_probe.sh 1 base` (0.1 year; `opening` and `cap1` in
  `runs/open_base-s1.json`). Seed-data variants: `make_ovr.sh` writes `ovr/<variant>/Data/Company/InitialMarket.php`
  (none yet; copy airline/make_ovr.sh and fix its line numbers); run `open_probe.sh <variant> 1`.
- After adding a model class in `src/`, delete `var/harness/cache/test` (the container is compiled once).
- Evidence: `grocery_evidence.md`.
