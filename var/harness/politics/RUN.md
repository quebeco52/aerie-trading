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

## Normal-vote drift (walk_fit.py, walk_an.py; 2026-10-09)
- `python3 walk_fit.py`: fits ParlGov's normal-weighted variogram, keeping parties averaging 5% or more.
  - It fits the walk on top of the Diet's short-run fit, with a party bootstrap.
  - This produces `PoliticsEngine::NORMAL_VOTE_DRIFT_VARIANCE`.
- `python3 long_vario.py`: the data variogram out to 75-year gaps, in 5-year bins.
- Runs: `formation_run.php <seedFrom> <seedTo> 200 180 formation/walk_<arm>_<i>.jsonl`.
  - Use 6 shards and `bin/php-slot`. One 16-seed arm takes about 25 minutes.
  - `formation_run.php` requires the main checkout's `tests/bootstrap.php`. A baseline arm has to run before `src/` changes. Its
    classes load in the first ticks, so editing after about a minute is safe.
- `python3 walk_an.py 'formation/walk_base_*.jsonl' 'formation/walk_new_*.jsonl' [until-year]` prints a paired table.
  - It is NEW − BASE at gaps of 4, 8, 20, 40 and 80 years, plus Pedersen volatility.
  - It uses the same selection as the fit: parties averaging 5% or more, results of 1% or more. Without that selection, rump
    parties dominate the weighted variogram.
