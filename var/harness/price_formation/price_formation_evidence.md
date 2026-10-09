# price_formation evidence

## 2026-10-07 change 2a (liveCostOfEquity drift; equity-consistent FV on ROE at CoE; own borrowing rate + Diet tax in structural EPS)

Question: does 2a move board price-formation moments? AFTER = worktree market-price-formation (uncommitted 2a),
BEFORE = /tmp/claude-1000/b1 (batch-1 src). Harness PriceFormationHarnessTest.php, run.sh/batch.sh, analyze.py.
Seeds 1-8, 20 years, 360 ticks/year, years 2-20, macro path recorded on the AFTER tree and replayed in both arms.
Stage 1 (8 seeds) stopped: all three targets |t| >= 4. No failed runs. Wall ~5 min.

```
seeds n=8: [1, 2, 3, 4, 5, 6, 7, 8]  years=20 tpy=360
quantity                        BEFORE     AFTER      diff       se       t  range(diff)
(a) med log(P/FV)               0.0002    0.0091    0.0089   0.0019    4.80  [0.0028, 0.0171]
(b) slope lpf~(y10-pol)        -3.1292   -2.2724    0.8568   0.1264    6.78  [0.3972, 1.5087]
(c) med trailing P/E           13.1224   13.3316    0.2092   0.0442    4.74  [0.0208, 0.3525]
ERP: med ann TR - y10           0.0753    0.0730   -0.0022   0.0012   -1.92  [-0.0072, 0.0017]
med name-yr realized vol        0.2247    0.2275    0.0028   0.0008    3.38  [-0.0011, 0.0063]
bankruptcies/seed               0.2500    0.1250   -0.1250   0.1250   -1.00  [-1.0000, 0.0000]
splits/seed                    84.3750   85.7500    1.3750   0.9051    1.52  [-2.0000, 5.0000]
AFTER only (mean over seeds, se across seeds, range); BEFORE mean for context:
  2c var share on earn ticks (med of name mean-annual)   0.04715 se 0.00178  [0.04045, 0.05577]  BEFORE 0.04745
  2c var share, pooled yrs (med)                         0.04725 se 0.00253  [0.03758, 0.05574]  BEFORE 0.04745
  2c board-mean log ret, earn tick                       0.00842 se 0.00078  [0.00488, 0.01192]  BEFORE 0.00828
```

Caveats: replayed macro (no board-cap feedback in the compared arms); ERP over survivors only; 4 earnings ticks per
name-year of 360 (1.1% of ticks) for the 2c variance share.

## 2026-10-07 round 2: attribute 2b (scripted distress/financing price shocks removed, SEO flat -3%) and 2c (earnings gap on unshaded consensus)

Arms: 2a = var/arms/2a-src, 2b = var/arms/2b-src, c = worktree src (2a+2b+2c). 20 years, 360 ticks/year, years 2-20.
Seeds 1-8 replay the macro recorded on the 2a tree (round 1); seeds 9-16 replay paths recorded on arm c.
Stage 2 (16 seeds) reached: targets (2) and (3) |t| >= 4 at 8 seeds; equity premium |t| 0.1-2.2 at 8, run to 16;
at 16 every ERP 2-se interval lies inside +-0.35pp (judged negligible against the 1-3pp gap to the US 4-6%). No failed runs.
Beat rate in the 2a column is 0 for seeds 1-8 (reused runs predate the counter): ignore it.

```
seeds n=16: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16]  years=20 tpy=360
quantity                            2a        2b     2b-2a       se       t  range(diff)
(a) med log(P/FV)               0.0162    0.0097   -0.0065   0.0009   -7.60  [-0.0111, -0.0002]
(b) slope lpf~(y10-pol)        -1.6377   -1.6667   -0.0290   0.0771   -0.38  [-0.4228, 0.6563]
(c) med trailing P/E           13.5248   13.3877   -0.1370   0.0325   -4.22  [-0.3858, 0.0557]
ERP: med ann TR - y10           0.0704    0.0720    0.0016   0.0008    2.06  [-0.0045, 0.0062]
med name-yr realized vol        0.2230    0.2211   -0.0019   0.0006   -3.05  [-0.0059, 0.0024]
(2) mean log ret, earn tick     0.0082    0.0068   -0.0013   0.0002   -7.34  [-0.0029, -0.0001]
var share on earn ticks         0.0472    0.0416   -0.0056   0.0010   -5.45  [-0.0132, 0.0023]
bankruptcies/seed               0.1250    0.3750    0.2500   0.1118    2.24  [0.0000, 1.0000]
splits/seed                    85.5625   85.1250   -0.4375   0.8849   -0.49  [-5.0000, 6.0000]
published beat rate             0.3527    0.7039    0.3512   0.0902    3.89  [-0.0038, 0.7405]
2b only (mean over seeds, se across seeds, range); 2a mean for context:
  2c var share on earn ticks (med of name mean-annual)   0.04158 se 0.00131  [0.03345, 0.05126]  2a 0.04719
  2c var share, pooled yrs (med)                         0.04073 se 0.00143  [0.03281, 0.05548]  2a 0.04688
  2c board-mean log ret, earn tick                       0.00682 se 0.00046  [0.00309, 0.01009]  2a 0.00816
seeds n=16: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16]  years=20 tpy=360
quantity                            2b         c      c-2b       se       t  range(diff)
(a) med log(P/FV)               0.0097   -0.0104   -0.0201   0.0012  -16.50  [-0.0297, -0.0125]
(b) slope lpf~(y10-pol)        -1.6667   -1.7741   -0.1074   0.0573   -1.87  [-0.4470, 0.3207]
(c) med trailing P/E           13.3877   13.2427   -0.1450   0.0330   -4.40  [-0.4305, 0.0700]
ERP: med ann TR - y10           0.0720    0.0712   -0.0008   0.0009   -0.83  [-0.0070, 0.0055]
med name-yr realized vol        0.2211    0.2193   -0.0018   0.0005   -3.56  [-0.0055, 0.0012]
(2) mean log ret, earn tick     0.0068   -0.0002   -0.0070   0.0001  -70.57  [-0.0077, -0.0061]
var share on earn ticks         0.0416    0.0364   -0.0052   0.0007   -7.50  [-0.0117, -0.0020]
bankruptcies/seed               0.3750    0.2500   -0.1250   0.1250   -1.00  [-1.0000, 1.0000]
splits/seed                    85.1250   85.8750    0.7500   0.7719    0.97  [-5.0000, 5.0000]
published beat rate             0.7039    0.7026   -0.0013   0.0012   -1.13  [-0.0090, 0.0063]
c only (mean over seeds, se across seeds, range); 2b mean for context:
  2c var share on earn ticks (med of name mean-annual)   0.03635 se 0.00146  [0.02913, 0.04912]  2b 0.04158
  2c var share, pooled yrs (med)                         0.03565 se 0.00139  [0.02706, 0.04535]  2b 0.04073
  2c board-mean log ret, earn tick                      -0.00018 se 0.00043  [-0.00337, 0.00289]  2b 0.00682
seeds n=16: [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16]  years=20 tpy=360
quantity                            2a         c      c-2a       se       t  range(diff)
(a) med log(P/FV)               0.0162   -0.0104   -0.0266   0.0011  -23.22  [-0.0373, -0.0178]
(b) slope lpf~(y10-pol)        -1.6377   -1.7741   -0.1364   0.0651   -2.09  [-0.7290, 0.2858]
(c) med trailing P/E           13.5248   13.2427   -0.2820   0.0382   -7.37  [-0.5166, -0.0128]
ERP: med ann TR - y10           0.0704    0.0712    0.0008   0.0009    0.87  [-0.0060, 0.0073]
med name-yr realized vol        0.2230    0.2193   -0.0038   0.0007   -5.29  [-0.0091, 0.0022]
(2) mean log ret, earn tick     0.0082   -0.0002   -0.0083   0.0002  -50.34  [-0.0100, -0.0073]
var share on earn ticks         0.0472    0.0364   -0.0108   0.0011   -9.46  [-0.0187, -0.0010]
bankruptcies/seed               0.1250    0.2500    0.1250   0.0854    1.46  [0.0000, 1.0000]
splits/seed                    85.5625   85.8750    0.3125   0.8353    0.37  [-6.0000, 6.0000]
published beat rate             0.3527    0.7026    0.3499   0.0903    3.87  [-0.0047, 0.7410]
c only (mean over seeds, se across seeds, range); 2a mean for context:
  2c var share on earn ticks (med of name mean-annual)   0.03635 se 0.00146  [0.02913, 0.04912]  2a 0.04719
  2c var share, pooled yrs (med)                         0.03565 se 0.00139  [0.02706, 0.04535]  2a 0.04688
  2c board-mean log ret, earn tick                      -0.00018 se 0.00043  [-0.00337, 0.00289]  2a 0.00816
```

## 2026-10-07 round 3: why the equity premium is ~7.1% (arm c, diagnosis)

Arm cx (worktree src = 2a+2b+2c, PF_X=1), seeds 1-8 on the round-1 macro paths, 20y, 360 tpy, years 2-20.
Failed names in A(ii) are counted as -100% annualized (included in the median). Index: cap weights at the start of
each quarter (buy-and-hold within the quarter); B index terms are cap-weighted per-name log terms, plus the residual
log(sum w e^r) - sum w r ("diversification"). EPS terms use TTM EPS; names with EPS <= 0 at a quarter end go to "FV growth, EPS<=0".

```
arm cx, seeds [1, 2, 3, 4, 5, 6, 7, 8], years 2-20
quantity                                      mean       se  per seed
A(i) median survivor geo TR - y10           0.0732   0.0038  0.0707 0.0762 0.0918 0.0603 0.0691 0.0603 0.0735 0.0841
A(ii) incl. failed as -100%                 0.0731   0.0038  0.0707 0.0762 0.0913 0.0600 0.0691 0.0603 0.0735 0.0841
A(iii) cap-wt index geo TR - y10            0.0710   0.0028  0.0676 0.0691 0.0840 0.0587 0.0675 0.0667 0.0764 0.0778
  failures in years 2-20                    0.2500   0.1637  0.0000 0.0000 1.0000 1.0000 0.0000 0.0000 0.0000 0.0000
B idx: log TR / yr                          0.1135   0.0031  0.1127 0.1115 0.1146 0.0962 0.1144 0.1155 0.1288 0.1144
B idx: dividend (log)                       0.0283   0.0009  0.0277 0.0275 0.0269 0.0284 0.0258 0.0270 0.0339 0.0291
B idx: TTM EPS growth                       0.0640   0.0058  0.0612 0.0635 0.0557 0.0316 0.0837 0.0810 0.0733 0.0617
B idx: FV/EPS growth                        0.0022   0.0021  0.0055 -0.0039 0.0093 0.0112 0.0014 -0.0016 -0.0028 -0.0017
B idx: FV growth, EPS<=0 names             -0.0054   0.0013  -0.0046 -0.0010 -0.0065 -0.0138 -0.0052 -0.0036 -0.0042 -0.0042
B idx: dlog(P/FV)                           0.0036   0.0025  0.0044 0.0044 0.0071 0.0119 -0.0086 -0.0055 0.0081 0.0069
B idx: diversification (residual)           0.0209   0.0011  0.0184 0.0211 0.0220 0.0269 0.0174 0.0183 0.0206 0.0227
B names: n (EPS>0 both ends)               77.6250   0.4199  76.0000 78.0000 78.0000 78.0000 76.0000 77.0000 79.0000 79.0000
B names EW: log TR / yr                     0.1139   0.0029  0.1156 0.1138 0.1209 0.0951 0.1182 0.1123 0.1205 0.1150
B names EW: dividend                        0.0324   0.0005  0.0325 0.0321 0.0310 0.0329 0.0303 0.0322 0.0353 0.0330
B names EW: EPS growth                      0.0837   0.0037  0.0819 0.0818 0.0921 0.0614 0.0959 0.0886 0.0873 0.0809
B names EW: FV/EPS growth                  -0.0022   0.0019  0.0011 -0.0069 0.0041 0.0035 -0.0008 -0.0025 -0.0110 -0.0047
B names EW: dlog(P/FV)                     -0.0001   0.0023  0.0001 0.0068 -0.0063 -0.0028 -0.0071 -0.0060 0.0089 0.0059
B names median: log TR                      0.1164   0.0031  0.1159 0.1182 0.1215 0.0976 0.1213 0.1104 0.1262 0.1200
B names median: dividend                    0.0293   0.0005  0.0274 0.0295 0.0300 0.0293 0.0279 0.0279 0.0316 0.0308
B names median: EPS growth                  0.0896   0.0033  0.0817 0.0905 0.0942 0.0722 0.1015 0.0955 0.0958 0.0852
B names median: FV/EPS growth              -0.0037   0.0020  -0.0014 -0.0118 0.0052 0.0001 -0.0012 -0.0039 -0.0116 -0.0050
B names median: dlog(P/FV)                  0.0003   0.0024  0.0005 0.0066 -0.0073 -0.0016 -0.0073 -0.0051 0.0101 0.0064
C CoE - y10Ema, median name                 0.0395   0.0013  0.0369 0.0390 0.0433 0.0434 0.0354 0.0369 0.0366 0.0448
C CoE - y10Ema, cap-weighted                0.0402   0.0008  0.0389 0.0398 0.0439 0.0417 0.0378 0.0392 0.0377 0.0428
C floor binds (share name-qtrs)             0.1202   0.0103  0.1746 0.0963 0.1332 0.1395 0.1049 0.1166 0.1173 0.0796
C median levered beta                       0.8294   0.0031  0.8224 0.8300 0.8265 0.8218 0.8210 0.8313 0.8354 0.8468
C macro ERP, time mean                      0.0472   0.0016  0.0439 0.0468 0.0521 0.0520 0.0429 0.0439 0.0432 0.0527
C median name sigma^2/2                     0.0279   0.0011  0.0268 0.0300 0.0243 0.0313 0.0269 0.0238 0.0318 0.0279
C index sigma^2/2                           0.0115   0.0006  0.0085 0.0125 0.0107 0.0122 0.0110 0.0120 0.0140 0.0108
C expected geo excess, median name          0.0117   0.0016  0.0101 0.0090 0.0190 0.0121 0.0085 0.0131 0.0047 0.0168
C expected geo excess, index                0.0288   0.0011  0.0304 0.0273 0.0332 0.0294 0.0268 0.0271 0.0236 0.0320
D nominal GDP growth (log)                  0.0413   0.0014  0.0437 0.0379 0.0450 0.0365 0.0466 0.0432 0.0414 0.0360
D inflation, mean                           0.0223   0.0007  0.0222 0.0197 0.0237 0.0232 0.0209 0.0213 0.0257 0.0212
D y10, mean                                 0.0493   0.0028  0.0518 0.0489 0.0374 0.0422 0.0536 0.0557 0.0611 0.0434
D y10Ema, mean                              0.0493   0.0028  0.0519 0.0486 0.0374 0.0427 0.0534 0.0558 0.0611 0.0434
D board TTM NI growth (log)                 0.0571   0.0026  0.0512 0.0545 0.0636 0.0424 0.0643 0.0622 0.0604 0.0584
D board book equity growth                  0.0590   0.0012  0.0651 0.0571 0.0593 0.0594 0.0590 0.0597 0.0591 0.0533
D board market cap growth                   0.0566   0.0022  0.0575 0.0543 0.0634 0.0432 0.0573 0.0563 0.0619 0.0588
```

Reading: realized index log TR 11.35%/yr = dividend 2.83 + per-share TTM EPS growth 6.40 + FV/EPS 0.22 + loss-makers -0.54
+ dlog(P/FV) 0.36 + cross-sectional Jensen 2.09. Valuation terms sum to ~0; the excess over CAPM (CoE - y10 = 4.0%,
geometric index 2.9% after sigma^2/2 = 1.15%) is cash-flow growth: board NI +5.7%/yr and cap-weighted per-share EPS +6.4%/yr
against nominal GDP +4.1%/yr, with fair value never pricing that growth in ex ante.

## 2026-10-07 round 4: growth fair value assumes vs growth firms deliver (arm c, diagnosis)

Arm cg (worktree src, PF_X=1 PF_G=1), seeds 1-8 on the round-1 macro paths, 20y, 360 tpy, years 2-20. Assumed g =
calculateFundableGrowth(calculateExpectedNominalGrowth(...), equityReturn, targetPayoutRatio), recomputed from the same
context (re-strike check in RUN.md); cg reproduces c bit for bit. Delivered growth = OLS trend of log TTM NI / revenue /
split-adjusted EPS over the 77 quarter-ends (firms positive throughout; NI falls back to endpoints). Gap = delivered NI - log(1+mean g).
Retention from per-report flows of survivors: retained = sum(NI - div - buyback)/T/avg book; other = dBook - retained.

```
arm cg, seeds [1, 2, 3, 4, 5, 6, 7, 8], years 2-20
quantity                                      mean       se  per seed
1 assumed g, median name                    0.0246   0.0012  0.027 0.025 0.021 0.020 0.029 0.027 0.027 0.021
1 assumed g, cap-weighted                   0.0287   0.0011  0.030 0.029 0.026 0.025 0.033 0.031 0.030 0.025
1 outlook leg, median                       0.0259   0.0012  0.028 0.026 0.023 0.022 0.031 0.029 0.028 0.022
1 outlook leg, cap-wt                       0.0294   0.0011  0.031 0.029 0.026 0.026 0.034 0.032 0.031 0.026
1 fundable cap ER*(1-payout), median        0.1069   0.0021  0.113 0.106 0.103 0.099 0.111 0.114 0.111 0.099
1 fundable cap, cap-wt                      0.1145   0.0018  0.118 0.112 0.112 0.111 0.121 0.121 0.114 0.106
1 share name-qtrs cap binds                 0.0987   0.0054  0.094 0.080 0.115 0.099 0.100 0.116 0.109 0.075
1 secularGrowth median                      0.0200   0.0000  0.020 0.020 0.020 0.020 0.020 0.020 0.020 0.020
1 secularGrowth min                         0.0100   0.0000  0.010 0.010 0.010 0.010 0.010 0.010 0.010 0.010
1 secularGrowth max                         0.0600   0.0000  0.060 0.060 0.060 0.060 0.060 0.060 0.060 0.060
1 names at 0.02 default                    32.0000   0.0000  32.000 32.000 32.000 32.000 32.000 32.000 32.000 32.000
1 names                                    78.7500   0.1637  79.000 79.000 78.000 78.000 79.000 79.000 79.000 79.000
2 NI growth (log), median firm              0.0581   0.0015  0.059 0.056 0.064 0.055 0.061 0.055 0.064 0.052
2 NI growth, cap-wt                         0.0486   0.0042  0.055 0.051 0.062 0.024 0.046 0.044 0.061 0.045
2 firms with NI growth                     77.6250   0.4199  76.000 78.000 78.000 78.000 76.000 77.000 79.000 79.000
2 revenue growth, median                    0.0517   0.0010  0.055 0.051 0.054 0.049 0.053 0.053 0.052 0.047
2 revenue growth, cap-wt                    0.0506   0.0013  0.056 0.054 0.048 0.047 0.050 0.054 0.051 0.045
2 EPS/share growth, median                  0.0920   0.0024  0.095 0.088 0.092 0.087 0.099 0.088 0.104 0.083
2 EPS/share growth, cap-wt                  0.0925   0.0024  0.096 0.087 0.098 0.090 0.094 0.092 0.102 0.081
2 firms EPS>0 throughout                   68.5000   1.2956  70.000 72.000 67.000 61.000 71.000 68.000 72.000 67.000
2 share-count shrink, median                0.0226   0.0007  0.022 0.022 0.021 0.021 0.026 0.024 0.025 0.021
2 share-count shrink, cap-wt                0.0285   0.0009  0.027 0.030 0.026 0.024 0.030 0.032 0.031 0.027
3 gap NI - log(1+g), median                 0.0298   0.0013  0.028 0.027 0.038 0.029 0.029 0.028 0.032 0.027
3 gap NI, cap-wt                            0.0216   0.0040  0.026 0.024 0.038 0.001 0.015 0.015 0.032 0.022
3 gap EPS/share - g, median                 0.0651   0.0020  0.063 0.063 0.073 0.061 0.064 0.059 0.075 0.063
3 gap EPS/share, cap-wt                     0.0650   0.0021  0.067 0.059 0.073 0.065 0.062 0.063 0.073 0.057
3 corr(gap, firm log TR) Pearson            0.6049   0.0609  0.633 0.503 0.683 0.804 0.658 0.653 0.230 0.675
3 corr(gap, firm log TR) Spearman           0.5544   0.0455  0.591 0.458 0.563 0.810 0.469 0.521 0.394 0.629
4 board ROE (NI/avg book)                   0.1730   0.0036  0.176 0.176 0.167 0.159 0.180 0.179 0.187 0.159
4 total payout (div+bb)/NI                  0.8225   0.0041  0.804 0.833 0.811 0.823 0.833 0.836 0.825 0.815
4 dividend payout                           0.3391   0.0056  0.331 0.333 0.349 0.363 0.318 0.321 0.350 0.347
4 buyback payout                            0.4833   0.0082  0.473 0.500 0.462 0.460 0.515 0.516 0.475 0.467
4 ROE*(1-payout) = retained/book            0.0307   0.0008  0.035 0.029 0.032 0.028 0.030 0.029 0.033 0.029
4 other equity flows/book                   0.0237   0.0008  0.025 0.022 0.021 0.027 0.024 0.025 0.024 0.020
4 dBook/avg book                            0.0544   0.0011  0.060 0.052 0.053 0.055 0.054 0.055 0.057 0.050
4 book growth (log)                         0.0590   0.0012  0.065 0.057 0.059 0.059 0.059 0.060 0.059 0.053
4 aggregate NI growth (log)                 0.0571   0.0026  0.051 0.054 0.064 0.042 0.064 0.062 0.060 0.058
  nominal GDP growth (log)                  0.0413   0.0014  0.044 0.038 0.045 0.036 0.047 0.043 0.041 0.036
gap NI - g by business model (pooled firm-seeds): mean, sd, n
  merchant_house                0.1322  0.0101    8
  conglomerate                  0.1040  0.0373   24
  utility                       0.0821  0.0286   16
  oil_gas_producer              0.0805  0.0351    7
  mining                        0.0795  0.0503    7
  financial_data                0.0739  0.0195   16
  law_firm                      0.0670  0.0224    8
  education                     0.0590  0.0102    8
  railroad                      0.0546  0.0072   16
  tools_and_accessories         0.0530  0.0255   16
  internet_retail               0.0482  0.0423    8
  investment_bank               0.0455  0.0176   24
  construction                  0.0454  0.0226    8
  medical_care_facility         0.0413  0.0087    8
  brokerage                     0.0406  0.0281    8
  chemical                      0.0399  0.0420    6
  biotech                       0.0395  0.0144    8
  asset_manager                 0.0383  0.0059    8
  consumer_staples              0.0379  0.0202   40
  heavy_manufacturing           0.0367  0.0189   40
  logistics                     0.0350  0.0265    8
  apparel_manufacturing         0.0323  0.0082    8
  waste_management              0.0304  0.0117    8
  distressed_debt               0.0287  0.0196    8
  computer_hardware             0.0285  0.0113    8
  resorts_casinos               0.0282  0.0122    8
  communication_equipment       0.0273  0.0284    8
  defense_contractor            0.0270  0.0668   16
  security_protection           0.0260  0.0116   16
  specialty_industrial_machinery  0.0250  0.0084   24
  luxury                        0.0233  0.0108    8
  credit_services               0.0226  0.0282   16
  restaurant                    0.0226  0.0162   16
  tech                          0.0223  0.0102    8
  commercial_bank               0.0215  0.0206   24
  advertising_agency            0.0196  0.0078    8
  telecom                       0.0184  0.0199    8
  steel_manufacturing           0.0165  0.0494    8
  hedge_fund                    0.0138  0.0088    8
  semiconductor                 0.0134  0.0151    8
  shadow_bank                   0.0132  0.0177    8
  auto_manufacturer             0.0067  0.0438   15
  private_equity                0.0060  0.0282    5
  clearing_house                0.0054  0.0172    8
  reit                          0.0045  0.0152   32
  shipping                      0.0028  0.0370   15
  investment_company            0.0005  0.0097    8
  reinsurance                  -0.0448  0.0674    8
  retail_insurance             -0.0524  0.0865    8
  refining                     -0.0525  0.0580    6
```

Reading: fair value assumes ~2.5-2.9% nominal (secular 2% real for 32 of 79 names + half of inflation:
INFLATION_NOMINAL_GROWTH_PASS_THROUGH 0.50), below nominal GDP 4.1%; the fundable cap (~11%) binds 10% of name-quarters.
Firms deliver NI +5.8% (median) / +4.9% (cap-wt), EPS per share +9.2% (buybacks shrink shares 2.3-2.9%/yr).
Under-forecast 2.2-3.0pp on aggregate NI, 6.5pp per share; gap correlates 0.60 with firm return. Retention funds 3.1pp of
5.4%/yr book growth; 2.4pp comes from other equity flows (not split further).

## 2026-10-07 round 5: change 2d (expected nominal growth carries inflation in full; pass-through 0.50 deleted)

BEFORE = 2c (var/arms/2c-src; seeds 1-8 reuse runs4, verified bit-identical over year 1), AFTER = worktree src. Each arm
opens from its own seed. Seeds 1-16 (1-8 on round-1 macro paths, 9-16 on round-2 paths), 20y, 360 tpy, years 2-20.
Stage 2 reached: at 8 seeds target (3) was t -2.1 (2-se band [-1.0, 0.0]pp). At 16 seeds: (2) and (3) |t| >= 4,
(1) 2-se band [-0.11, +0.29]pp, negligible. No failed runs.

```
seeds n=16 [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16]
quantity                              BEFORE     AFTER      diff       se       t
(1) cap-wt index geo TR - y10         0.0695    0.0704    0.0009   0.0010    0.91
(2) median trailing P/E              13.2427   13.7327    0.4899   0.0438   11.18
(3) gap NI growth - g, cap-wt         0.0224    0.0165   -0.0059   0.0013   -4.56
median-name premium                   0.0712    0.0699   -0.0013   0.0013   -1.01
assumed g, median                     0.0247    0.0350    0.0104   0.0002   52.28
assumed g, cap-wt                     0.0287    0.0371    0.0085   0.0002   47.86
fundable cap binds                    0.0982    0.1243    0.0262   0.0026   10.23
MAX_EXPECTED_GROWTH binds             0.0709    0.2091    0.1382   0.0078   17.68
gap NI growth - g, median             0.0316    0.0237   -0.0080   0.0007  -11.40
NI growth, cap-wt                     0.0494    0.0518    0.0024   0.0013    1.85
share-count shrink, median            0.0222    0.0217   -0.0005   0.0005   -0.97
share-count shrink, cap-wt            0.0288    0.0288    0.0000   0.0006    0.04
dividend yield (log), index           0.0279    0.0268   -0.0010   0.0003   -3.78
dividend yield (log), median name     0.0287    0.0277   -0.0010   0.0004   -2.90
median log(P/FV)                     -0.0104   -0.0114   -0.0011   0.0013   -0.82
median realized vol                   0.2193    0.2198    0.0005   0.0006    0.89
bankruptcies/seed                     0.2500    0.1250   -0.1250   0.0854   -1.46
```

Reading: assumed g rises 0.85-1.04pp, short of the full ~1.1pp because MAX_EXPECTED_GROWTH (0.05) now binds on 21% of
firm-quarters (from 7%) and the fundable cap on 12% (from 10%). Fair P/E +0.49, but the index premium does not move:
the cap-weighted gap closes only 0.6 of 2.2pp, and P/FV, buybacks and vol are unchanged.

## 2026-10-07 round 6: corporate growth audit, measurement (arm 2d = worktree src, diagnosis)

Question: why does board NI outgrow nominal GDP, and what funds the book growth that retained earnings do not?
Arm cb (2d src, PF_X PF_G PF_B), seeds 1-8 on round-1 macro paths, 20y, 360 tpy, years 2-20, survivors. cb == cg bit for bit.
Bridge flows are sums over years / sum of opening books (per year). Firm growth = OLS trend of log TTM levels.

```
arm cb, seeds [1, 2, 3, 4, 5, 6, 7, 8], years 2-20 (survivors)
quantity                                            mean       se
1 aggregate NI growth (log)                       0.0594   0.0029
1 aggregate revenue growth                        0.0585   0.0018
1 aggregate net-margin change                     0.0009   0.0023
1 nominal GDP growth                              0.0413   0.0014
1 real GDP growth                                 0.0187   0.0012
1 GDP deflator growth                             0.0226   0.0006
1 firm revenue growth, median                     0.0527   0.0018
1 firm revenue growth, rev-wt                     0.0485   0.0026
1 firm NI growth, median                          0.0609   0.0020
1 firm NI growth, rev-wt                          0.0615   0.0025
1 firm margin change, median                      0.0034   0.0007
1 firm margin change, rev-wt                      0.0071   0.0018
1 revenue - nominal GDP, median                   0.0114   0.0010
1 revenue - nominal GDP, rev-wt                   0.0072   0.0021
2 aggregate invested capital growth               0.0519   0.0009
2 aggregate net PP&E growth                       0.0664   0.0017
2 aggregate total assets growth                   0.0511   0.0010
2 aggregate turnover change (rev - IC)            0.0066   0.0018
2 firm IC growth, median                          0.0554   0.0018
2 firm IC growth, rev-wt                          0.0501   0.0021
2 firm turnover change, median                   -0.0009   0.0004
2 firm turnover change, rev-wt                   -0.0016   0.0015
2 industry price level growth, median             0.0000   0.0000
2 industry price level growth, rev-wt             0.0001   0.0000
2 firms with price level                         79.0000   0.0000
2 volume (rev - price), median                    0.0523   0.0017
2 volume, rev-wt                                  0.0483   0.0026
2 capex / depreciation, board                     1.6832   0.0100
4 dBook / opening book (per yr)                   0.0580   0.0010
4 retained = NI - div - bb                        0.0328   0.0008
4   NI                                            0.1803   0.0041
4   dividends                                    -0.0596   0.0008
4   buybacks                                     -0.0879   0.0033
4 non-retained total                              0.0252   0.0010
4   stock compensation (APIC)                     0.0224   0.0003
4   equity issuance                               0.0000   0.0000
4   other in treasury strategy                    0.0000   0.0000
4   treasury finalize residual                    0.0001   0.0001
4   asset-sale loss (OCI)                        -0.0000   0.0000
4   goodwill impairment                          -0.0006   0.0001
4   AFS + anchor-stake marks                      0.0025   0.0007
4   M&A equity (stock-paid deals)                 0.0000   0.0000
4   divestiture gain/loss                         0.0009   0.0002
4   other inside the tick                        -0.0000   0.0000
4   operator (reorgs etc.)                        0.0001   0.0000
4 unexplained (check ~0)                         -0.0000   0.0000
5 acquisitions per year (board)                   9.6908   0.4858
5 acquired revenue / board revenue (per yr)       0.0046   0.0003
5 divestitures per year                           0.7237   0.1265
5 divested revenue / board revenue               -0.0017   0.0003
5 M&A spend / opening book (per yr)               0.0103   0.0007
5 goodwill booked / opening book                  0.0021   0.0001
revenue growth - nominal GDP by business model (pooled firm-seeds): top 10, bottom 5
  merchant_house                0.0963    8
  conglomerate                  0.0834   24
  financial_data                0.0642   16
  utility                       0.0424   16
  education                     0.0395    8
  private_equity                0.0383    8
  tools_and_accessories         0.0361   16
  railroad                      0.0348   16
  brokerage                     0.0345    8
  law_firm                      0.0332    8
  ...
  construction                 -0.0112    8
  reinsurance                  -0.0126    8
  refining                     -0.0198    8
  auto_manufacturer            -0.0238   16
  reit                         -0.0253   32
by sector:
  Utilities                     0.0424   16
  Industrials                   0.0239  200
  Financials                    0.0200  168
  Health Care                   0.0194   16
  Information Technology        0.0164   32
  Consumer Staples              0.0097   40
  Materials                     0.0091   24
  Consumer Discretionary        0.0088   80
  Communication Services        0.0046    8
  Energy                       -0.0082   16
  Real Estate                  -0.0253   32
top 5 firms by share of board non-retained equity flows (mean over seeds):
  SWAN      0.210
  BRKW      0.154
  WEAV      0.111
  HUMM      0.076
  IBIS      0.058
```

Reading: NI outgrows nominal GDP 1.8pp because revenue does (+1.7pp aggregate; margins +0.1pp). Revenue follows capital:
IC +5.2%, net PP&E +6.6% (capex/dep 1.68), turnover ~flat per firm. M&A adds net +0.3pp (off-board private acquisitions only).
Aggregate revenue (5.85%) beats the revenue-weighted firm trend (4.85%): fast-growing models (merchant house, conglomerate,
financial data, +6-10pp over GDP) gain share. industry_price_level is flat (not a price index), so price/volume cannot be split.
Book: retained 3.3%/yr + SBC 2.24pp (APIC; SBC is settled in new shares, so it is legitimate) + marks 0.25 + divestiture 0.09
- impairment 0.06 = 5.8%; no equity issuance and no stock-paid deals.
