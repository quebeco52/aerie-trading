# Rework harness (macro + government rework, 2026-10)

- `macro_moments.php <seed> <years> <out> [fund]`: full macro loop + politics, TPY 48, quarterly series. `fund=1`
  adds the Sovereign Reserve Fund with a synthetic board cap growing with nominal GDP.
- `run.sh <arm> <seed>`: arms `new`, `newfund` (working tree), `old`, `oldfund` (`old_tree` worktree). YEARS default 100.
- Batch: `bin/harness-batch var/harness/rework/run.sh 1-4 new newfund`
- Analyse: `python3 var/harness/rework/analyse.py var/harness/rework/runs new` (or `old new` for paired Δ, t).
- Old arm: `git worktree add --detach var/harness/rework/old_tree <commit>`; `cp -a vendor var/harness/rework/old_tree/`;
  `cp var/harness/rework/macro_moments.php var/harness/rework/old_tree/var/harness/rework/` (untracked files are not in
  the worktree). Remove with `git worktree remove --force var/harness/rework/old_tree`.

Evidence: `rework_evidence.md`.
