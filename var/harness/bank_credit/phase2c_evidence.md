# Phase 2c: bank and card betas after the A+B+C calibration

## 2026-10-06: A (system deposit beta), B (floating share solved to the DSS matching line), C (FDIC fee yields)

**Question.** After A+B+C, do the five lenders hit DSS (2021): income beta 0.379, expense beta 0.360, ROA beta about 0,
and a cross-firm matching slope of 0.77-0.88? Paired against Phase 1 (HEAD 38ea800).

**Harness.** `runs/p2c/BankCreditHarnessP2cTest.php` is the P2 test plus `state:floating_loan_share` in `bk` and
MathUtility, MacroEngine and MonetaryPolicySubsystem in `class_files`. `runs/p2c/run_p2c.sh after <seed>`. BEFORE and
rec are the P2 runs, copied unchanged into `runs/p2c/`: scratchpad tree `wt_p2before`, macro paths in scratchpad `p2_macro`.
- Validity: HEAD is still 38ea800. `diff -rq` of `wt_p2before/src` and `config` against `git archive HEAD` is empty
  (only a stray empty `.claude/.cc-writes` dir). `.env` is identical to the working one.
- The (A) macro is bit-identical. `runs/p2c/macro_same.php` runs the macro loop only, 16 seeds x 20 y x 360 tpy, with
  no equity cap. It hashes every quarterly MacroState, and the hashes are the same on 16/16 seeds in both trees
  (`macro_same_seeds1-16.out`). Replaying the P2 path is therefore valid.
- Run: 16 seeds x 20 y x 360 tpy, `mt_srand(seed)`, replaying the P2 path. 16/16 OK, 115-175 s per run. The macro path
  was identical in both arms on 16/16 seeds.
- ReflectionClass: BEFORE loaded bank-model md5 5902b4de from the scratchpad; AFTER loaded 8a6f02dc from the working tree.
- The repo was unchanged: md5 of the 13 modified files OK, and `git status --short` matched before and after.
- Opening state matches the brief: floating share 0.54/0.33/1.00/0.46/0.45, spreads +0.31/+1.67/-0.28/+8.9/+13.5%,
  fees 2.07/1.17/1.47/4.68/2.85%.

Analysis: `python3 runs/p2c/analyse_p2c.py > runs/p2c/phase2c_seeds1-16.out`, and `diag_p2c.py > diag_p2c.out`, which
splits the income beta into the lending stream and interest on cash.

**DSS betas**, AFTER | diff vs Phase 1 (se):

| firm | income | expense | ROA | lending-only income | line(expense) | income residual to line |
|---|---|---|---|---|---|---|
| LAKE | 0.430 \| +0.125 (0.007) | 0.275 \| +0.038 (0.006) | 0.152 \| +0.011 (0.012) | 0.341 | 0.314 | +0.117 |
| RIVR | 0.439 \| +0.057 (0.022) | 0.273 \| +0.069 (0.006) | 0.179 \| +0.043 (0.015) | 0.349 | 0.313 | +0.127 |
| PLVR | 0.369 \| +0.084 (0.015) | 0.272 \| +0.119 (0.007) | 0.099 \| +0.023 (0.019) | 0.279 | 0.311 | +0.058 |
| TALN | 0.552 \| +0.246 (0.038) | 0.351 \| +0.152 (0.019) | 0.205 \| -0.012 (0.032) | 0.464 | 0.372 | +0.180 |
| STRK | 0.568 \| +0.107 (0.096) | 0.357 \| +0.167 (0.014) | 0.224 \| +0.027 (0.069) | 0.480 | 0.377 | +0.191 |
| POOL | 0.918 \| +0.004 (0.065) | 0.472 \| +0.006 (0.009) | 0.109 \| -0.008 (0.035) | 0.417 | 0.465 | +0.453 |

Interest on cash adds +0.09 to the income beta of every lender (POOL +0.50). The matching solve leaves it out.
On the lending stream alone, the banks sit within 0.03 of the line, and the card lenders sit +0.09 to +0.10 above it.

Cross-firm slope of income beta on expense beta (no se): 3 banks +20.9, which is ill-posed because (A) gives all three
the same expense beta, 0.272-0.275. Across the 5 lenders ex POOL it is +1.83, against 0.77-0.88.

**Levels and outcomes**: see `runs/p2c/phase2c_seeds1-16.out` (full table). Card-lender NII/EA is in `diag_p2c.out`.
The ROE p5, CET1 min and hike-8 cells are tail cells, indicative at n=16.

**Board.** Bankruptcies per seed were 0.06 in both arms (diff 0.00, se 0.09). BEFORE: CASC on seed 4 (y16.2). AFTER:
TIER on seed 2 (y14.3). Live rec: TIER on seeds 9 and 14. The previous Phase 2 AFTER killed TIER on 2, 6, 9 and 14;
three of the four went with A+B+C. The macro path, credit spreads included, is replayed identically, so any bank link
runs through board coupling, not the macro. TIER sits at a threshold. No non-finite values in either arm.

**Caveats.** The BEFORE arm ran the P2 harness file, which records the same fields except the new `bk` key and the
extra class_files. The DSS income beta here includes interest on cash, below EBIT.
