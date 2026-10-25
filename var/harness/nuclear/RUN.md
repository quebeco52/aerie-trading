# Reactor vendor (FIRE) behaviour check

Question family: does the reactor vendor (NuclearVendorBusinessModel, FIRE) behave sensibly on the live board, beside GRIP (defence)
and DIPR (power producer).

- Harness: `var/harness/nuclear/NuclearHarnessTest.php` (forked from `grocery/`; extends `lib/BoardRun.php`; read-only).
  Writes `runs/<arm>-s<seed>.json` (`NUCLEAR_OUT`): opening, every quarter of FIRE, GRIP, DIPR (KPIs, streams, EBIT,
  consensus and reported EPS, gap EMA, `ecp` = energyCostPushLag, `agri` = agri index EMA, `ddg` = domestic demand gap EMA, sentiment residual, totalTime), a daily price path
  with a cap-weighted board index, `accidents`, `overruns` (onset lore in the earnings text), and per quarter the regime states and FIRE's order-probability terms (`drivers`: energy, financing, carbon, fx, p from the model itself), and the
  run record's `reorgs`, `defaults` and treasury `distress_lines`.
- Run script: `var/harness/nuclear/run.sh <arm> <seed>` (env YEARS 20, TPY 360; live macro, no Diet).
- Batch (at the x0.74 seed): `OVR=<abs>/var/harness/nuclear/ovr/scale74 bin/harness-batch var/harness/nuclear/run.sh 1-4 live && python3 var/harness/nuclear/analyse.py live
  > var/harness/nuclear/live_4seed_20y.out` (about 2 min wall).
- Opening probe: `bin/harness-batch var/harness/nuclear/open_probe.sh 1 base` (0.1 year; `opening` and `cap1` in
  `runs/open_base-s1.json`). Seed-data variants: `make_ovr.sh` writes `ovr/<variant>/Data/Company/InitialMarket.php`
  (`make_ovr.sh` writes `scale74`, FIRE x0.74 incl. sam; it edits fixed lines 2872-2905); run `open_probe.sh <variant> 1`.
- After adding a model class in `src/`, delete `var/harness/cache/test` (the container is compiled once).
- Evidence: `nuclear_evidence.md`.
