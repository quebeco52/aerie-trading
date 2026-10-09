# Phase 2d: betas struck on total assets (cash counts toward the matched income beta)

## 2026-10-06: AFTER re-run only, BEFORE and macro paths reused from p2c

**Question.** resolveExpenseBeta and resolveMatchedFloatingShare now strike both betas on total assets (EA + treasury
cash), as DSS do. Do the lenders now sit on the DSS matching line (income beta = 0.379 + 0.768 (expense beta - 0.360))?
How much of what is left is the book itself, and how much the NII stream's volume channels (planned Phase 3)?

**Harness.** `runs/p2d/BankCreditHarnessP2dTest.php` is the p2c test plus a read-only per-tick probe. Before each
`updateStocks`, it records `iy` = `resolveInterestYield(stock, macro)` and `ea_pre` for each lender, on the book state
calculateSectorPhysics reads that tick. At capture it also records `iy_post`, `mds` (macro_demand_shift) and `vsh`
(the SLOOS, housing, M2 and credit-gap shifts, computed from the model's own constants by reflection). `PROBE=0` turns
the probe off. `runs/p2d/run_p2d.sh after|afternp <seed>`.
- Validity: HEAD is 38ea800. `diff -rq` of `wt_p2before/{src,config}` against `git archive HEAD` is empty, apart from the
  stray empty `.claude/.cc-writes` dir. `.env` is identical. Since p2c, only the bank model and its test have changed,
  and the macro classes are untouched, so the p2c macro-identity proof still holds. 16/16 macro_q identical.
- Probe is read-only: seed 1 with `PROBE=0` gives 480/480 lender quarters identical (excluding the new keys), the same
  macro and the same deaths.
- ReflectionClass: BEFORE bank model 5902b4de (scratchpad), p2c AFTER 8a6f02dc, p2d AFTER a7c46fc6 (working tree).
- The repo was unchanged: md5 of the 13 modified files and `git status --short` matched before and after.
- 16 seeds x 20 y x 360 tpy, all OK, 150-165 s per run. Opening floating shares were 0.351/0.212/0.823/0.332/0.333, as in the brief.

Analysis: `python3 runs/p2d/decompose_p2d.py > runs/p2d/phase2d_seeds1-16.out` (DSS table, decomposition, jackknife
slope). The full levels, outcomes and board table: `python3 runs/p2d/analyse_p2d.py > runs/p2d/p2d_full_seeds1-16.out`.

**DSS betas**, AFTER | vs BEFORE (se) | vs p2c AFTER (se):

| firm | income | expense | ROA | lending-only | line | income - line |
|---|---|---|---|---|---|---|
| LAKE | 0.319 \| +0.013 (0.013) \| -0.112 (0.011) | 0.272 \| +0.036 (0.005) | 0.059 \| -0.082 (0.014) | 0.230 | 0.311 | +0.007 (0.017) |
| RIVR | 0.341 \| -0.042 (0.021) \| -0.099 (0.020) | 0.274 \| +0.070 (0.006) | 0.096 \| -0.040 (0.015) | 0.249 | 0.313 | +0.028 (0.012) |
| PLVR | 0.347 \| +0.062 (0.017) \| -0.022 (0.011) | 0.271 \| +0.119 (0.007) | 0.108 \| +0.032 (0.042) | 0.257 | 0.311 | +0.036 (0.016) |
| TALN | 0.498 \| +0.192 (0.031) \| -0.054 (0.049) | 0.354 \| +0.155 (0.019) | 0.171 \| -0.045 (0.028) | 0.410 | 0.374 | +0.124 (0.021) |
| STRK | 0.510 \| +0.049 (0.116) \| -0.058 (0.077) | 0.354 \| +0.164 (0.015) | 0.193 \| -0.004 (0.086) | 0.421 | 0.374 | +0.135 (0.058) |
| POOL | 0.963 \| +0.049 (0.045) \| +0.044 (0.058) | 0.454 \| -0.012 (0.015) | 0.144 \| +0.028 (0.027) | 0.479 | 0.451 | +0.512 (0.059) |

**Decomposition of the AFTER income beta** (income = book + cash + resid; seed-clustered se):

| firm | book | cash | resid | of which named volume shifts | gap to line: book+cash-line | gap: resid |
|---|---|---|---|---|---|---|
| LAKE | 0.219 (0.002) | 0.089 | +0.011 (0.007) | +0.026 (0.003) | -0.004 (0.013) | +0.011 (0.007) |
| RIVR | 0.207 (0.003) | 0.091 | +0.042 (0.011) | +0.033 (0.003) | -0.014 (0.013) | +0.042 (0.011) |
| PLVR | 0.237 (0.002) | 0.090 | +0.020 (0.008) | +0.023 (0.003) | +0.016 (0.013) | +0.020 (0.008) |
| TALN | 0.318 (0.004) | 0.087 | +0.092 (0.022) | +0.068 (0.004) | +0.032 (0.012) | +0.092 (0.022) |
| STRK | 0.357 (0.011) | 0.088 | +0.064 (0.065) | +0.097 (0.006) | +0.071 (0.013) | +0.064 (0.065) |
| POOL | not wired | 0.484 (0.018) | +0.479 (0.059) | n/a | +0.033 (0.021) | +0.479 (0.059) |

Book = 100 iy EA_pre/TA. Price-only (iy x mean EA/TA) is about 0.03 (banks) to 0.08 (STRK) below book; the gap is
the EA/TA mix. macro_demand_shift makes up 0.009-0.042 of the named shifts. POOL's ShadowBank stream does not read
resolveInterestYield (it carries an ad-hoc `max(0, (policy - 0.03) x 1.5)` bonus), and its "lending" is total revenue.

**Cross-firm slope** (income on expense beta, delete-one-seed jackknife se; DSS 0.77-0.88): 5 lenders ex POOL, BEFORE
+0.15 (0.25), p2c +1.83 (0.45), p2d +2.07 (0.43). For the 3 banks it is ill-posed (expense betas 0.271-0.274):
+0.10 (9.01).

**Board.** Bankruptcies per seed: BEFORE 0.06, AFTER 0.25, diff +0.19 (0.14), inside noise. BEFORE: CASC on seed 4
(y16.2). AFTER: TIER on seeds 8 (y16.0), 9 (y19.0) and 14 (y19.0), and FALC on seed 5 (y3.7). Live rec: TIER on seeds 9 and 14.
No non-finite values. Lender ROE TTM mean vs BEFORE: LAKE -4.3 (0.4), RIVR -1.5 (0.3), PLVR -0.6 (0.3), TALN -3.5 (0.6),
STRK -3.3 (0.4) pp. Hike-8 PPNR is lower for LAKE, RIVR and PLVR (-0.43/-0.39/-0.23 pp). ROE p5, CET1 min and hike-8 are
tail cells, indicative at n=16.

**Caveats.** The named volume shifts are book x (mds + shifts), a first-order approximation to the multiplicative
stream. The banks' resid is not separable from the shifts at their se. EA in the book is pre-tick EA.
