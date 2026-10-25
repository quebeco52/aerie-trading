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
  - `off`, `on` (industry outlook, section e): `off` = working tree as committed; `on` = working tree + `ovr_on/`
    (IndustryOutlooks DEFAULT_DRIFT_VOL 0.08) with `OUTLOOK_SALT=1` (onStart pre-writes salt 1000+SEED). Every arm
    logs the outlook state per quarter to `runs/<arm>-<seed><TAG>.outlook.json` when the store has readOutlooks.
    Paths for this run: `TAG=-oc` (recorded on the 2a working tree), logs in `logs-oc/`.

      TAG=-oc LOGS=var/harness/idle/logs-oc BASE=<tree> bin/harness-batch var/harness/idle/run.sh 1-4 base off on
      TAG=-oc python3 var/harness/idle/analyse_shortage.py 1,2,3,4 off on; python3 var/harness/idle/analyse_outlook.py 1,2,3,4 on -oc

  - `off2`, `on2` (section f): working tree, outlook off / on (ovr_on + salt). `onbase` = `BASE` tree + ovr_on + salt,
    the outlook-on arm for a committed tree. The probe logs log(need/plant) per report (idle.json column 7) via the
    ledger's resolveTrendCapacityGap; `analyse_decline.py 1,2,3,4 onbase on2 -oc` uses it.
  - `cal` (section g): working tree + `ovr_cal/` (calibrated per-industry outlook table) + salt. Paths `-oc` cover
    seeds 1-8. `analyse_cal.py 1-8-list cal off -oc`: belief error and exp(x) SD by drift category, glut share, events.
  - `val`, `head` (section h): working tree / `BASE` tree, outlook as committed in src, salt 1000+SEED, `FVX=1`
    (fair value and cost of equity in the quarter rows; read-only, checked with `valnofvx`). The probe also logs
    realised vol accumulators (idle.json `rv`: sum sq tick log returns, n, configured vol) and per report the filed
    `carry:capacity_gap`, its catch-up growth and x_fv (columns 8-10). `analyse_val.py 1,...,8 head val -oc`.
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
