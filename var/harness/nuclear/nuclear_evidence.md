# Reactor vendor (FIRE) evidence

## 2026-10-25: FIRE behaviour check, live macro (arm `live`, seeds 1-4, 20y, TPY 360, no Diet), GRIP and DIPR beside it

Working tree (NuclearVendorBusinessModel uncommitted). Opening probe seed 1 as seeded: revenue 51.9B, OM 13%, EPS 5.08, P/E 21.02, P/B 3.56, cap 107.6B. x0.74 incl. sam (cash 3.7B, equity 22.2B, debt 11.1B, retained 8.88B, sam 0.111; `make_ovr.sh`, ovr `scale74`): revenue 38.4B, EPS 3.76, P/E 21.01, cap 79.6B; GRIP 209.5B and DIPR 34.3B unchanged. The batch ran at scale74:
`OVR=<abs>/var/harness/nuclear/ovr/scale74 bin/harness-batch var/harness/nuclear/run.sh 1-4 live && python3 var/harness/nuclear/analyse.py live` (table `live_4seed_20y.out`, driver means by seed `drivers_by_seed.out`).

```
arm live, seeds [1, 2, 3, 4], NaN hits 0, deaths [[], [], [], []]

== FIRE (Nuclear Power Equipment) ==
open rev bn                             38.41          38.41          38.41          38.41   |   38.41
open om                                 13.0%          13.0%          13.0%          13.0%   |   13.0%
open P/E                                21.01          21.01          21.01          21.01   |   21.01
open P/B                                 3.56           3.56           3.56           3.56   |    3.56
open cap bn                             79.63          77.57          77.97          77.92   |   78.27
rev CAGR                                 5.0%           3.6%           3.7%           4.6%   |    4.2%
om mean                                 13.8%          12.7%          12.5%          12.1%   |   12.8%
om min                                   6.7%           5.8%           5.9%           3.8%   |    5.6%
om max                                  19.3%          18.3%          17.7%          19.5%   |   18.7%
neg EBIT q share                         0.0%           0.0%           0.0%           0.0%   |    0.0%
EPS g SD                                29.1%          28.0%          23.5%          43.9%   |   31.1%
neg annual EPS years                     0.00           0.00           0.00           0.00   |    0.00
vol                                     19.3%          20.2%          18.6%          20.7%   |   19.7%
beta wk                                  0.86           0.82           0.77           0.83   |    0.82
maxDD                                  -49.3%         -65.1%         -37.8%         -58.9%   |  -52.8%
div cuts                                 0.00           0.00           1.00           2.00   |    0.75
distress q                               0.00           0.00           0.00           0.00   |    0.00
worst rating                              BBB            BBB            BBB            BBB   | 
dead/default/reorg                      0/0/0          0/0/0          0/0/0          0/0/0   | 
treasury distress lines                  5.00           1.00           2.00           4.00   |    3.00
P/E settle (y3-20 med)                  18.21          20.10          21.51          16.86   |   19.17
P/E open - settle                        2.80           0.91          -0.50           4.16   |    1.84
EPS beat rate                           76.2%          76.2%          78.8%          63.7%   |   73.8%
P/E last 5y mean                        19.02          15.99          30.02          18.08   |   20.78
share fuel                               0.37           0.40           0.41           0.41   |    0.40
share plant_services                     0.31           0.38           0.38           0.39   |    0.36
share new_build                          0.32           0.23           0.21           0.20   |    0.24
new-build rev CV                         0.39           0.24           0.25           0.45   |    0.33
new-build share min/max           0.160/0.397    0.166/0.292    0.138/0.303    0.086/0.283   | 
backlog q mean                           6.78           4.17           3.86           3.58   |    4.60
backlog q min/max                   2.70/9.47      2.87/5.88      2.19/6.26      1.35/5.72   | 
orders / yr                              1.55           0.80           0.70           0.75   |    0.95
book-to-bill mean                        1.04           0.82           0.76           0.88   |    0.88
accidents (regime onsets)                0.00           1.00           1.00           2.00   |    1.00
accident lore events                     0.00           1.00           1.00           2.00   |    1.00
accident quarter share                   0.00           0.60           0.47           0.71   |    0.45
overruns (regime onsets)                 1.00           2.00           1.00           2.00   |    1.50
overrun lore events                      1.00           2.00           1.00           2.00   |    1.50
overrun quarter share                    0.21           0.05           0.12           0.28   |    0.17
om overrun - not pp                     -6.19          -6.23          -5.61          -5.48   |   -5.88
corr(p, energy term)                     0.55           0.86           0.82           0.85   |    0.77
corr(p, financing term)                  0.53          -0.21           0.23           0.52   |    0.27
carbon price max                         0.00           0.00           0.00           0.00   |    0.00
corr(orders, p)                          0.42           0.11           0.20           0.25   |    0.24
slump quarters pooled 56: rev yoy - mean +0.76pp, EBIT yoy - mean -2.49pp, yoy margin change - mean -0.20pp

== GRIP (Aerospace & Defense) ==
open rev bn                             85.59          85.59          85.59          85.59   |   85.59
open om                                 14.0%          14.0%          14.0%          14.0%   |   14.0%
open P/E                                23.60          23.61          23.59          23.61   |   23.60
open P/B                                 6.00           6.00           6.00           6.00   |    6.00
open cap bn                            209.50         206.90         208.34         207.75   |  208.12
rev CAGR                                 4.4%           4.6%           5.2%           5.3%   |    4.9%
om mean                                 14.3%          13.0%          14.8%          15.3%   |   14.4%
om min                                   6.5%          -3.4%           1.1%          -1.3%   |    0.7%
om max                                  20.1%          21.8%          22.9%          24.1%   |   22.2%
neg EBIT q share                         0.0%           5.0%           0.0%           2.5%   |    1.9%
EPS g SD                                35.7%         145.9%          48.4%         498.3%   |  182.1%
neg annual EPS years                     0.00           2.00           0.00           1.00   |    0.75
vol                                     16.2%          16.1%          14.8%          15.7%   |   15.7%
beta wk                                  0.78           0.80           0.78           0.71   |    0.77
maxDD                                  -35.1%         -56.2%         -31.6%         -47.5%   |  -42.6%
div cuts                                 1.00           3.00           1.00           2.00   |    1.75
distress q                               0.00           0.00           0.00           0.00   |    0.00
worst rating                              BBB             BB            BBB            BBB   | 
dead/default/reorg                      0/0/0          0/0/0          0/0/0          0/0/0   | 
treasury distress lines                 16.00          25.00          20.00          20.00   |   20.25
P/E settle (y3-20 med)                  21.77          16.77          20.28          17.33   |   19.04
P/E open - settle                        1.83           6.84           3.32           6.29   |    4.57
EPS beat rate                           80.0%          76.2%          71.2%          70.0%   |   74.4%
P/E last 5y mean                        25.58          10.56          18.13          16.76   |   17.76
slump quarters pooled 58: rev yoy - mean +0.26pp, EBIT yoy - mean -20.79pp, yoy margin change - mean +0.47pp

== DIPR (Utilities - Independent Power Producers) ==
open rev bn                             10.15          10.15          10.15          10.15   |   10.15
open om                                 28.0%          28.0%          28.0%          28.0%   |   28.0%
open P/E                                17.10          17.10          17.10          17.10   |   17.10
open P/B                                 1.53           1.53           1.53           1.53   |    1.53
open cap bn                             34.29          33.12          33.91          33.73   |   33.76
rev CAGR                                 5.2%           3.3%           4.3%           1.4%   |    3.6%
om mean                                 25.1%          28.8%          24.1%          24.7%   |   25.7%
om min                                   4.0%         -11.6%          -0.4%         -16.7%   |   -6.1%
om max                                  47.3%          51.2%          59.5%          51.6%   |   52.4%
neg EBIT q share                         0.0%           1.2%           2.5%           5.0%   |    2.2%
EPS g SD                                58.0%         312.3%        2433.3%         110.9%   |  728.6%
neg annual EPS years                     0.00           0.00           1.00           1.00   |    0.50
vol                                     34.3%          29.7%          31.7%          30.3%   |   31.5%
beta wk                                  0.43           0.43           0.41           0.40   |    0.42
maxDD                                  -88.7%         -54.4%         -78.6%         -64.9%   |  -71.6%
div cuts                                 1.00           2.00           3.00           4.00   |    2.50
distress q                               0.00           0.00           0.00           0.00   |    0.00
worst rating                                B            BBB             BB             BB   | 
dead/default/reorg                      0/0/0          0/0/0          0/0/0          0/0/0   | 
treasury distress lines                  0.00           0.00           0.00           0.00   |    0.00
P/E settle (y3-20 med)                  18.05          17.60          17.57          16.19   |   17.35
P/E open - settle                       -0.96          -0.50          -0.47           0.91   |   -0.25
EPS beat rate                           72.5%          75.0%          72.5%          73.8%   |   73.4%
P/E last 5y mean                        19.26          26.18          10.91          29.69   |   21.51
slump quarters pooled 58: rev yoy - mean -5.59pp, EBIT yoy - mean -199.17pp, yoy margin change - mean -1.55pp

FIRE accident onsets (8q before vs the spell, capped at 8q):
  seed 2, t +5.869, dur_q 48, orders/yr pre +1.500, orders/yr during +0.500, fuel vs pre -0.029, plant_services vs pre +0.173, new_build vs pre +0.039, om pre +0.130, om during +0.104, excess ret 1q +0.095, excess ret 4q +0.107
  seed 3, t +10.619, dur_q 38, orders/yr pre +1.500, orders/yr during +0.500, fuel vs pre -0.052, plant_services vs pre +0.136, new_build vs pre -0.016, om pre +0.147, om during +0.140, excess ret 1q -0.038, excess ret 4q +0.203
  seed 4, t +14.369, dur_q 15, orders/yr pre +1.000, orders/yr during +1.000, fuel vs pre -0.005, plant_services vs pre +0.184, new_build vs pre +0.048, om pre +0.164, om during +0.144, excess ret 1q +0.104, excess ret 4q +0.168

FIRE order-probability terms, all quarters pooled (mean, SD):
  energy     mean +0.1425 sd 0.2894
  financing  mean -0.0126 sd 0.1934
  carbon     mean +0.0000 sd 0.0000
  fx         mean +0.0305 sd 0.0412
  p          mean +0.2901 sd 0.0747

1 energy mean 0.054 sd 0.192 | financing mean 0.055 sd 0.210
2 energy mean 0.252 sd 0.353 | financing mean -0.013 sd 0.195
3 energy mean 0.095 sd 0.309 | financing mean -0.046 sd 0.187
4 energy mean 0.169 sd 0.234 | financing mean -0.046 sd 0.161
```

Findings. Sound: no distress, margin 3.8-19.5% and never negative, backlog 4.6 firm-quarters (steady 19 nb quarters = 4.75), orders 0.95/yr, overrun share 17% (stationary 19%) costing 5.9pp of margin while active (mechanical ~6.25pp x 0.8). Off: (1) accidents last long: stationary share 20% with a 20-quarter mean spell; observed spells 48, 38, 15 quarters, 45% of quarters in accident across seeds; (2) an accident is close to revenue-neutral (fuel -0.5 to -5%, services +14 to +18%, new build flat vs the 8 quarters before, margin -1 to -3pp) and the stock beats the board after it (+11 to +20% over 4q, 3 events); (3) the energy term has mean +0.14 (seed means +0.05 to +0.25), not ~0, SD 0.29, and drives the order probability (corr 0.77); financing mean -0.013 (seed means -0.05 to +0.06), SD 0.19, corr 0.27; carbon price 0 throughout (no Diet).

## 2026-10-25 (b): arm `fix` (accident exit .10, fuel cut .15, retrofit .04, 20% backlog cancelled at onset; energy term log(EMA / decade trend); x0.74 seed in InitialMarket)

Run: `rm -rf var/harness/cache/test; bin/harness-batch var/harness/nuclear/run.sh 1-8 fix && python3 var/harness/nuclear/analyse.py fix && python3 var/harness/nuclear/events.py fix` (tables `fix_8seed_20y.out`, `events_fix.out`; probe `open_fix.out`). Harness `drivers.energy` now recomputes the log term. Seeds 1-8, 20y, TPY 360, live macro, no Diet. Opening probe: revenue 38.4B, EPS 3.76, P/E 21.01, cap 79.6B.

FIRE means of 8 seeds: cap 78.9B; revenue CAGR 4.4%; margin 13.7% (min 5.3, max 19.3), no loss quarter or negative-EPS year; annual EPS growth SD 33%; vol 20.0%, beta 0.82, maxDD -50%; dividend cuts 0.75; worst rating BBB in every seed; no distress, default or reorganisation; beat rate 77.5%; settled P/E (y3-20 median) 16.9, last 5y mean 19.7: opens 4.1 points high. Streams 0.39/0.34/0.27; new-build CV 0.30. Overrun active 16% (stationary 19%), margin -6.9pp while active.

```
arm fix, 8 seeds; accident onsets per run [0, 0, 1, 2, 0, 0, 0, 2] (mean 0.62); accident spells (q) [3, 4, 6, 8, 19]; overrun spells (q) [1, 1, 2, 2, 3, 6, 6, 7, 8, 8, 9, 9, 11, 13, 18]

FIRE accident event study       accident mean (se) n        placebo mean (se) n
fuel h0                            -0.138 (0.019) 4         +0.001 (0.002) 448
plant_services h0                  +0.068 (0.030) 4         +0.001 (0.002) 448
new_build h0                       -0.200 (0.049) 4         +0.008 (0.006) 448
rev h0                             -0.086 (0.028) 4         +0.002 (0.003) 448
om pp h0                           -3.519 (2.179) 4         +0.053 (0.124) 448
fuel h4                            -0.045 (0.063) 4         +0.003 (0.002) 448
plant_services h4                  +0.049 (0.020) 4         +0.002 (0.002) 448
new_build h4                       -0.320 (0.041) 4         +0.025 (0.010) 448
rev h4                             -0.088 (0.032) 4         +0.005 (0.003) 448
om pp h4                           -2.467 (2.108) 4         +0.145 (0.179) 448
xret +1q                           +0.010 (0.015) 4         +0.008 (0.004) 448
xret +2q                           +0.023 (0.084) 4         +0.016 (0.005) 448
xret +4q                           +0.032 (0.050) 4         +0.032 (0.007) 448
xret +8q                           +0.130 (0.134) 4         +0.057 (0.011) 448
orders/yr before                   +1.125 (0.125) 4                          -
orders/yr during                   +0.458 (0.315) 4                          -

seed  energy mean/sd  financing mean/sd  p mean  orders/yr  b2b mean  backlog q  accident share  overrun share
   1  -0.009/0.246    +0.042/0.209      0.259   1.15       0.96      5.58       0.00            0.01
   2  -0.006/0.153    -0.033/0.209      0.244   1.10       0.91      5.57       0.00            0.33
   3  -0.022/0.398    -0.064/0.161      0.237   0.85       0.90      4.18       0.24            0.28
   4  -0.024/0.258    -0.069/0.140      0.227   0.85       0.93      4.11       0.12            0.17
   5  +0.019/0.165    -0.020/0.130      0.260   1.30       0.95      6.40       0.00            0.25
   6  -0.065/0.333    +0.029/0.236      0.246   1.20       0.85      6.32       0.00            0.16
   7  -0.052/0.310    -0.092/0.094      0.212   1.00       0.79      5.47       0.00            0.00
   8  +0.019/0.215    +0.137/0.256      0.294   1.25       1.07      5.43       0.14            0.10
mean  -0.017/0.260    -0.009/0.179      0.247   1.09       0.92      5.38       0.06            0.16
```

Accidents now cost revenue: -8.6% at onset and 4q later (new build -20%/-32%, fuel -14%/-5%, services +7%/+5%), margin -3.5pp/-2.5pp (se 2.2), orders 1.1 -> 0.5/yr. The price does not react: excess return over the board +1%/+2%/+3%/+13% at +1/2/4/8q against the placebo +1%/+2%/+3%/+6% (n 4; placebo windows overlap, so its se is understated). Accident onsets 0.62/run against ~0.89 expected (5 vs 7.1, inside Poisson noise), active 6% (stationary 11%), spells 3-19 quarters. Energy term centred (mean -0.017, SD 0.26), financing -0.009 / 0.18; order probability 0.247, orders 1.09/yr, book-to-bill 0.92, backlog 5.4 firm-quarters.
