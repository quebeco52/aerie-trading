# Polls harnesses

Run script: `form.sh <arm> <seed>` (takes its own `bin/php-slot`; outputs in `runs_form/`).

## Poll profile (poll error by months out; cabinet's polls vs result by month of term)
- `poll_profile.php <seeds> <years>` is serial (~6 s per seed-decade; 20 x 400y = 80 min). Use the shard:
  `poll_profile_shard.php <seed> <years>` prints one JSON line of [sum, n] per bucket.
- `bin/harness-batch var/harness/polls/form.sh 1-20 pshard && python3 var/harness/polls/poll_profile_merge.py 'var/harness/polls/runs_form/pshard_*.json'`
  (YEARS default 400; ~22 min wall on 8 slots). Tables: `poll_profile.out` (old), `poll_profile_new.out`.

## Vote moments, working tree vs a baseline commit (formation_run.php from ../politics)
1. `git worktree add --detach var/harness/politics/head_tree <commit>`; `cp -a vendor var/harness/politics/head_tree/`.
2. Write `head_tree/var/harness/politics/formation_run_h.php` = `formation_run.php` with the require made
   `require __DIR__ . "/../../../tests/bootstrap.php";` (sed the `^require` line), or the head arm runs the new code.
3. `bin/harness-batch var/harness/polls/form.sh 1-8 new head && python3 var/harness/polls/form_an.py var/harness/polls/runs_form`
   (YEARS default 200, TPY default 180; 16 runs ~15 min wall). Paired NEW - HEAD: Pedersen volatility, cabinet loss
   at the vote (pp), minority-cabinet share of formations. `OUT=<dir>` before the batch keeps a run apart from old ones.
   `form_an2.py <dir>` adds terms ended by a fall, single-party and largest-party-in-cabinet shares, and the coalition
   PM party's vote change split by the campaign growth gap; `form_slope.py <dir>` gives its slope on the gap.
4. `git worktree remove --force var/harness/politics/head_tree`.

`enp_an.py <dir>` adds ENP and the largest vote p90 by 40-year block, one-party majorities, days to government
   and second attempts (table `enp_16.out`).

Evidence: `polls_evidence.md`.

## Long-run lever means (PolicyCapitalization LONG_RUN_* and *_TERM_PERSISTENCE)
`bin/harness-batch var/harness/polls/form.sh 1-16 levers` (200y per seed, ~5 min wall), then
`php var/harness/polls/lever_means_an.php var/harness/polls/runs_form/levers_*.jsonl`. Measures come from
`PolicyCapitalization::measure()`, so a new priced lever needs no change here. Last: `lever_means_rework.out` (2026-10).
