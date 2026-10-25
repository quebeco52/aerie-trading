# Idle-capacity channel: no-change check

Question family: does the idle-capacity channel (price floor + `plantLoad` < 1, KPI `idle_plant_share`) change the
board, and where does it bind?

- Harness: `IdleHarnessTest.php` = the plain `BoardRun` plus a read-only `reportRow()` that logs per firm-quarter
  `[t, ticker, industry, idle_plant_share, industry_price_level]` to `IDLE_OUT` (JSON). The OUT record is untouched.
- Run script: `run.sh <arm> <seed>`; YEARS (20), TPY (360). Outputs `runs/<arm>-<seed>.{jsonl,log,idle.json}`,
  macro paths in `macro/`.
  - `rec`: working tree, records the macro path.
  - `idle`: working tree, replays it.
  - `fix`, `fix2`: same as `idle` (working tree), named per variant (fix2 = growth gates on WACC, section d).
    `base` outputs for seeds 1-16 (3c79f9c4) are cached in runs/; reuse them while HEAD:src and the harness hold.
  - `base`: `BASE=<tree>` required: `git archive <commit>` + `vendor` symlink, kept OUTSIDE the repo (scratchpad) so
    git and PHPStan do not see it; delete it and `var/harness/cache-<basename>` afterwards.
  - `ovr`: working tree with src copies under `ovr/` in front (bootstrap `OVR`); `ovr/` currently deleted.
  - `probe`: working tree + `ovr_probe/` (CapitalBudgetEngine copy that logs the growth-capex gate for
    `PROBE_TICKER`, default FALC, into IDLE_OUT `probe`). STALE since section d: re-copy it from src before use.
    `analyse_probe.py <seed> <ticker> <y0> <y1>` prints per-year gate and funding and checks probe OUT == fix OUT.
  - `TAG=-y40 YEARS=40 LOGS=var/harness/idle/logs-y40`: separate macro paths/outputs for a 40-year horizon;
    `analyse_y40.py 1,2 -y40` gives ND/EBITDA every 5 years.
  - `plain`: working tree, `FullMarketHarnessTest.php` replay, to check the probe is read-only.
- Batch:

      bin/harness-batch var/harness/idle/run.sh 1-4 rec && bin/harness-batch var/harness/idle/run.sh 1-4 base idle \
        && python3 var/harness/idle/analyse.py 1,2,3,4 > var/harness/idle/nochange.out

- Shortage-capex batch (macro paths in `macro/` reused):

      BASE=<scratchpad>/basetree-<sha> bin/harness-batch var/harness/idle/run.sh 1-4 base fix \
        && python3 var/harness/idle/analyse_shortage.py 1,2,3,4 base fix > var/harness/idle/shortage_capex.out

  `NAMED=LOON,... analyse_shortage.py [seeds] [A] [B]`: industry price level tails (y10-20, non-financials), idle counts, capex/rev,
  ND/EBITDA, payout, cap, margin, defaults, B-or-worse, SKYL real revenue multiple, named firms, revenue movers.
  The probe also logs CPI and opening revenue (`IDLE_OUT` `opening`).
- Analysis: `analyse.py [seeds]` prints per-seed OUT identity (base vs idle, `secs` dropped, first differing path),
  the idle-arm KPI counts, and, only if records differ, paired targets (end board cap, median op margin,
  defaults+deaths, share of firm-quarters rated B or worse) via `lib/stats.py`.

Gotchas: a new base tree needs a new directory name (the kernel cache is keyed on its basename). After a service change
in `src/`, delete `var/harness/cache/test`. Rebuild the base tree if HEAD moves.
