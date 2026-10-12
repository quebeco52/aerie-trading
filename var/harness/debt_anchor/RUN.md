# Debt anchor (where gross debt settles under the Diet's fiscal rule)

Question: with the Bohn reaction on GROSS debt (two-sided, 0.10 x (debt - 0.70)) and net taxes giving back the policy
levies (PublicAccountsDTO::policyLevies()), where does gross debt/GDP settle? Sets MacroEngine::INITIAL_DEBT_TO_GDP.
Paired follow-up: does the change move debt, fund/GDP and the primary balance in tariff quarters vs HEAD.

- Harness: `var/harness/debt_anchor/FullMarketHarnessTest.php`, a fork of `var/harness/FullMarketHarnessTest.php`
  whose FISCAL=1 rows also carry `nfl` (net financial liabilities), `tariff`, `y10`, `levies` (null on trees without
  `policyLevies()`). Live macro, POLITICS=1 (the Diet sets tariffs), MACRO_OUT one row a quarter (+ founding row).
- Run script: `run.sh after|before <seed>` (YEARS 100, TPY 360 by default; takes its own php-slot).
  Output `runs/<arm>-<seed>.macro.jsonl`.
  - `after`: the main working tree.
  - `before`: HEAD snapshot at `<scratchpad>/da-before` (`BEFORE_TREE=` overrides), built by
    `bash var/harness/debt_anchor/make_before_tree.sh <dir>`: git archive HEAD + `cp -a .env* vendor` (never a
    symlink) + policy_fit.json/asym_fit.json. Runs with BASE=<tree>, its own bootstrap/src, cache
    `var/harness/cache-da-before/`. Rebuild if HEAD moves; check `diff -rq src <dir>/src` lists only the change.
- Delete `var/harness/cache/test` after a constructor or service change in src/.
- Batch (one seed-century ~9 min; 4 seeds 540 s wall):

      bin/harness-batch var/harness/debt_anchor/run.sh 1-4 after && \
        python3 var/harness/debt_anchor/analyse.py after 1-4 > var/harness/debt_anchor/after_s4_y100.out

  Paired stage (8 seeds x 2 arms ~20 min wall at 8 slots):

      bin/harness-batch var/harness/debt_anchor/run.sh 1-8 before after && \
        python3 var/harness/debt_anchor/paired.py 1-8 > var/harness/debt_anchor/paired_s8_y100.out
- Analysis: `analyse.py <arm> <a-b>`: per seed median/mean debt y30-100, debt y50/y100, OLS slope y20-100 (pp/decade),
  nfl and fund at y50/y100, crisis count, share of tariff quarters, net-tax range; pooled median/mean, primary balance
  (+ = surplus) in tariff>0 vs tariff=0 quarters, share at the 0.20 floor and above 0.90, NaN/negative checks.
- `paired.py <a-b>`: targets |slope| y20-100, tariff surplus (primary balance tariff>0 minus tariff=0), log fund/GDP
  y100; side checks; fund/GDP decomposition from the per-quarter flow columns (f_ret_idx, f_draw, f_stab, f_inflow,
  f_stamp summed in the harness each tick): (r-g)F + inflow + stamp - (draw+stab) + residual. Size n as unpaired
  (the harness is not deterministic run to run).
- `payout.py <a-b>`: draw/fund, stabilisation/fund and /GDP, draw share (law, applied, distribution), share of
  quarters above the Council line (rows from 2026-10-12 stage 16 on; seeds 1-8 lack draw_law/draw_share/council_line).
- 2026-10-12 debt-target lever: `before` = HEAD 1ba3a94 snapshot (rebuild with make_before_tree.sh when HEAD moves),
  `after` = working tree. Rows add debt_target, council_debt_line, anchor (after only), coal_state.
  `target.py <a-b>`: sd of debt y20-100, OLS slope of debt on the trailing 8y coalition state position (pp/unit),
  share above the Council line, side checks, capped-anchor share. Earlier runs (rule change) in `runs_v1/`; after arm at
  opening 0.72 in `runs_after072/`. Stage 16 (after opening 0.60): `target_s16_y100.out`.
- `runs_old0.93/`: the first 4 after runs, opened at debt 0.93 (before INITIAL_DEBT_TO_GDP = 0.72).
- Evidence: `debt_anchor_evidence.md`.
