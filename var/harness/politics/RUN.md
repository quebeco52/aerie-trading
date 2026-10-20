# Politics gate

Harness: `gate.php` (macro + Diet, plain script, no fund, no equity cap). Usage:
`php gate.php <seedFrom> <seedTo> <years> <tpy> <arm> <out.jsonl>`; arms `off` (no politics) and `on` (Diet votes on its
own stream), plus `calendar|nocommittee|noregulator|step` (see the header). One JSON row per quarter.
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

## Debt rule (debtrule_an.py, 2026-10-20)
`STAGE=debtrule_4 YEARS=60 TPY=180 bin/harness-batch var/harness/politics/spend.sh 1-8 newon headon` (head_tree as above), then
`python3 var/harness/politics/debtrule_an.py var/harness/politics/runs/debtrule_4 5`: overshoot past a constant target, |debt - target|, primary balance, cycle.

## Fixed debt target (fixdebt_an.py, 2026-10-20)
`FIXED_DEBT=0.37,0.0094 STAGE=fixdebt_8 YEARS=60 TPY=180 bin/harness-batch var/harness/politics/spend.sh 1-8 newoff headoff`
(head_tree as above; copy the tree gate.php into it, since HEAD's lacks FIXED_DEBT and dtgt), then
`python3 var/harness/politics/fixdebt_an.py var/harness/politics/runs/fixdebt_8 [from to]`: first crossing, 15-year overshoot, years 30-60 deviation.

## Crisis severity (crisis_an.py, 2026-10-20)
gate.php writes an extra `"ev":"crisis"` row on each crisis tick (drag jump, built/req/ccyb going in, both drag scales).
`STAGE=crisis_4 YEARS=60 TPY=180 bin/harness-batch var/harness/politics/spend.sh 1-4 newon headon` (head_tree as above,
copy the tree gate.php into it), then `python3 var/harness/politics/crisis_an.py var/harness/politics/runs/crisis_4 5`.

## State identity across a refactor (state_identity.php, 2026-10-20)
`state_identity.php run <seedFrom> <seedTo> <years> <tpy> <states.jsonl> <pages.jsonl>` drives the macro engine and the
Diet as gate.php's `on` arm does and writes each seed-year's raw `PoliticsState::toArray()`, plus the government,
department, regulator and reserve page arrays and the headline contexts at the first tick, mid-run and the last year.
1. Run it on the baseline in a detached worktree (`git worktree add --detach <dir> <commit>`, `cp -a vendor <dir>/`,
   copy the script in; it requires its own tree's bootstrap) and on the working tree, one seed per process.
2. `state_identity.php compare head_states.jsonl tree_states.jsonl head_pages.jsonl tree_pages.jsonl` under the working
   tree: maps the per-head fields saved before `heads` into it, then demands every line and every page match exactly,
   and checks that `PoliticsState::fromArray()` reads each baseline line into the tree's byte for byte.
   3 seeds x 40 years at tpy 52: about 40 s a seed.
