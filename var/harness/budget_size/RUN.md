# Budget size (PUBLIC_PURCHASES_TO_GDP 0.21 vs 0.15)

Question: what shrinking public purchases from 21% to 15% of GDP does to the cycle and to public debt.

- Arms: `new` = main working tree (0.15); `old` = HEAD worktree + main's modified/untracked src/ tests/ var/harness/
  code + copied vendor + policy_fit.json/asym_fit.json, with only `PUBLIC_PURCHASES_TO_GDP` put back to 0.21.
  Build it with `bash var/harness/budget_size/make_old_tree.sh <scratch>/old` (prints `diff -r src`; must be the one
  line). It also clears both trees' harness caches. `run.sh` and `all.sh` hard-code the scratch path of the old tree
  (`OLD_TREE=` overrides in run.sh; edit all.sh).
- A (cycle moments): `var/harness/stabiliser_double_count/loopfit_tree.sh <tree> <tag> 36` per tree,
  `LOOPFIT_DIR=var/harness/budget_size/runs/A` (CreditMacroProbe 120y + rate-path probes, 360 tpy).
  Analysis: `python3 var/harness/budget_size/paired_a.py runs/A runs/A` (paired.py plus `gsd`, the sd of annual real
  output growth from pot x (1 + gap_avg)).
- B (debt, brake): `bin/harness-batch var/harness/budget_size/run.sh 1-16 new old` = FullMarketHarnessTest live,
  POLITICS=1, FISCAL=1 (adds `primary`, `net_tax`, `spend_idx` to MACRO_OUT and a tick-1 founding row), 60y, 360 tpy.
  Analysis: `python3 var/harness/budget_size/paired_b.py var/harness/budget_size/runs`.
- Both at once: `bash var/harness/budget_size/all.sh` (~70 min, all through bin/php-slot) -> `result.out`.
- Evidence: `budget_size_evidence.md`. Remove the worktree afterwards (`git worktree remove --force <scratch>/old`).
