# Grocer (SPRW) evidence

## 2026-10-25: SPRW behaviour check, live macro (arm `live`, seeds 1-4, 20y, TPY 360, no Diet), SGRB and LARK beside it

Working tree on HEAD 3790b315. Run: `rm -rf var/harness/cache/test; bin/harness-batch var/harness/grocery/run.sh 1-4 live && python3 var/harness/grocery/analyse.py live` (table `live_4seed_20y.out`). Opening probe `bin/harness-batch var/harness/grocery/open_probe.sh 1 base`: SPRW revenue 190.0B, OM 3.5%, P/E 18.43, P/B 2.87, cap 86.8B (inside 65-95B; x0.92 with sam_ratio would land 80B). SGRB 45.2B / 22% / 23.4 / 7.89 / 176.9B; LARK 86.7B / 24% / 23.7 / 7.06 / 389.5B.

```
arm live, seeds [1, 2, 3, 4], NaN hits 0, deaths [[], [], [], []]

== SPRW (Grocery Stores) ==
open rev bn                            190.02         190.02         190.02         190.02   |  190.02
open om                                  3.5%           3.5%           3.5%           3.5%   |    3.5%
open P/E                                18.43          18.43          18.43          18.43   |   18.43
open P/B                                 2.87           2.87           2.87           2.87   |    2.87
open cap bn                             86.82          85.73          85.81          85.89   |   86.06
rev CAGR                                 4.7%           4.4%           4.3%           4.3%   |    4.4%
om mean                                  4.0%           4.2%           3.8%           3.9%   |    4.0%
om min                                  -1.7%           0.2%          -1.0%          -0.1%   |   -0.6%
om max                                   8.6%           7.9%           7.1%           9.0%   |    8.1%
neg EBIT q share                         5.0%           0.0%           1.2%           2.5%   |    2.2%
EPS g SD                               244.2%         131.6%       77099.4%         211.7%   | 19421.7%
neg annual EPS years                     1.00           0.00           0.00           0.00   |    0.25
vol                                     17.6%          18.3%          18.2%          16.2%   |   17.6%
beta wk                                  0.71           0.75           0.71           0.77   |    0.73
maxDD                                  -38.3%         -39.6%         -53.9%         -30.1%   |  -40.5%
div cuts                                 4.00           0.00           1.00           3.00   |    2.00
distress q                               0.00           0.00           0.00           0.00   |    0.00
worst rating                               BB             BB             BB             BB   | 
dead/default/reorg                      0/0/0          0/0/0          0/0/0          0/0/0   | 
treasury distress lines                  8.00           6.00           6.00           1.00   |    5.25
P/E settle (y3-20 med)                  13.91          16.28          13.44          17.92   |   15.39
P/E open - settle                        4.52           2.15           4.99           0.51   |    3.04
EPS beat rate                           82.5%          75.0%          80.0%          71.2%   |   77.2%
share open g/f                    0.899/0.101    0.900/0.100    0.901/0.099    0.900/0.100   | 
share end4q g/f                   0.866/0.134    0.911/0.089    0.898/0.102    0.898/0.102   | 
zero stream quarters                     0.00           0.00           0.00           0.00   |    0.00
basket_volume_index p5/p95        0.979/1.021    0.976/1.026    0.980/1.022    0.983/1.026   | 
shelf_price_index p5/p95          0.958/1.026    0.968/1.027    0.986/1.031    0.951/1.004   | 
war onsets / 20y                         1.00           4.00           1.00           2.00   |    2.00
war quarter share                        0.38           0.23           0.07           0.39   |    0.27
om war - peace pp                       -3.40          -2.69          -2.88          -2.09   |   -2.76
rel energy price p5/p95         -0.253/+0.583  -0.321/+0.226  -0.336/+0.389  -0.383/+0.545   | 
corr(rev yoy, d4 energy)                 0.33          -0.02           0.28           0.38   |    0.24
corr(EBIT yoy, d4 energy)               -0.08          -0.11          -0.13          -0.21   |   -0.13
corr(fuel rev yoy, d4 energy)            0.93           0.85           0.93           0.95   |    0.92
corr(basket idx, gapEma)                -0.32           0.31          -0.32          -0.00   |   -0.08
slump quarters pooled 75: rev yoy - mean -0.67pp, EBIT yoy - mean +155.27pp, yoy margin change - mean -0.22pp

== SGRB (Packaged Foods) ==
open rev bn                             45.18          45.18          45.18          45.18   |   45.18
open om                                 22.0%          22.0%          22.0%          22.0%   |   22.0%
open P/E                                23.38          23.38          23.38          23.38   |   23.38
open P/B                                 7.89           7.89           7.89           7.89   |    7.89
open cap bn                            176.87         179.28         176.39         177.53   |  177.52
rev CAGR                                 3.3%           3.7%           3.2%           3.5%   |    3.4%
om mean                                 23.3%          23.8%          22.7%          23.4%   |   23.3%
om min                                  19.9%          20.6%          20.2%          20.5%   |   20.3%
om max                                  27.3%          27.1%          25.3%          26.0%   |   26.4%
neg EBIT q share                         0.0%           0.0%           0.0%           0.0%   |    0.0%
EPS g SD                                18.7%          18.2%          19.1%          19.2%   |   18.8%
neg annual EPS years                     0.00           0.00           0.00           0.00   |    0.00
vol                                     18.4%          18.3%          16.4%          15.6%   |   17.2%
beta wk                                  0.60           0.56           0.55           0.54   |    0.56
maxDD                                  -36.5%         -39.3%         -38.0%         -32.4%   |  -36.6%
div cuts                                 1.00           1.00           1.00           1.00   |    1.00
distress q                               0.00           0.00           0.00           0.00   |    0.00
worst rating                                A              A              A              A   | 
dead/default/reorg                      0/0/0          0/0/0          0/0/0          0/0/0   | 
treasury distress lines                  0.00           0.00           0.00           0.00   |    0.00
P/E settle (y3-20 med)                  18.30          17.71          16.70          17.69   |   17.60
P/E open - settle                        5.08           5.67           6.68           5.69   |    5.78
EPS beat rate                           80.0%          77.5%          70.0%          86.2%   |   78.4%
slump quarters pooled 73: rev yoy - mean -0.12pp, EBIT yoy - mean +0.46pp, yoy margin change - mean +0.11pp

== LARK (Household & Personal Products) ==
open rev bn                             86.70          86.70          86.70          86.70   |   86.70
open om                                 24.0%          24.0%          24.0%          24.0%   |   24.0%
open P/E                                23.71          23.71          23.71          23.71   |   23.71
open P/B                                 7.06           7.06           7.06           7.06   |    7.06
open cap bn                            389.51         388.21         387.97         388.23   |  388.48
rev CAGR                                 4.8%           4.4%           3.9%           4.1%   |    4.3%
om mean                                 24.6%          25.0%          24.0%          24.6%   |   24.5%
om min                                  19.9%          18.3%          17.1%          17.8%   |   18.3%
om max                                  27.5%          27.4%          26.6%          27.4%   |   27.2%
neg EBIT q share                         0.0%           0.0%           0.0%           0.0%   |    0.0%
EPS g SD                                19.2%           6.3%           7.2%          17.8%   |   12.6%
neg annual EPS years                     0.00           0.00           0.00           0.00   |    0.00
vol                                     13.9%          12.5%          13.2%          11.7%   |   12.8%
beta wk                                  0.77           0.76           0.77           0.75   |    0.76
maxDD                                  -30.7%         -21.1%         -37.7%         -24.9%   |  -28.6%
div cuts                                 1.00           1.00           0.00           1.00   |    0.75
distress q                               0.00           0.00           0.00           0.00   |    0.00
worst rating                                A              A              A              A   | 
dead/default/reorg                      0/0/0          0/0/0          0/0/0          0/0/0   | 
treasury distress lines                  0.00           0.00           0.00           0.00   |    0.00
P/E settle (y3-20 med)                  21.08          19.98          16.27          20.70   |   19.51
P/E open - settle                        2.63           3.73           7.43           3.01   |    4.20
EPS beat rate                           85.0%          78.8%          77.5%          77.5%   |   79.7%
slump quarters pooled 75: rev yoy - mean -0.64pp, EBIT yoy - mean -1.44pp, yoy margin change - mean -0.17pp
```

War quarters are read exactly from the shelf index over its farm-price level (two values, 0.984 war and 1.004 peace). Onsets 1, 4, 1, 2 (design ~2); war share 27% (stationary 20%): seed 1 has one 30-quarter war and seed 4 one of 26 (each ~6.5% at a 10%/q exit), the rest last 1-8. War margin -2.8pp vs peace against a mechanical 1.8pp (2% on 90% of sales); the excess is timing (both long wars sit in years 2-9). Slumps (75 pooled quarters): revenue -0.7pp, margin change -0.2pp, so neutral rather than defensive; the basket index reads the domestic demand gap and mood, corr with the output gap EMA -0.08 (-0.32 to +0.31). Fuel: fuel revenue yoy corr 0.92 with the 4q relative-energy change, firm revenue 0.24, EBIT -0.13. Annual EPS growth SD blows up on near-zero years (3.5% margin): 0.25 negative-EPS years instead. Opens 3.0 P/E points above where it settles (18.4 -> 15.4). No NaN, deaths or distress.

## 2026-10-25 (b): arm `fix` (variable-cost noise share 0.13 for grocery; SPRW x0.92 incl. sam 0.12)

Run: `rm -rf var/harness/cache/test; bin/harness-batch var/harness/grocery/run.sh 1-4 fix && python3 var/harness/grocery/analyse.py fix && python3 var/harness/grocery/compare.py live fix` (tables `fix_4seed_20y.out`, `compare_live_fix.out`). Opening probe seed 1 (`open_fix.out`): SPRW revenue 174.7B, OM 3.5%, P/E 18.45, P/B 2.87, cap 79.8B; SGRB, LARK, BELL, TERN tick-1 caps identical to the `live` probe (176.87, 389.51, 204.51, 51.19B). Seeds 1-4, 20y, TPY 360; arms do not pair.

```
SPRW, seeds 1-4                                     live                       fix
open rev bn                                       190.02                    174.73
open om                                             3.5%                      3.5%
open P/E                                           18.43                     18.45
open P/B                                            2.87                      2.87
open cap bn                                        86.06                     79.15
rev CAGR                                            4.4%                      4.3%
om mean                                             4.0%                      3.6%
om min                                             -0.6%                      1.6%
om max                                              8.1%                      5.3%
neg EBIT q share                                    2.2%                      0.0%
neg annual EPS years                                0.25                      0.00
vol                                                17.6%                     19.1%
beta wk                                             0.73                      0.73
maxDD                                             -40.5%                    -44.3%
div cuts                                            2.00                      0.75
distress q                                          0.00                      0.00
worst rating                                 BB BB BB BB               BB BB BB BB
dead/default/reorg               0/0/0 0/0/0 0/0/0 0/0/0   0/0/0 0/0/0 0/0/0 0/0/0
P/E settle (y3-20 med)                             15.39                     18.36
P/E open - settle                                   3.04                      0.09
EPS beat rate                                      77.2%                     80.0%
share open g/f                             0.899/0.101..             0.899/0.101..
share end4q g/f                            0.866/0.134..             0.902/0.098..
zero stream quarters                                0.00                      0.00
basket_volume_index p5/p95                 0.979/1.021..             0.983/1.024..
shelf_price_index p5/p95                   0.958/1.026..             0.964/1.022..
war onsets / 20y                                    2.00                      2.00
war quarter share                                   0.27                      0.27
om war - peace pp                                  -2.76                     -1.61
rel energy price p5/p95                  -0.253/+0.583..           -0.358/+0.149..
corr(rev yoy, d4 energy)                            0.24                      0.25
corr(EBIT yoy, d4 energy)                          -0.13                      0.03
corr(fuel rev yoy, d4 energy)                       0.92                      0.92
corr(basket idx, gapEma)                           -0.08                     -0.00
slump pooled live: n 75, rev yoy - mean -0.67pp, yoy margin change - mean -0.22pp
slump pooled fix: n 64, rev yoy - mean -1.04pp, yoy margin change - mean +0.26pp
```

Margin peaks gone: 1.6-5.3% (live -0.6 to 8.1%), no negative-EBIT quarter or negative-EPS year. War minus peace -1.6pp, now the mechanical 1.8pp. Settled P/E 18.4 = opening 18.45. Slumps (64 quarters): revenue -1.0pp, margin change +0.3pp (mildly defensive in margin). Fuel: firm revenue corr 0.25, EBIT 0.03. Long wars again in seeds 1 and 3 (45%, 41% of quarters, 2 onsets each).
Annual EPS growth SD now finite: 47% (28-63; the two long-war seeds highest), since a war takes 1.8pp of a 3.5% margin, half the profit.
