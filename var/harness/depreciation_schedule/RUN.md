# depreciation_schedule: schedule vs units-of-production depreciation, paired full-board arms

Harness: `FullMarketHarnessTest.php`, a fork of `earnings_b1/` (stale `boardBankCredit:` macro arg removed) that also
records per report `dp` = [capacity utilization, seasonal factor, schedule D, charged D] and `ave` (announcement
variance EMA), per firm `ind`, `model`, `fin`, `seas`, and per run `src` (the files EarningsEngine, MiningBusinessModel,
the trait, Kernel and composer's ClassLoader were loaded from: check it names the arm's tree).

Each arm loads its OWN tree: `bootstrap.php` requires `$ROOT/tests/bootstrap.php`, `run.sh` runs `$ROOT/vendor/bin/phpunit`,
so composer resolves App\ to that tree's src. Nothing from the main checkout is loaded. The probe is an OVR copy of the
arm's own EarningsEngine.php, built by `make_ovr.sh <arm> <tree>` (it prints the diff: a wrapper round
calculateDepreciation(), no draws). Rebuild the ovr-* dirs and delete cache-*/ after any change in either tree.

Arms (`run.sh <arm> <seed>`; runs and macro paths in `$H`, default /tmp/claude-1000/depsched):
- `rec` base tree, live macro, records the macro path; `newlive` new tree, live macro (pair with rec).
- `base` base tree replaying rec's path; `new` new tree replaying it (the clean pair).
Trees: BASE_TREE (default /tmp/claude-1000/snap-base), NEW_TREE (default the depreciation-state worktree).
No politics, YEARS=15, TPY=360, ~60 s per run.

    for a in rec base new newlive; do var/harness/depreciation_schedule/make_ovr.sh $a <tree>; done   # once
    bin/harness-batch var/harness/depreciation_schedule/run.sh 1-8 rec && \
      bin/harness-batch var/harness/depreciation_schedule/run.sh 1-8 base new newlive && \
      python3 var/harness/depreciation_schedule/analyse.py /tmp/claude-1000/depsched 1,2,3,4,5,6,7,8

`analyse.py` prints board sanity (cap index end/start, max drawdown, failures) and per-group firm means for ops
(non-financial, non-producer) and producers (Mining, OilGasProducer models), paired by seed: delta, se, t.
EBIT margins are clipped to [-1, 1]; seasonal amplitude = peak-factor minus trough-factor quarter EBIT margin.
