# Staples harness

Question family: what a ConsumerStaplesBusinessModel change does to PHIL, SGRB, CROP, LARK, PINT on the whole board.

- Harness: `var/harness/staples/StaplesHarnessTest.php`, a fork of `var/harness/FullMarketHarnessTest.php` (same env,
  see `var/harness/full/RUN.md`). Extra output per run: `st_px` (staples price every tick), `st_eps` (consensus and
  Street EPS per report, read at the end because the subscriber sets them after persist), `st_earn` (earnings-day
  change % with a recall/fine flag from the lore line), `idx_log`/`idx_div` (summed log board price return and
  dividend yield over float cap).
- Run script: `var/harness/staples/run.sh <arm> <seed>`; YEARS (20), TPY (360).
  - `rec`: baseline tree, live macro, records `macro/path-<seed>.gz`.
  - `base`: baseline tree, replays that path. `new`: the working tree, replays it.
  - Both arms pass BASE=<tree>, so each loads its own src and config (and its own container cache).
  - Baseline tree: `cp -a` the worktree to `/tmp/claude-1000/staples-base` (BASEDIR), drop `.git`, reverse the patch,
    and replace its `vendor` with a symlink to the worktree's vendor (a copied vendor redeclares the Composer loader).
- Batch: `bin/harness-batch var/harness/staples/run.sh 1-4 rec && bin/harness-batch var/harness/staples/run.sh 1-4 base new
  && python3 var/harness/staples/analyse.py > var/harness/staples/<name>.out`
- Analysis: `analyse.py [arms]` prints one table per arm (seed 99 is the smoke seed and skipped).
- Runtime: about 5 s per simulated year per run.
- The Write/Edit guard keys on the main checkout's var/harness; in a worktree, edit a copy under $TMPDIR and `cp` it in.
