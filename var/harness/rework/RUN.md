# Rework harness (macro + government rework, 2026-10)

- `macro_moments.php <seed> <years> <out> [fund]`: full macro loop + politics, TPY 48, quarterly series. `fund=1`
  adds the Sovereign Reserve Fund with a synthetic board cap growing with nominal GDP.
- `run.sh <arm> <seed>`: arms `new`, `newfund` (working tree), `old`, `oldfund` (`old_tree` worktree). YEARS default 100.
- Batch: `bin/harness-batch var/harness/rework/run.sh 1-4 new newfund`
- Analyse: `python3 var/harness/rework/analyse.py var/harness/rework/runs new` (or `old new` for paired Δ, t).
- Old arm: `git worktree add --detach var/harness/rework/old_tree <commit>`; `cp -a vendor var/harness/rework/old_tree/`;
  `cp var/harness/rework/macro_moments.php var/harness/rework/old_tree/var/harness/rework/` (untracked files are not in
  the worktree). Remove with `git worktree remove --force var/harness/rework/old_tree`.
- `macro_moments.php` also records sloosTighteningIndex, recessionProbability, inversionDuration, tipsBreakeven and
  `fxTicks` (sum |dlog FX|, tick count, rate-change count on rate-meeting ticks vs other ticks).
- Macro-review step 1 (2026-10-16): `run_step1.sh` arms `s1new`/`s1newfund` (this worktree) and `s1base`/`s1basefund`
  (a clean export of HEAD at BASE, default /tmp/claude-1000/base, with vendor and macro_moments.php copied in; no
  worktree needed). `bin/harness-batch var/harness/rework/run_step1.sh 1-4 s1base s1new s1basefund s1newfund`, then
  `python3 var/harness/rework/analyse_step1.py var/harness/rework/runs` (paired table, |t|>2 flagged).

Evidence: `rework_evidence.md`.
- Macro-review step 2 (2026-10-16): `run_step2.sh` arms `s2base`/`s2basefund` (export of HEAD at BASE, default
  /tmp/claude-1000/base2) and `s2new`/`s2newfund`. `bin/harness-batch var/harness/rework/run_step2.sh 1-4 s2base s2new
  s2basefund s2newfund`, then `python3 var/harness/rework/analyse_step2.py var/harness/rework/runs` (needs
  `step2_extra.py` beside it: GDP q/q sd/acf1, inventory gap, PMI, reimbursement growth and drift vs CPI).
