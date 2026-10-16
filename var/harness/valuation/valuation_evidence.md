# Valuation evidence

## 2026-10-17: sector P/E prior re-struck at today's real discount rate (worktree valuation-step3)

Question: does `Valuation::sectorHurdleShift()` make long-duration names (staples, utilities, REITs) more rate-sensitive
than cyclicals? Sanity of the change; ROIC-gap persistence vs the assumed omega 0.62; post-report drift.

Harness: `ValuationHarnessTest.php` via `run.sh rec|base|new`, 16 seeds x 15 years, 360 ticks/yr, one recorded macro
path per seed replayed in both arms. Stopped at stage 16: staples fair-value beta |t| = 13.7, utilities/REITs inside
+-1%/100bp. Micro draws do not pair, so differences are paired on the macro path only. Tables: `stage16.out`
(21-tick windows), `stage16_w90.out` (90-tick windows).

| % per +100bp real 10Y | FV beta base | new | diff (se) | price beta base | new | diff (se) |
|---|---|---|---|---|---|---|
| Staples | -11.0 | -17.3 | -6.25 (0.46) | -0.35 | -0.69 | -0.34 (0.09) |
| Utilities | -6.9 | -7.4 | -0.46 (0.34) | +0.36 | +0.08 | -0.28 (0.32) |
| REITs | -14.3 | -14.8 | -0.48 (0.41) | -0.43 | -0.55 | -0.12 (0.10) |
| Cyclicals | -11.9 | -15.9 | -4.00 (0.25) | -0.59 | -0.71 | -0.12 (0.09) |
| Growth | -13.8 | -21.6 | -7.72 (0.39) | -0.63 | -1.08 | -0.44 (0.20) |
| Financials | -10.4 | -13.8 | -3.38 (0.22) | -0.56 | -0.60 | -0.03 (0.13) |

Sanity: index 6.47 vs 6.46 %/y, vol 15.10 vs 15.13 %, failures 0.12 vs 0.06, no NaN/zero prices, board mean FV/price
1.00 at year 0 in both arms (no founding level shift) and within 0.02 at years 1-15.

Persistence (base arm; return gap = true return - longRunReturn, 4 reports apart): board omega 0.63; staples 0.76,
utilities 0.54, REITs 0.63, cyclicals 0.57, growth 0.46, financials 0.67; PHIL 0.88, SGRB 0.78, CROP 0.48, LARK 0.81,
PINT 0.77. At 1 sd of gap and r = 8% the structural return moves at most ~28 bp (PHIL), ~5-15 bp per sector.
Post-report drift: corr(FV revision, next 1-4 quarter return) +0.10 to +0.17 board-wide (market-adjusted +0.12 to
+0.17), staples +0.03 to +0.12.

Caveats: rate beta pools rate-driven and cycle-driven moves of the real 10Y; the log P/E slope uses trailing EPS
(noisy). Utility and REIT fair value weights the P/E leg 15-40% (`calculateFairValue` overrides), so the prior change
barely reaches them. Price betas are ~1/20 of fair-value betas even at 90-tick windows.
