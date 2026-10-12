# Political pressure: one give-in episode

This harness measures what one episode does when the Monetary Authority gives ground to the cabinet. It is the fit
behind `MonetaryPolicySubsystem::PRESSURE_RATE_CONCESSION` and `PRESSURE_ANCHOR_DRIFT_RATE`.

**Targets** (`pressure_evidence.md`):
- Gavin & Manger 2023: policy rate −30bp at 3q, inflation +0.5pp at 6q. These two are fitted.
- Drechsel 2024, Fig. 7: price level +2.9% at 4y and ≈+5% at 10y. This is a check only.

## Scripts
- `run.sh <head|new>-<episode> <seed>` runs `../politics/gate.php`, arm `concede`.
  - `head` reads `head_tree/`, made with `git worktree add --detach var/harness/pressure/head_tree <commit>` plus
    `cp -a vendor` into it. `new` reads this tree.
  - Episodes:
    - `base`: no episode, the paired baseline on the same code path. Bit-identical to gate's `off` arm.
    - `rand`: one episode from year 10 (STEP_AT), with its length drawn on `PoliticalPressure::CONTINUATION` per seed.
    - `L<n>`: exactly n quarters.
    - `off`: gate's no-politics arm.
- `bisect_tree.sh <label> <commit>` and `bisect_run.sh <label>-<episode> <seed>` run the same episodes through
  `episode_gate.php`. That script is the macro engine alone, with the policy DTO built by reflection, so it runs on every
  commit since 22d4bb9. Use it to find which commit moved the response.
- `python3 episode_an.py runs/<stage> [tree ...]` prints each arm minus its tree's `base`, paired by seed. It also prints:
  - `avg`: the `rand` arm post-stratified on the length each seed drew;
  - `wavg`: the `L1..L8` arms weighted by the geometric length distribution.
  - Fit on `avg` and confirm with `wavg`.
- `irf.sh` and `irf_an.py` are the 10-04 originals: 256 seeds, no `bin/php-slot`. They are superseded.

A 22-year run at TPY 180 takes about 2 s. Seeds 1-48 for `rand` and `base` take about 12 s wall.
- The base arm does not depend on the two constants, so copy `runs/<stage>/new-base_*` into each fitting stage.
- Edit `src/` only between batches. A run still waiting for a slot loads whatever is on disk when it starts.

    STAGE=e3 bin/harness-batch var/harness/pressure/bisect_run.sh 1-48 new-rand
    STAGE=e3 bin/harness-batch var/harness/pressure/bisect_run.sh 1-4 new-L1 new-L2 new-L3 new-L4 new-L5 new-L6 new-L8
    python3 var/harness/pressure/episode_an.py var/harness/pressure/runs/e3 new
