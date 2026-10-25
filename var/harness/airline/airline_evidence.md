# Airline (TERN) evidence

## 2026-10-25: TERN behaviour check, live macro (arm `live`, seeds 1-4, 20y, TPY 360, no Diet), CANV beside it

Run: `rm -rf var/harness/cache/test; bin/harness-batch var/harness/airline/run.sh 1-4 live && python3 var/harness/airline/analyse.py live` (table `live_4seed_20y.out`); `python3 var/harness/airline/diag.py` (`diag_live.out`). Opening probe: `open_probe.sh base 1`; `make_ovr.sh && open_probe.sh scale18 1`.

Opening (seed 1): revenue 44.6B, OM 9.0%, P/E 10.81, P/B 1.18, cap 28.0B (target ~50B). Balance sheet x1.8 (treasury 10.8B, equity 43.2B, debt 34.2B, retained 18.0B): revenue 60.6B, P/E 15.81, P/B 1.19, cap 51.1B. Revenue scales only x1.36 and P/E rises 10.8 -> 15.8 with it, so cap is not linear in book here as it was for BELL.

```
arm live, seeds [1, 2, 3, 4], NaN hits 0, deaths [[], [], [], []]

== TERN (Airlines) ==
open rev bn                             44.58          44.58          44.58          44.58   |   44.58
open om                                  9.0%           9.0%           9.0%           9.0%   |    9.0%
open P/E                                10.81          10.81          10.81          10.81   |   10.81
open P/B                                 1.18           1.18           1.18           1.18   |    1.18
open cap bn                             28.00          28.12          28.45          28.58   |   28.29
rev CAGR                                 3.3%           3.6%           3.8%           3.1%   |    3.5%
om mean                                 10.0%          10.0%          11.7%           8.3%   |   10.0%
om min                                  -6.7%          -9.2%          -2.6%          -6.9%   |   -6.4%
om max                                  22.4%          25.5%          24.9%          23.3%   |   24.0%
neg EBIT q share                         6.2%          13.8%           2.5%          15.0%   |    9.4%
EPS g SD                               583.8%         135.4%         102.8%         258.6%   |  270.2%
neg annual EPS years                     1.00           3.00           1.00           3.00   |    2.00
vol                                     40.7%          52.1%          34.2%          62.1%   |   47.3%
beta wk                                  1.81           1.36           1.35           1.56   |    1.52
maxDD                                  -92.7%         -96.6%         -75.6%         -96.7%   |  -90.4%
div cuts                                 3.00           3.00           5.00           5.00   |    4.00
distress q                               0.00           2.00           0.00           2.00   |    1.00
worst rating                                B              D             BB              D   | 
dead/default/reorg                      0/0/0          0/7/1          0/0/0          0/7/1   | 
rescue/distress lines                    0.00           0.00           0.00           0.00   |    0.00
treasury distress lines                 24.00          39.00          14.00          30.00   |   26.75
P/E settle (y3-20 med)                   9.17           8.30          10.03           6.13   |    8.41
P/E open - settle                        1.65           2.52           0.78           4.68   |    2.41
EPS beat rate                           76.2%          73.8%          78.8%          65.0%   |   73.4%
share open l/b/c/m             0.46/0.31/0.09/0.14 0.45/0.33/0.10/0.12 0.43/0.32/0.11/0.13 0.41/0.35/0.12/0.13   | 
share end4q l/b/c/m            0.48/0.29/0.10/0.13 0.41/0.35/0.10/0.13 0.44/0.31/0.11/0.14 0.42/0.35/0.10/0.13   | 
zero stream quarters                     0.00           0.00           0.00           0.00   |    0.00
seat_capacity_index p5/p95        0.962/1.044    0.963/1.043    0.947/1.029    0.983/1.075   | 
seat_capacity_index min/max       0.877/1.052    0.948/1.050    0.867/1.054    0.872/1.095   | 
load_factor p5/p95                0.804/0.865    0.800/0.884    0.815/0.887    0.799/0.861   | 
load_factor min/max               0.786/0.880    0.775/0.894    0.802/0.895    0.791/0.866   | 
strikes / 20y                            1.00           0.00           1.00           3.00   |    1.25
corr(pax yoy, 4q d gapEma)               0.22           0.51           0.34           0.51   |    0.39
corr(d4 om, d4 energy push)              0.10          -0.42           0.14           0.01   |   -0.04
energy push p5/p95              -0.009/+0.006  -0.009/+0.017  -0.011/+0.006  -0.010/+0.008   | 
slump quarters pooled 38: rev yoy - mean -3.29pp, EBIT yoy - mean -84.97pp

== CANV (Integrated Freight & Logistics) ==
open rev bn                             88.72          88.72          88.72          88.72   |   88.72
open om                                 11.0%          11.0%          11.0%          11.0%   |   11.0%
open P/E                                16.08          16.08          16.08          16.08   |   16.08
open P/B                                 2.75           2.75           2.75           2.75   |    2.75
open cap bn                            110.10         109.99         110.72         110.51   |  110.33
rev CAGR                                 4.6%           5.7%           4.5%           6.5%   |    5.3%
om mean                                 10.8%          11.3%           9.6%          11.7%   |   10.8%
om min                                  -4.8%          -4.6%          -5.7%          -2.7%   |   -4.4%
om max                                  19.6%          22.1%          22.9%          20.2%   |   21.2%
neg EBIT q share                         2.5%           5.0%           8.8%           2.5%   |    4.7%
EPS g SD                               354.1%         145.2%         803.4%          88.2%   |  347.7%
neg annual EPS years                     0.00           1.00           1.00           0.00   |    0.50
vol                                     21.5%          20.5%          21.5%          22.7%   |   21.6%
beta wk                                  1.12           1.04           1.08           1.08   |    1.08
maxDD                                  -53.4%         -47.5%         -48.5%         -47.9%   |  -49.3%
div cuts                                 8.00           6.00           5.00           3.00   |    5.50
distress q                               0.00           0.00           0.00           0.00   |    0.00
worst rating                              BBB             BB            BBB             BB   | 
dead/default/reorg                      0/0/0          0/0/0          0/0/0          0/0/0   | 
rescue/distress lines                    0.00           0.00           0.00           0.00   |    0.00
treasury distress lines                  4.00           6.00          12.00           6.00   |    7.00
P/E settle (y3-20 med)                  16.92          21.25          15.89          14.31   |   17.09
P/E open - settle                       -0.84          -5.17           0.19           1.77   |   -1.01
EPS beat rate                           78.8%          76.2%          75.0%          71.2%   |   75.3%
slump quarters pooled 40: rev yoy - mean -7.49pp, EBIT yoy - mean -36.24pp

TERN largest 4q energy-push rise per seed: report t at the end of the rise, rise, margin % from -4q to +4q
  s1: t 18.14, rise +0.008, om % [1.3, -6.7, 1.6, -3.5, 2.3, 13.0, 7.2, 9.8, 7.7]
  s2: t 5.89, rise +0.019, om % [6.9, 9.0, 4.8, 8.5, 11.1, -0.9, -3.4, -5.7, -2.1]
  s3: t 14.39, rise +0.011, om % [8.9, 16.4, 14.4, 18.1, 15.7, 17.2, 19.3, 14.5, 11.6]
  s4: t 1.14, rise +0.010, om % [8.1, -2.9, 5.7, 0.9, 6.2, -1.5, 8.3, 2.0, 2.5]
```

s1 mean price rev/rev -0.003 corr(pr, gap) +0.21 corr(lf, gap) -0.05 corr(seat, gap) +0.80 | gap>1%: n 21 lf 0.832 om 0.112 pr +0.008 | gap<-1%: n 28 lf 0.837 om 0.082 pr -0.016 | all om 0.100
s2 mean price rev/rev -0.000 corr(pr, gap) +0.27 corr(lf, gap) +0.26 corr(seat, gap) +0.87 | gap>1%: n 24 lf 0.847 om 0.123 pr +0.024 | gap<-1%: n 12 lf 0.827 om 0.051 pr -0.036 | all om 0.100
s3 mean price rev/rev +0.021 corr(pr, gap) +0.15 corr(lf, gap) -0.14 corr(seat, gap) +0.71 | gap>1%: n 7 lf 0.845 om 0.133 pr +0.043 | gap<-1%: n 30 lf 0.847 om 0.107 pr +0.006 | all om 0.117
s4 mean price rev/rev -0.037 corr(pr, gap) +0.32 corr(lf, gap) +0.07 corr(seat, gap) +0.58 | gap>1%: n 42 lf 0.834 om 0.112 pr -0.012 | gap<-1%: n 1 lf 0.828 om 0.056 pr -0.095 | all om 0.083

Findings: two of four seeds reorganise (s2 year 8.7, s4 year 4.2; 7 payment-default quarters each, rating D, maxDD -97%). s4 fails in a boom: gap EMA +2%, seats 1.04-1.05 (scheduled off the output gap at sensitivity 2.42), load factor 0.80, price revenue -5 to -12% of revenue, margin 1-5%, price 31 -> 2.3 with book equity still 21B. Seats follow the output gap while leisure demand follows the domestic demand gap (x0.8) and visitors the foreign gap, so an export-led boom fills fewer seats than it schedules; at fare elasticity 1.5 with every load and fare dollar booked as price, a 3% demand/seat shortfall is ~7.5% of revenue against a 9% margin. Fuel barely registers: energy push lag p5/p95 about -1%/+1%, corr(4q margin change, 4q energy change) -0.04. Annual EPS growth SD is meaningless here (near-zero years); use negative-EPS years instead. Load factor 0.78-0.90, seats 0.87-1.10, no NaN, no zero stream.

## 2026-10-25 (b): arm `fix` (seats chase passenger demand, tau 0.5y, one load/fare; TERN seed x1.8 incl. sam 0.18)

Run: `rm -rf var/harness/cache/test; bin/harness-batch var/harness/airline/run.sh 1-4 fix && python3 var/harness/airline/analyse.py fix && python3 var/harness/airline/compare.py live fix && python3 var/harness/airline/slump.py live fix` (tables `fix_4seed_20y.out`, `compare_live_fix.out`, `slump_live_fix.out`, `diag_fix.out`). Seeds 1-4, 20y, TPY 360; arms do not pair. Fuel now on the relative energy price (energy_cost_push_lag / 0.025); a correlation does not change under that rescaling, so the new rows are the level correlation and the slope.

Opening probe (seed 1): revenue 80.2B, OM 9.0%, P/E 10.78, P/B 1.18, cap 50.4B.

```
TERN, seeds 1-4                                     live                       fix
open rev bn                                        44.58                     80.24
open om                                             9.0%                      9.0%
open P/E                                           10.81                     10.78
open P/B                                            1.18                      1.18
open cap bn                                        28.29                     50.92
rev CAGR                                            3.5%                      4.4%
om mean                                            10.0%                     10.2%
om min                                             -6.4%                     -3.4%
om max                                             24.0%                     22.1%
neg EBIT q share                                    9.4%                      5.3%
neg annual EPS years                                2.00                      0.50
vol                                                47.3%                     36.7%
beta wk                                             1.52                      1.50
maxDD                                             -90.4%                    -71.4%
div cuts                                            4.00                      2.00
distress q                                          1.00                      0.00
worst rating                                    B D BB D               BB BB B CCC
dead/default/reorg               0/0/0 0/7/1 0/0/0 0/7/1   0/0/0 0/0/0 0/0/0 0/0/0
rescue/distress lines                               0.00                      0.00
treasury distress lines                            26.75                     16.00
P/E settle (y3-20 med)                              8.41                     10.10
P/E open - settle                                   2.41                      0.69
EPS beat rate                                      73.4%                     71.2%
share open l/b/c/m                 0.46/0.31/0.09/0.14..     0.47/0.31/0.09/0.14..
share end4q l/b/c/m                0.48/0.29/0.10/0.13..     0.44/0.33/0.09/0.13..
zero stream quarters                                0.00                      0.00
seat_capacity_index p5/p95                 0.962/1.044..             0.980/1.031..
seat_capacity_index min/max                0.877/1.052..             0.961/1.039..
load_factor p5/p95                         0.804/0.865..             0.815/0.868..
load_factor min/max                        0.786/0.880..             0.802/0.874..
strikes / 20y                                       1.25                      1.00
corr(pax yoy, 4q d gapEma)                          0.39                      0.26
corr(d4 om, d4 energy push)                        -0.04                     -0.18
rel energy price p5/p95                  -0.364/+0.238..           -0.232/+0.703..
corr(om, rel energy price)                         -0.25                     -0.21
om pp per +10% energy                              -0.72                     -0.50
slump pooled live: n 38, rev yoy - mean -3.29pp, EBIT yoy - mean -84.97pp
slump pooled fix: n 17, rev yoy - mean +0.58pp, EBIT yoy - mean +327.56pp
live: slump n 38, rev yoy - mean -3.29pp, yoy margin change - mean +0.26pp, EBIT yoy change / mean|EBIT| - mean +5.8%
fix: slump n 17, rev yoy - mean +0.58pp, yoy margin change - mean +6.10pp, EBIT yoy change / mean|EBIT| - mean +54.0%
```

No reorganisation, default or distress quarter (live: 2 reorganisations). Seed 4 still reaches CCC (maxDD -96%) at the turn of a boom: gap EMA 4.2% -> 0, seats 1.07 lagging demand down, load 0.80, price revenue -10 to -13% of revenue for four quarters, margins -9% to 0; price 169 -> 12, then margin 18% in the trough as seats catch down. The lag makes margins lead the gap: slump-quarter margins are 6.1pp ABOVE the mean change (17 quarters, 3 seeds; raw EBIT yoy +328pp is a near-zero-base artefact). Fuel: relative energy price p5/p95 -0.23/+0.70; margin -0.50pp per +10% energy (live -0.72), corr -0.21.
