# Time to build: growth capex queued as construction in progress

Question (10-28): with ordinary growth capex of non-financial firms queued as CIP (CapitalBudgetEngine, placed in
service at each sector's CAPEX_COMPLETION_RATE) instead of landing in PP&E at once, does the board show capacity
cycles (plant under construction, longer shortages, idle plant in gluts) without breaking board health?

- Harness: `TimeToBuildHarnessTest.php` = plain `BoardRun`; each quarter row adds `cip`, `cip_s`, `gppe`, `icap`,
  `ind`, `idle` (idle_plant_share KPI), `pl` (industry_price_level KPI); each MACRO_OUT row adds real GDP, potential,
  CPI, deflator. Read-only.
- Macro is LIVE (own stream; arms on one seed meet the same cycle, output gap differs ~1e-5), so the board's
  feedback to GDP is included.
- Run script: `run.sh <arm> <seed>`; YEARS (20), TPY (360), TAG. Outputs `runs/<arm>-<seed><TAG>.{jsonl,macro.jsonl,log}`.
  - `ttb`: working tree.
  - `base`: `BASE=<tree>`: `git archive HEAD | tar -x -C <scratchpad>/ttbbase-<sha>` and
    `ln -s <main checkout>/vendor <tree>/vendor` (a copied vendor fails: two composer autoloaders). Keep the tree
    outside the repo; a new tree needs a new basename (cache key). Delete `var/harness/cache-ttbbase-*` afterwards.
- Batch:

      BASE=<tree> bin/harness-batch var/harness/timetobuild/run.sh 1-8 base ttb \
        && python3 var/harness/timetobuild/analyse.py 1,2,3,4,5,6,7,8 > var/harness/timetobuild/ttb_n8_y20.out

- Analysis: `analyse.py <seeds> [A] [B]` (default base ttb): construction, idle, industry price level tails and
  persistence, board health, growth, absurd-firm checks; paired via `lib/stats.py`; then named distress events,
  industries ever idle, firms with CIP > 25% of invested capital for 8+ quarters, PP&E stalls. "2nd half" windows are
  t >= YEARS/2.

Results: `timetobuild_evidence.md`.
