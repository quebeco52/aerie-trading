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

## 2026-10-19 TPY check: B360 vs B3600, 8 seeds x 18 years, POLITICS 1, HEAD 58382878

Question: does the tick rate (live 3600 vs harness 360) change the District slumps? Arm B only, unpaired across TPY.
Run: see RUN.md "TPY check". B3600 runs took 13.5-17.8 min each on 8 slots, B360 ~2.5 min; no NaN, all 16 OK.

```
Summary: mean [min, max] over seeds; reach = seeds at or beyond the live value
quantity        live          B360 mean [min, max] reach         B3600 mean [min, max] reach             diff (se) t
gap%           -0.60    -0.36 [  -1.60,   0.66]   3/8      -0.16 [  -1.14,   0.41]   2/8       0.20 ( 0.36)   0.6
gap<-2          0.36     0.21 [   0.08,   0.46]   1/8       0.19 [   0.00,   0.31]   0/8      -0.02 ( 0.06)  -0.4
deep_q         29.00    13.50 [   5.00,  33.00]   1/8      13.25 [   3.00,  23.00]   0/8      -0.25 ( 3.98)  -0.1
neg_q                   20.38 [  11.00,  37.00]            24.12 [  16.00,  31.00]             3.75 ( 3.53)   1.1
pol_floor       0.18     0.07 [   0.00,   0.19]   1/8       0.07 [   0.00,   0.14]   0/8      -0.00 ( 0.03)  -0.1
fed_floor       0.33     0.02 [   0.00,   0.10]   0/8       0.07 [   0.00,   0.28]   0/8       0.05 ( 0.04)   1.3
fgap%          -0.97     0.37 [  -0.66,   2.16]   0/8       0.16 [  -1.05,   1.31]   1/8      -0.21 ( 0.42)  -0.5
fneg_q                  19.75 [   9.00,  28.00]            19.38 [   8.00,  38.00]            -0.38 ( 4.13)  -0.1
push_tot                 7.03 [ -15.16,  40.39]             4.70 [ -13.48,  22.67]            -2.32 ( 7.46)  -0.3
push_dd       -19.80   -10.10 [ -17.27,  -2.85]   0/8      -9.91 [ -18.44,  -3.04]   0/8       0.19 ( 2.48)   0.1
worst_rgq%              -1.26 [  -1.74,  -0.54]            -1.18 [  -2.35,  -0.55]             0.08 ( 0.25)   0.3
```

Verdict: no TPY effect on any slump moment (all |t| <= 1.3). The live dump (deep spell 29q, Fed floor 0.33, fgap mean -0.97, push drawdown -19.8pp) sits at or past the edge of both arms: no seed in either arm reaches the Fed floor share or the push drawdown, and none matches the live combination. Caveats: n=8 per arm, unpaired; 72 quarters from a fresh reset, no burn-in.
