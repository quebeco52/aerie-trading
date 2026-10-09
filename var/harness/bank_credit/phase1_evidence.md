# Phase 1 evidence: lender cost base on earning assets

## 2026-10-06: structural cost = revenueGeneratingCapital x ratio / 4 for the banks and card lenders

**Question.** On the same seeds and macro path, what does the uncommitted Phase 1 change (nine src files vs HEAD
0071ab6: `EarningsEngine` cost on the book; bank and card revenue target = target EBIT + EA x cost ratio;
efficiency clamps removed; ratios LAKE 0.0279, RIVR 0.0282, PLVR 0.0267, TALN 0.0742, STRK 0.059, POOL null) do to the
six lenders' levels, rate slopes, returns, capital, ROE volatility and PPNR cyclicality? Key check: opex/EA slope on
the policy rate goes from about +0.5 to about 0.

**Harness.** `runs/p1/BankCreditHarnessP1Test.php` (copy of `runs/qw/BankCreditHarnessQwTest.php`; it adds `ii`
(interest income), `pol_ema` (policyRateEma, recorded), `streams` (the full revenue-stream split) on each quarter row,
and `class_files` (ReflectionClass file + md5 of the seven touched classes) and `cost_hook` per run). Bootstrap
`runs/p1/bootstrap.php` (qw copy; the BASE autoloader is prepended). `runs/p1/run_p1.sh <rec|before|after> <seed>`:
- rec = BEFORE tree, live macro, records the path (scratchpad `p1_macro/path-<seed>.gz`, 313 MB)
- before = BEFORE tree replaying it: the working tree rsync'd to scratchpad `wt_p1before` with the nine src files
  replaced by `git show HEAD:<path>`, so its src is HEAD's exactly (`diff -rq` shows only those nine); vendor
  symlink; the user's working `.env` in both arms
- after = working tree replaying it
`seq 1 16 | xargs -P 8 -I{} ./run_p1.sh rec {}`, then 32 before/after runs at `-P 8`. 16 seeds x 20 y, 360 tpy,
`mt_srand(seed)`. 48/48 runs OK, 754 s wall (118-130 s per run). Macro path identical in both arms on 16/16 seeds.
Every BEFORE run loaded the scratchpad tree's classes, every AFTER run the working tree's (ReflectionClass). The cost
hook is absent in BEFORE and reads 0.0279 / 0.0282 / 0.0267 / 0.0742 / 0.059 / null in AFTER. Repo unchanged after:
`runs/p1/repo_files_before.md5` OK, md5 of the whole src/config/templates tree identical, `git status --porcelain`
identical (`git_status_before.txt` = `git_status_after.txt`).
`python3 analyse_p1.py > runs/p1/phase1_seeds1-16.out`. It reuses `analyse_qw.lender()` for ROE, CET1, P/B and payout
stops, and `nii_sensitivity.ols_cluster()` for the slopes.

**Definitions.** All rates x4 / gross `ea`, %. opex = rev - EBIT - prov. PPNR = EBIT + prov - int. fees = rev less
the lending stream (`net_interest_income` for banks, which leaves LAKE's proprietary dividend in fees; `lending` for
TALN and STRK). Slopes come from pooled OLS on (policyRateEma, y10-y2 EMA, outputGapEma) with CR1 standard errors
clustered by seed. The slope difference comes from one stacked fit with arm interactions, clustered by seed. Quarterly
ROE = 4 x NI / mean(previous eq, eq), and its sd is taken within each seed.

**Levels, % EA** (BEFORE -> AFTER, paired diff (se), n=16):

| lender | revenue | opex | int exp | PPNR | NIM | fees |
|---|---|---|---|---|---|---|
| LAKE | 9.18->6.80 (-2.39, 0.12) | 5.26->2.69 (-2.56, 0.10) | 1.23 (-0.01, 0.01) | 2.69->2.88 (+0.19, 0.03) | 4.20->2.81 (-1.39, 0.08) | 3.80->2.82 (-0.98, 0.05) |
| RIVR | 8.82->6.43 (-2.40, 0.10) | 5.23->2.71 (-2.52, 0.08) | 0.90 (+0.01, 0.03) | 2.70->2.81 (+0.11, 0.02) | 7.07->4.94 (-2.13, 0.07) | 0.94->0.68 (-0.25, 0.01) |
| PLVR | 6.86->5.15 (-1.71, 0.10) | 4.27->2.57 (-1.70, 0.09) | 0.85->0.83 (-0.02, 0.02) | 1.75->1.76 (+0.02, 0.01) | 5.01->3.59 (-1.42, 0.08) | 1.09->0.82 (-0.27, 0.01) |
| TALN | 17.73->15.11 (-2.61, 0.28) | 9.88->7.02 (-2.87, 0.17) | 0.97->0.93 (-0.03, 0.05) | 6.88->7.16 (+0.29, 0.13) | n/a | 8.14->6.90 (-1.24, 0.16) |
| STRK | 31.38->15.43 (-15.95, 0.24) | 21.42->5.24 (-16.18, 0.16) | 0.91->0.86 (-0.04, 0.04) | 9.06->9.32 (+0.27, 0.14) | n/a | 1.66->0.81 (-0.85, 0.02) |
| POOL | 8.30->8.47 (+0.17, 0.30) | 4.28->4.36 (+0.08, 0.15) | 4.75 (+0.00, 0.02) | n/u (portfolio coupon below EBIT) | n/a | n/a |

Measured opex/EA sits 4-11% below the input ratio (LAKE 2.69 vs 2.79; STRK 5.24 vs 5.90). That gap is not
decomposed here.

**Policy-rate slopes**, pp of EA per pp, with slope and gap held: BEFORE | AFTER | diff (se):

| lender | opex/EA | NIM | PPNR/EA | fees/EA |
|---|---|---|---|---|
| LAKE | +0.578 \| +0.007 \| -0.570 (0.040) | +0.159 \| -0.160 \| -0.320 (0.027) | +0.018 \| +0.050 \| +0.032 (0.025) | +0.435 \| +0.216 \| -0.219 (0.024) |
| RIVR | +0.515 \| -0.005 \| -0.520 (0.052) | +0.445 \| -0.035 \| -0.480 (0.051) | +0.025 \| +0.011 \| -0.015 (0.014) | +0.097 \| +0.041 \| -0.056 (0.007) |
| PLVR | +0.494 \| -0.004 \| -0.497 (0.056) | +0.416 \| +0.006 \| -0.409 (0.051) | +0.040 \| +0.055 \| +0.015 (0.008) | +0.124 \| +0.047 \| -0.077 (0.011) |
| TALN | +0.596 \| -0.005 \| -0.601 (0.122) | n/a | +0.201 \| +0.091 \| -0.110 (0.050) | +0.542 \| +0.154 \| -0.388 (0.124) |
| STRK | +0.282 \| +0.015 \| -0.266 (0.110) | n/a | -0.057 \| +0.093 \| +0.150 (0.095) | +0.019 \| +0.018 \| -0.002 (0.011) |
| POOL | -0.206 \| -0.165 \| +0.042 (0.053) | n/a | (n/u) | n/a |

**Returns, capital, volatility, cycle** (BEFORE -> AFTER, diff (se)):

| lender | ROE mean | ROE p5* | CET1 mean | CET1 min* | P/B | payout stops* | sd qtr ROE | PPNR worst-8 less median |
|---|---|---|---|---|---|---|---|---|
| LAKE | 20.11->21.67 (+1.56, 0.24) | 11.2->12.8 (+1.56, 0.33) | 13.67 (-0.01, 0.04) | 12.57 (-0.02, 0.09) | 2.08->2.19 (+0.10, 0.02) | 0 / 0 | 5.05 (+0.01, 0.11) | -0.30 (-0.00, 0.03) |
| RIVR | 15.29->16.01 (+0.72, 0.10) | 7.2->8.2 (+0.99, 0.22) | 15.34->15.37 (+0.03, 0.02) | 13.95->14.08 (+0.13, 0.06) | 1.35->1.42 (+0.06, 0.02) | 0 / 0 | 4.66->4.32 (-0.34, 0.09) | -0.21->-0.09 (+0.12, 0.05) |
| PLVR | 13.04->13.37 (+0.32, 0.13) | 5.0->5.9 (+0.92, 0.54) | 16.68->16.57 (-0.11, 0.05) | 13.97->13.90 (-0.07, 0.11) | 1.23->1.24 (+0.01, 0.01) | 0 / 0 | 4.63->4.15 (-0.48, 0.27) | -0.15 (-0.00, 0.03) |
| TALN | 22.25->24.20 (+1.94, 0.74) | 10.3->12.2 (+1.89, 1.13) | 19.49->19.28 (-0.21, 0.30) | 16.22->16.50 (+0.28, 0.18) | 1.72->1.83 (+0.10, 0.04) | 0 / 0 | 8.08->7.56 (-0.52, 0.41) | -0.57->-0.40 (+0.17, 0.10) |
| STRK | 17.98->20.27 (+2.29, 0.83) | -6.7->-2.4 (+4.29, 1.71) | 19.08->18.73 (-0.35, 0.37) | 14.02->14.53 (+0.51, 0.35) | 1.48->1.59 (+0.11, 0.04) | 1.31->1.00 (-0.31, 0.22) | 17.24->13.81 (-3.42, 0.61) | -0.50->-0.49 (+0.01, 0.20) |
| POOL | 21.18->21.53 (+0.34, 0.36) | 13.2->13.6 (+0.34, 0.59) | 42.25->42.17 (-0.08, 0.50) | 32.83->32.68 (-0.15, 0.48) | 1.62->1.64 (+0.02, 0.03) | 0 / 0 | 6.30->6.17 (-0.13, 0.17) | (n/u) |

\* tail cells, indicative at n=16.

Board: bankruptcies 0.06 -> 0.06 / seed (FULM s1 in both arms), reorganisations 0.25 -> 0.12 (-0.12, 0.09),
payment-default firms 0.38 -> 0.38, non-finite samples 0 in both arms. No lender failed.

**Finding.** The key check passes. opex/EA no longer moves with the policy rate: about +0.5 before, 0.00 +/- 0.01
after, for all five lenders that carry the hook, and POOL is unchanged. Revenue/EA's rate slope halves (LAKE +1.03 ->
+0.50). But the revenue target is still split into streams by fixed weights (`CommercialBankBusinessModel.php:476-479`,
`$expectedRevenue * $niiWeight` / `* $feeWeight`). So about 43% of LAKE's rate-driven revenue lands in fees and the
proprietary dividend, fees/EA +0.22 per pp, while interest expense rises +0.44 per pp. As a result LAKE's reported NIM
now FALLS with the rate (-0.16, se 0.02), and RIVR and PLVR are flat (-0.04, +0.01). The benchmark is about +0.08
(Claessens et al., second-hand). BEFORE was 2-5x too steep; AFTER is too flat for RIVR and PLVR and has the wrong sign
for LAKE. PPNR/EA stays flat in rates (the back-solve pins it). Levels move toward FDIC 2019: opex/EA LAKE 2.69,
RIVR 2.71, PLVR 2.57 against 2.85 for all banks (2.58% of assets); TALN 7.02 against 7.42 for card banks. NIM falls to
LAKE 2.81, RIVR 4.94, PLVR 3.59 against 3.36. RIVR and PLVR fees (0.68, 0.82) sit under the 1.62% of EA benchmark;
LAKE's 2.82 includes its proprietary dividend. STRK's revenue/EA halves (31.4 -> 15.4) for unchanged PPNR, and its
quarterly ROE sd falls 17.2 -> 13.8 (-3.4, 0.6). ROE and P/B rise modestly for every lender with the hook (LAKE +1.6pp,
P/B +0.10).

**Caveats.** Tail cells (p5, CET1 min, payout stops, failures) need 48 seeds. The replay drops the board-cap feedback
into the macro: the live rec runs show other deaths (TIER x3, FALC, FULM x2). POOL's paired diffs are non-zero on some
seeds (revenue range -1.0 to +4.3) through board coupling, but its mean is inside noise. PPNR leaves out interest
income below EBIT (reported separately as `ii`; for the banks it is 0.30-0.33% of EA and does not change between arms).
The two arms carry the user's working `.env` (SIM_TICKS_PER_YEAR is irrelevant here; the harness sets tpy).
