# Idle-capacity channel: no-change check

Question family: does the idle-capacity channel (price floor + `plantLoad` < 1, KPI `idle_plant_share`) change the
board, and where does it bind?

- Harness: `IdleHarnessTest.php` = the plain `BoardRun` plus a read-only `reportRow()` that logs per firm-quarter
  `[t, ticker, industry, idle_plant_share, industry_price_level]` to `IDLE_OUT` (JSON). The OUT record is untouched.
- Run script: `run.sh <arm> <seed>`; YEARS (20), TPY (360). Outputs `runs/<arm>-<seed>.{jsonl,log,idle.json}`,
  macro paths in `macro/`.
  - `rec`: working tree, records the macro path.
  - `idle`: working tree, replays it.
  - `base`: `basetree-cf0e6108/` (`git archive cf0e6108` + `vendor` symlink; override with `BASE=<tree>`), replays it.
  - `ovr`: working tree with the src copies in `ovr/` in front (bootstrap `OVR`); now CapitalBudgetEngine with
    growth capex in HEAD's multiplication order.
  - `plain`: working tree, `FullMarketHarnessTest.php` replay, to check the probe is read-only.
- Batch:

      bin/harness-batch var/harness/idle/run.sh 1-4 rec && bin/harness-batch var/harness/idle/run.sh 1-4 base idle \
        && python3 var/harness/idle/analyse.py 1,2,3,4 > var/harness/idle/nochange.out

- Analysis: `analyse.py [seeds]` prints per-seed OUT identity (base vs idle, `secs` dropped, first differing path),
  the idle-arm KPI counts, and, only if records differ, paired targets (end board cap, median op margin,
  defaults+deaths, share of firm-quarters rated B or worse) via `lib/stats.py`.

Gotchas: a new base tree needs a new directory name (the kernel cache is keyed on its basename). After a service change
in `src/`, delete `var/harness/cache/test`. Rebuild the base tree if HEAD moves.
