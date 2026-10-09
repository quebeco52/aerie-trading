# Politics gate

Harness: `gate.php` (macro + Diet, plain script, no fund, no equity cap). Usage:
`php gate.php <seedFrom> <seedTo> <years> <tpy> <arm> <out.jsonl>`; arms `off` (no politics) and `on` (Diet votes on its
own stream), plus `calendar|nocommittee|noregulator|nopressure|step|concede` (see the header). One JSON row per quarter.
Note: gate.php `require`s the main checkout's tests/bootstrap.php by absolute path.

## Arms on one tree
`bin/harness-batch` is the batch tool; `run_gate.sh` is the old xargs-free parallel loop (kept, but prefer spend.sh-style).
Analysis: `python3 gate_an.py runs/<label> [burn]` reads `<label>/{on,off}_*.jsonl`, prints paired on - off and the lever stats
(spending share, partisan purchases per Diet unit, brake rounds a year, debt > 90% share).

## Working tree vs a baseline commit (spend.sh)
1. `git worktree add --detach var/harness/politics/head_tree <commit>`; `cp -a vendor var/harness/politics/head_tree/`
   (a symlinked vendor runs main's src).
2. In `head_tree/var/harness/politics/gate.php` replace the absolute bootstrap require with
   `require __DIR__ . '/../../../tests/bootstrap.php';`, or the head arms silently run the new code.
3. `STAGE=spend_16 bin/harness-batch var/harness/politics/spend.sh 1-16 newon newoff headon headoff`
   (YEARS default 100, TPY default 180; ~100 s per seed-century; 32 runs take ~3.5 min wall on 8 slots).
4. `python3 var/harness/politics/spend_an.py var/harness/politics/runs/spend_16` (paired NEW - HEAD table) and
   `python3 var/harness/politics/gate_an.py var/harness/politics/runs/spend_16/new` (lever stats on the new tree).
   `python3 var/harness/politics/mix_an.py var/harness/politics/runs/<stage>` (Council line distribution, spend, cts; new tree).
5. `git worktree remove --force var/harness/politics/head_tree`.

Outputs: `runs/<stage>/{new,head}/{on,off}_<seed>.jsonl`; tables in `spend_<n>.out`, `spend_<n>_new.out`.
Evidence: `politics_evidence.md`.
