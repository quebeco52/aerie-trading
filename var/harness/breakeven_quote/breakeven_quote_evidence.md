# Breakeven quote evidence

## 2026-10-13: quote vs expectation leg, display-rule check

Question: does the new `tipsBreakevenQuoted` (expectation + -0.356 x EBP) move like US T10YIE (sd 0.37pp, dq sd
0.24pp, corr dq with log WTI +0.69, with BAA10Y -0.76)? Expected: quote sd ~0.13pp, spread correlation clearly negative.

Harness: `breakeven_quote.php`, working tree (uncommitted change), one arm, seeds 1-3 x 40 years, 48 tpy, no equity cap.
Table (`s3_y40.out`, pp, quarterly averages, 2-year burn-in):

```
  seed    leg  sd lvl   sd dq    min    max   c HY   c IG  c lnE | q-e mean  EBP mean/sd
     1  quote   0.230   0.136   1.13   2.27  -0.91  -0.91   0.24 |   -0.063  0.18/ 0.58
     1 expect   0.083   0.042   1.80   2.20  -0.00  -0.00   0.75
     2  quote   0.150   0.097   1.55   2.40  -0.89  -0.89   0.31 |    0.021 -0.06/ 0.34
     2 expect   0.084   0.039   1.90   2.31  -0.18  -0.18   0.69
     3  quote   0.216   0.128   1.10   2.33  -0.85  -0.85   0.20 |   -0.067  0.19/ 0.52
     3 expect   0.116   0.051   1.72   2.33   0.01   0.01   0.66
 mean3  quote   0.199   0.120   1.10   2.40  -0.88  -0.88   0.25 |   -0.036
 mean3 expect   0.094   0.044   1.72   2.33  -0.06  -0.06   0.70
```

Caveats: 3 seeds, display-rule grade. HY and IG change correlations agree to 2 decimals, so in this loop the HY
spread moves almost one-for-one with macroCreditSpread quarterly. Quote's energy correlation (+0.25) is diluted by
EBP; the expectation leg alone carries +0.70. Quote - expectation mean = -0.356 x EBP mean, which is seed-dependent
(EBP mean -0.06 to +0.19pp over 38 years).
