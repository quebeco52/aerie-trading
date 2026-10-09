# Lender pre-provision economics vs rate level, slope and cycle

## 2026-10-06: before replacing the ROE-back-solved bank revenue

**Question.** How do LAKE, RIVR, PLVR, TALN, STRK and POOL's revenue, interest expense, opex, PPNR and NIM (all % of
earning assets, annualized) move with the policy rate, the 10y-2y slope and the output gap? Specifically: does
opex/EA rise with the policy rate (hand calculation: about +0.4pp of EA per pp for LAKE; US banks roughly flat), and
how does NIM's rate slope compare with about +8bp per +1pp (Claessens, Coleman & Donnelly 2018, second-hand)?

**Harness.** No new simulation. `nii_sensitivity.py` reads `runs/qw/after-<seed>.jsonl` (the quick-wins AFTER arm:
working tree = HEAD 0071ab6 plus a comment-only change, `BankCreditHarnessQwTest.php`, macro path replayed from
`runs/qw/rec-<seed>`). Seeds 1-16, 20 years, 360 ticks/year, 80 report quarters per lender per seed, 1,280
lender-quarters each, no lender failed. Run: `python3 nii_sensitivity.py > nii_sensitivity_seeds1-16.out`.

**Definitions.** All per row, x4 / gross `ea`. opex = rev - EBIT - provision (cost ratio x revenue, depreciation,
severance; for the banks also the curve NIM squeeze, which the model books in the cost ratio and which is backed out
of the reported NIM as nii - int - nim x (ea - allow)/4). PPNR = EBIT + provision - interest expense. NIM = the
reported figure (net EA). Regressors in pp: policy-rate EMA, slope = y10 EMA - y2 EMA (the pair the model reads),
outputGapEma. Pooled OLS, CR1 standard errors clustered by seed (G = 16). The script also fits with seed fixed effects;
those match the pooled fits within about one se.

**Levels** (pooled mean / sd; se of the mean across the 16 seed means is 0.02-0.3):

| % of EA          | LAKE      | RIVR      | PLVR      | TALN       | STRK       | POOL        |
|------------------|-----------|-----------|-----------|------------|------------|-------------|
| revenue          | 9.18/2.02 | 8.82/1.89 | 6.86/1.53 | 17.73/2.45 | 31.38/3.49 | 8.30/2.96   |
| interest expense | 1.23/0.74 | 0.90/0.64 | 0.85/0.43 | 0.97/0.54  | 0.91/0.50  | 4.75/1.01   |
| opex             | 5.26/1.13 | 5.23/1.15 | 4.27/1.04 | 9.88/1.23  | 21.42/1.85 | 4.28/1.52   |
| PPNR             | 2.69/0.29 | 2.70/0.39 | 1.74/0.31 | 6.88/1.09  | 9.06/2.15  | (-0.73) *   |
| reported NIM     | 4.20/0.60 | 7.07/1.07 | 5.01/0.83 | n/a        | n/a        | n/a         |

\* POOL books its portfolio coupon below EBIT (`ShadowBankBusinessModel::calculateInterestIncome`, line 144), and the
rows do not record interest income, so POOL's PPNR and rev - int leave out its main income line and are not usable.

**OLS on (policy EMA, slope, gap)**: coef (clustered se), n = 1,280 quarters, 16 seeds:

| lender | y        | policy EMA     | slope          | gap            | R2   |
|--------|----------|----------------|----------------|----------------|------|
| LAKE   | NIM      | +0.139 (0.036) | -0.188 (0.086) | +0.026 (0.017) | 0.60 |
| LAKE   | PPNR/EA  | +0.006 (0.023) | -0.100 (0.047) | +0.064 (0.012) | 0.45 |
| LAKE   | opex/EA  | +0.557 (0.045) | +0.000 (0.088) | -0.116 (0.044) | 0.70 |
| RIVR   | NIM      | +0.414 (0.064) | +0.055 (0.076) | -0.045 (0.041) | 0.45 |
| RIVR   | PPNR/EA  | +0.019 (0.024) | +0.070 (0.021) | +0.056 (0.019) | 0.05 |
| RIVR   | opex/EA  | +0.485 (0.060) | -0.121 (0.073) | -0.112 (0.057) | 0.63 |
| PLVR   | NIM      | +0.396 (0.058) | +0.240 (0.100) | +0.005 (0.023) | 0.53 |
| PLVR   | PPNR/EA  | +0.033 (0.016) | +0.159 (0.028) | +0.070 (0.014) | 0.15 |
| PLVR   | opex/EA  | +0.477 (0.060) | -0.091 (0.102) | -0.083 (0.022) | 0.76 |
| TALN   | PPNR/EA  | +0.212 (0.075) | +0.374 (0.158) | +0.117 (0.049) | 0.11 |
| TALN   | opex/EA  | +0.617 (0.133) | +0.471 (0.283) | +0.010 (0.046) | 0.52 |
| STRK   | PPNR/EA  | -0.066 (0.126) | +0.484 (0.250) | +0.235 (0.079) | 0.04 |
| STRK   | opex/EA  | +0.257 (0.132) | -0.283 (0.264) | +0.172 (0.072) | 0.28 |
| POOL   | opex/EA  | -0.203 (0.105) | -1.132 (0.138) | -0.091 (0.078) | 0.23 |

Revenue/EA for LAKE rises +0.996 (0.075) per pp and interest expense +0.433 (0.028): revenue passes the rate through
one for one, and opex rises with it as a fixed cost ratio of revenue. The NIM squeeze has no part in it (policy coef
+0.003 (0.003); slope coef -0.013 to -0.219). Full table, including the one-regressor and spot-rate fits:
`nii_sensitivity_seeds1-16.out`.

**The two numbers.**
- opex/EA per +1pp policy rate, LAKE: +0.56 (0.05) with slope and gap held, +0.48 (0.03) alone (+0.44 on the spot
  rate). RIVR +0.46 to +0.49 and PLVR +0.46 to +0.48. So the hand calculation's sign is confirmed and its size is
  slightly low. US banks are roughly flat.
- NIM per +1pp policy rate: LAKE +0.14 (0.04) held / +0.23 (0.02) alone, RIVR +0.41 / +0.36, PLVR +0.40 / +0.30.
  That is 2-5x the ~+0.08 benchmark.
- PPNR/EA per +1pp: LAKE +0.006 (0.023), RIVR +0.019 (0.024), PLVR +0.033 (0.016). It is flat, because the back-solve
  pins pre-provision profit to the ROE target and revenue then absorbs the rise in funding cost.

**PPNR cyclicality**, per seed: mean PPNR/EA over the 8 lowest-gap quarters (mean gap -3.08pp) minus the seed's median
PPNR/EA quarter:

| lender | median | worst 8 | diff (se)      | % of median | range          | n  |
|--------|--------|---------|----------------|-------------|----------------|----|
| LAKE   | 2.70   | 2.40    | -0.297 (0.052) | -11.0       | [-0.86, -0.07] | 16 |
| RIVR   | 2.73   | 2.52    | -0.213 (0.058) | -7.8        | [-0.79, +0.10] | 16 |
| PLVR   | 1.73   | 1.58    | -0.150 (0.040) | -8.8        | [-0.45, +0.17] | 16 |
| TALN   | 6.90   | 6.33    | -0.569 (0.131) | -8.4        | [-1.68, -0.00] | 16 |
| STRK   | 9.07   | 8.57    | -0.501 (0.206) | -5.8        | [-1.94, +1.05] | 16 |
| POOL   | n/u *  |         |                |             |                |    |

**Caveats.**
- The rows record the spot policy rate, not `policyRateEma`. The EMA is rebuilt from the quarterly spot samples with
  the engine's tau = 0.25 y (`MacroAggregateSubsystem::STANDARD_EMA_HORIZON_YEARS`), opened at 0.034. On the spot rate
  instead, the one-regressor slopes are about 10% smaller, and no conclusion changes.
- PPNR leaves out interest earned on excess treasury cash (the rows have no interest-income field). For the banks,
  cash is about 10% of EA, so including it could add at most about +0.1pp of EA per pp to the PPNR rate slope. That
  bound is inferred, not measured. For POOL the missing line is the whole portfolio coupon (see above).
- Equity-wealth loop and macro are whatever the replayed recording ran. This is one arm, the current tree, and there
  is no counterfactual.
- To close both gaps: add `'ii' => (float) $report->getInterestIncome()` and `'pol_ema' => $m?->policyRateEma` to
  the row in `runs/qw/BankCreditHarnessQwTest.php` (around line 316), then rerun the AFTER arm on seeds 1-16 (about
  2 min per seed, 8 at a time).
