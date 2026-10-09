# Phase 2 evidence: bank and card revenue from a repricing book yield

## 2026-10-06: earning assets x (repricing book interest yield + fee yield) for the banks and card lenders

**Question.** What does the uncommitted Phase 2 change (four src files vs HEAD 38ea800: `CommercialBankBusinessModel`,
`CreditServicesBusinessModel`, `ModelParam`, `StockModelTuning`) do on the same seeds and the same macro path? It adds
a per-firm book yield in three parts (residential 9.5y, other loans 2.4y with half floating, securities 8.4y, from
DSS 2021 Table A.2), a loan spread and fee yield struck once at the opening macro, prime-indexed cards (TALN floating,
STRK fixed), and it drops the NIM slope squeeze. Targets (DSS 2021): income beta 0.379, expense beta 0.360, ROA beta
about 0, a bank range of 0.1-0.6, and a slope of income beta on expense beta of 0.77-0.88. NIM on the policy-rate
level: about +0.08 per pp (Claessens et al., second-hand).

**Harness.** `runs/p2/BankCreditHarnessP2Test.php` is a copy of P1. It adds `bk` (the five `state:*` book keys from
earningsMomentumZ, empty in BEFORE), `y5` and `y30` to each quarter row. Bootstrap `runs/p2/bootstrap.php` is the P1
copy. `runs/p2/run_p2.sh <rec|before|after> <seed>`:
- rec = BEFORE tree, live macro, records the path to scratchpad `p2_macro/path-<seed>.gz`
- before = BEFORE tree replaying that path. The tree is scratchpad `wt_p2before`: the working tree rsync'd with the
  four files replaced by `git show HEAD:<path>`. `diff -rq` against `git archive HEAD src` is empty (only an
  untracked `.claude/.cc-writes` dir). It has a vendor symlink and the user's working `.env`.
- after = working tree replaying that path

Run with `seq 1 16 | xargs -P 8 -I{} runs/p2/run_p2.sh rec {}`, then 32 before/after runs at `-P 8`. That is 16 seeds
x 20 y at 360 tpy with `mt_srand(seed)`. 48/48 runs OK; 115-126 s per run, 17.5 min wall.
- The macro path was identical in both arms on 16/16 seeds.
- ReflectionClass: every BEFORE and rec run loaded the scratchpad tree, with the bank model md5 5902b4de (HEAD's).
  Every AFTER run loaded the working tree (a8cf36cc).
- The repo was unchanged afterwards: `repo_files_before.md5` OK, the md5 of src/config/templates/tests matched, and
  `git status --porcelain` matched before and after.

Analysis: `python3 runs/p2/analyse_p2.py > runs/p2/phase2_seeds1-16.out`.

**Definitions.**
- DSS betas: regress the quarterly change in y/TA (x4, %) on the quarterly change in the spot policy rate at the
  report, at lags 0-3, and sum the four coefficients. Data are pooled within each firm across seeds, with CR1 se
  clustered by seed. The arm difference and the RIVR-PLVR difference each come from one stacked fit with
  interactions, clustered by seed.
- Interest income = the lending stream (`net_interest_income` for banks, `lending` for cards, all revenue for POOL)
  plus interest on cash below EBIT.
- Levels and slopes are as in Phase 1 (% of EA, x4; regressors policyRateEma, y10-y2, outputGapEma).
- Hike-8 = PPNR/EA over the 8 quarters after each seed's largest 4-quarter rise in the policy rate (mean 3.97 pp),
  less the seed median.

**Opening revenue is unchanged**: first-quarter revenue AFTER/BEFORE differs by -0.2% to +1.0% across the six firms.
The franchise pricing struck in AFTER:

| firm | loan spread | fee yield (% of EA) |
|---|---|---|
| LAKE | **-0.58%** | 2.53 |
| RIVR | +2.20% | 0.64 |
| PLVR | +0.08% | 0.80 |
| TALN | +6.13% | 6.92 |
| STRK | +15.65% | 0.86 |

**DSS betas**, BEFORE | AFTER | diff (se), n=16 seeds x ~76 quarters:

| firm | income beta | expense beta | ROA beta |
|---|---|---|---|
| LAKE | 0.305 \| 0.393 \| +0.087 (0.013) | 0.236 \| 0.231 \| -0.005 (0.004) | 0.141 \| 0.157 \| +0.016 (0.015) |
| RIVR | 0.383 \| 0.550 \| +0.167 (0.015) | 0.205 \| 0.202 \| -0.002 (0.004) | 0.136 \| 0.314 \| +0.179 (0.011) |
| PLVR | 0.285 \| 0.279 \| -0.006 (0.016) | 0.152 \| 0.148 \| -0.004 (0.002) | 0.076 \| 0.111 \| +0.035 (0.013) |
| TALN | 0.306 \| 0.901 \| +0.595 (0.040) | 0.199 \| 0.193 \| -0.006 (0.004) | 0.217 \| 0.637 \| +0.420 (0.040) |
| STRK | 0.461 \| 0.521 \| +0.060 (0.097) | 0.190 \| 0.186 \| -0.004 (0.003) | 0.197 \| 0.320 \| +0.123 (0.064) |
| POOL | 0.914 \| 0.953 \| +0.039 (0.056) | 0.466 \| 0.459 \| -0.006 (0.015) | 0.117 \| 0.144 \| +0.028 (0.031) |

RIVR less PLVR in AFTER: income beta +0.271 (0.017), ROA beta +0.203 (0.011); in BEFORE they were +0.098 and +0.060.
The cross-firm slope of income beta on expense beta, across the 3 banks with no se: 0.41 BEFORE, 1.83 AFTER, against
0.77-0.88.

**Levels and NIM slope**, BEFORE -> AFTER (diff, se):

| firm | NIM | NIM slope / pp | rev/EA | int exp/EA | opex/EA | PPNR/EA |
|---|---|---|---|---|---|---|
| LAKE | 2.79->2.62 (-0.17, 0.07) | -0.155->+0.016 (+0.171, 0.028) | 6.86->6.25 (-0.61, 0.05) | 1.25->1.17 (-0.08, 0.02) | 2.71->2.72 (+0.01, 0.01) | 2.90->2.36 (-0.55, 0.05) |
| RIVR | 4.94->4.86 (-0.08, 0.08) | +0.009->+0.242 (+0.233, 0.023) | 6.46->6.33 (-0.13, 0.07) | 0.92->0.91 (-0.01, 0.02) | 2.74->2.75 (+0.01, 0.02) | 2.80->2.67 (-0.13, 0.07) |
| PLVR | 3.53->3.60 (+0.06, 0.05) | +0.006->+0.101 (+0.095, 0.036) | 5.19->5.24 (+0.05, 0.04) | 0.89 (-0.00, 0.02) | 2.58->2.61 (+0.03, 0.02) | 1.72->1.74 (+0.02, 0.04) |
| TALN | n/a | n/a | 15.12->14.77 (-0.35, 0.14) | 0.95->0.92 (-0.03, 0.03) | 7.02->7.07 (+0.06, 0.02) | 7.15->6.78 (-0.37, 0.14) |
| STRK | n/a | n/a | 15.74->15.52 (-0.21, 0.08) | 0.84->0.85 (+0.01, 0.03) | 5.27->5.37 (+0.10, 0.03) | 9.63->9.31 (-0.32, 0.09) |
| POOL | n/a | n/a | 8.67->8.51 (-0.16, 0.19) | 4.85->4.86 (+0.01, 0.01) | 4.46->4.39 (-0.07, 0.10) | n/u |

PPNR/EA slope on the policy rate AFTER: LAKE +0.02, RIVR +0.23, PLVR +0.10, TALN +0.62, STRK +0.54 (BEFORE
0.04-0.25).

**Outcomes**, BEFORE -> AFTER (diff, se):

| firm | ROE mean | ROE p5* | CET1 mean | CET1 min* | P/B | payout stops* | sd qtr ROE | hike-8 less median |
|---|---|---|---|---|---|---|---|---|
| LAKE | 21.87->17.49 (-4.37, 0.41) | 12.9->7.0 (-5.84, 0.55) | 13.71->13.62 (-0.09, 0.05) | 12.55->12.12 (-0.43, 0.16) | 2.25->1.87 (-0.39, 0.03) | 0 / 0 | 4.93->5.76 (+0.83, 0.20) | +0.14->-0.16 (-0.29, 0.11) |
| RIVR | 16.06->15.28 (-0.78, 0.44) | 8.1->3.1 (-4.94, 0.87) | 15.36->15.17 (-0.19, 0.07) | 14.01->13.12 (-0.89, 0.21) | 1.43->1.38 (-0.05, 0.03) | 0 / 0.06 | 4.42->6.47 (+2.05, 0.23) | +0.05->+0.08 (+0.04, 0.08) |
| PLVR | 12.83->12.95 (+0.12, 0.44) | 5.4->4.6 (-0.78, 0.72) | 16.74->16.87 (+0.13, 0.08) | 14.05->14.21 (+0.15, 0.23) | 1.23->1.25 (+0.01, 0.03) | 0 / 0 | 4.36->4.55 (+0.19, 0.29) | -0.02->-0.17 (-0.15, 0.08) |
| TALN | 23.75->21.87 (-1.89, 0.79) | 12.2->4.3 (-7.87, 0.94) | 19.39->18.95 (-0.44, 0.15) | 16.39->15.40 (-0.98, 0.33) | 1.83->1.73 (-0.10, 0.05) | 0 / 0 | 7.43->10.18 (+2.75, 0.30) | +0.04->+0.84 (+0.80, 0.21) |
| STRK | 21.45->19.98 (-1.47, 0.63) | 0.1->-3.1 (-3.17, 1.76) | 19.06->18.56 (-0.50, 0.24) | 14.96->14.57 (-0.38, 0.27) | 1.68->1.62 (-0.05, 0.03) | 0.31 / 0.62 (+0.31, 0.34) | 13.03->13.52 (+0.49, 0.56) | +0.34->-0.04 (-0.37, 0.22) |
| POOL | 21.72->21.49 (-0.22, 0.16) | 13.2->12.9 (-0.23, 0.27) | 41.69->41.49 (-0.21, 0.39) | 32.76->32.80 (+0.04, 0.44) | 1.67 (-0.00, 0.01) | 0 / 0 | 6.32->6.37 (+0.05, 0.11) | -0.22->-0.54 (-0.32, 0.19) |

\* tail cells, indicative at n=16.

**Board.** Bankruptcies per seed rose from 0.06 to 0.31 (+0.25, se 0.11). TIER (Tiercel Capital Partners, private
equity) died on seeds 2, 6, 9 and 14 in AFTER and on none in BEFORE. The live rec run (BEFORE tree) killed TIER on
seeds 9 and 14, so TIER sits near a threshold. Reorganisations went 0.12 -> 0.19 (+0.06, 0.14), and payment-default
firms 0.31 -> 0.44. There were no non-finite values in either arm, and no lender failed.

**Findings.**
1. RIVR is clearly more rate-positive than PLVR in AFTER: its ROA beta is 0.31 against 0.11 (difference +0.20, se
   0.01), and its income beta is 0.55 against 0.28.
2. Expense betas do not move (0.15-0.24, against DSS 0.36). That is expected, since the deposit side is untouched, and
   it is the binding gap.
3. Income betas fan out: PLVR 0.28, LAKE 0.39, RIVR 0.55, TALN 0.90. They are no longer matched to expense betas
   (cross-firm slope 1.8 against 0.8). ROA betas, already positive in BEFORE (0.08-0.22), rise for every lender, where
   DSS has about 0.
4. NIM slopes: PLVR +0.10 is on the +0.08 benchmark, RIVR +0.24 is 3x it, and LAKE +0.02 is flat.
5. LAKE's loan spread is struck negative (-0.58%: loans priced under the curve). Its PPNR/EA falls 0.55 pp, ROE
   4.4 pp and P/B 0.39, already -0.33 pp of PPNR in years 1-5. Opening revenue is unchanged, so the loss accrues after
   the opening.
6. The hiking-cycle squeeze appears for LAKE (-0.29, se 0.11) and PLVR (-0.15, se 0.08). TALN instead gains +0.84 in
   a hike (floating book).
7. Quarterly ROE volatility rises for RIVR (+2.1 pp) and TALN (+2.8 pp).
8. POOL is unchanged within noise.

**Caveats.**
- Tail cells need 48 seeds.
- The replay drops the board-cap feedback into the macro.
- The policy rate in the DSS fit is the spot rate at each report, not a quarter average.
- The cross-firm beta slope is on 3-5 firms and has no se.
- The TIER deaths are a board-coupling effect at n=16 and were not traced.
