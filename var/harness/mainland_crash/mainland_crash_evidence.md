# Mainland crash evidence

## 2026-10-19: compensated Merton crash in the mainland gap (s8.out, s16.out, event16.out)

Question: does a mainland crash (0.025/yr, mean 0.023, log sd 0.30, MAINLAND_GAP_SIGMA 0.00490 -> 0.00452, innovation sd
held at 0.00490) give the District a left tail in growth and more co-movement, and does it move the cycle guards?

Harness: `MainlandCrashHarnessTest.php` (copy of comove), arms A = working tree + ovrA (intensity 0, sigma 0.00490),
B = working tree (HEAD 8396933c + uncommitted src). 100 years, TPY 360, POLITICS 1, burn 5. Stage 8 (s8.out) left the
District targets at |t| < 2, so stage 16. Seed 11 arm A died at year 44.5 (`Not a decimal: 'NAN'` in
Stock::setPrice from StockTracker.php:441, a market-side NaN price, not the crash arm); seed 11 dropped from both arms, n = 15.

Paired B-A, n = 15 (full table in s16.out):

| quantity | A | B | B-A (se) t |
|---|---|---|---|
| growth skew | -0.24 | -0.17 | +0.07 (0.07) 1.1 |
| growth excess kurtosis | 0.52 | 0.28 | -0.24 (0.19) -1.3 |
| worst quarter % | -1.88 | -1.77 | +0.11 (0.16) 0.7 |
| share of quarters < -1% | 0.017 | 0.013 | -0.005 (0.002) -2.0 |
| quarters < -2% per century | 0.70 | 0.42 | -0.28 (0.24) -1.2 |
| worst 4-quarter growth % | -4.57 | -4.20 | +0.37 (0.40) 0.9 |
| gap mean % / sd % | -0.39 / 2.26 | -0.31 / 2.28 | +0.08 (0.16) / +0.01 (0.10) |
| recessions per decade | 1.62 | 1.46 | -0.16 (0.15) -1.1 |
| policy floor share | 0.060 | 0.052 | -0.008 (0.015) -0.5 |
| mainland d fgap skew / exkurt | -0.03 / -0.02 | -0.55 / 2.91 | t -4.3 / 4.2 |
| mainland worst quarter d fgap % | -1.57 | -2.71 | -1.15 (0.25) -4.6 |
| corr(gap, fgap) / HP corr | 0.49 / 0.385 | 0.48 / 0.353 | -0.01 (0.05) / -0.03 (0.04) |
| corr(rgq, d fgap) k=0 / mainland 2q ahead | 0.05 / 0.36 | 0.02 / 0.36 | -0.03 (0.03) / 0.00 (0.02) |

Event study (28 crashes, B-A less the pre-crash quarter): fgap -2.5pp at impact, -3.3pp by +2; the District's demand
shock -6.8pp at impact, but the gap falls only -1.0 by +2 and -2.6 by +6, and the growth hit peaks at -1.15pp in +2,
spread over 6 quarters.

Reading: the crash is visible on the mainland (t > 4) and invisible in the District's growth tail and co-movement; the
guards stay inside noise. The demand shock enters the gap as a drift (MacroAggregateSubsystem.php:926-930, the
domestic-demand drift array), so a one-quarter jump in it is integrated into a slow slide, not a sharp quarter.
Real references: quarters < -2% US 1 per 23 years (~4.3 per century) vs 0.4 here; growth skew median -0.99 vs -0.17;
HP corr EA-US 0.66 (0.49 ex 2008-10) vs 0.35; growth corr 0.51 vs 0.02 contemporaneous (0.36 with a 2-quarter lag).
