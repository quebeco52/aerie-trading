# Consulting evidence

## 2026-10-25 GDWT/TSTN behaviour check (4 seeds x 20y, TPY 360, live macro, no Diet)

Question: do the new ConsultingBusinessModel firms behave sensibly beside CLAW and LYRE. Harness ConsultingHarnessTest.php via run.sh live 1-4; table live_4seed_20y.out (src uncommitted working tree on main, HEAD 5aab55b6).

```
arm live, seeds [1, 2, 3, 4], NaN hits 0, deaths [[], [], [], []]

== GDWT (Consulting Services) ==
open rev bn                           23.13        23.13        23.13        23.13   |   23.13
open om                               15.0%        15.0%        15.0%        15.0%   |   15.0%
open P/E                              21.99        21.99        21.99        21.99   |   21.99
open P/B                               5.15         5.15         5.15         5.15   |    5.15
rev CAGR                               6.1%         5.3%         5.1%         6.0%   |    5.6%
om mean                               15.7%        14.7%        14.9%        16.3%   |   15.4%
om min                                 7.1%         8.2%        10.2%        11.7%   |    9.3%
om max                                21.2%        21.6%        19.3%        20.0%   |   20.5%
EPS g SD                              18.4%        16.2%        20.1%        10.1%   |   16.2%
vol                                   19.7%        19.5%        21.2%        22.2%   |   20.6%
beta wk                                0.50         0.50         0.50         0.65   |    0.54
maxDD                                -38.0%       -44.5%       -56.0%       -39.1%   |  -44.4%
div cuts                               0.00         0.00         0.00         0.00   |    0.00
distress q                             0.00         0.00         0.00         0.00   |    0.00
worst rating                              A            A            A            A   | 
dead                                   0.00         0.00         0.00         0.00   |    0.00
sector rev share 0->end        0.180->0.130 0.204->0.143 0.191->0.140 0.189->0.157   | 
b2b p5                                 0.89         0.92         0.91         0.93   |    0.91
b2b p95                                1.09         1.07         1.08         1.08   |    1.08
backlog q min                          3.16         3.28         3.30         3.17   |    3.23
backlog q max                          3.99         3.67         3.77         3.86   |    3.82
stra backlog q min/max            0.55/0.80    0.58/0.77    0.58/0.76    0.56/0.74   | 
tech backlog q min/max            5.02/6.44    5.30/5.97    5.26/6.04    5.21/6.13   | 
risk backlog q min/max            1.40/2.25    1.43/2.12    1.58/2.09    1.42/2.34   | 
corr(strat b2b, gap)                   0.21         0.16        -0.02         0.07   |    0.10
corr(strat bookings yoy, gap)          0.40         0.27         0.13         0.50   |    0.33
corr(risk b2b, spread)                 0.45         0.16         0.49         0.27   |    0.34
corr(firm b2b, gap)                    0.02         0.16         0.08        -0.10   |    0.04
corr(firm b2b, spread)                 0.15         0.01         0.15         0.09   |    0.10
stress spread pp                       0.56         0.32         0.66         0.50   |    0.51
stress rev yoy - mean                  4.3%         0.8%         5.7%         2.4%   |    3.3%
stress NI yoy - mean                   2.0%         1.9%        12.7%        13.4%   |    7.5%
slump gap                             -1.9%        -1.1%        -1.4%        -3.2%   |   -1.9%
slump rev yoy - mean                  -2.1%        -1.2%         3.5%         0.8%   |    0.3%
worst b2b                              0.86         0.90         0.87         0.85   |    0.87
d om pp +2q                           -2.09         0.06         2.62        -2.29   |   -0.43
d rev % +2q                            3.64         1.43         4.59        -4.64   |    1.25
d om pp +4q                           -1.26         1.22         1.90         3.34   |    1.30
d rev % +4q                            5.74         7.96         5.74         9.32   |    7.19

== TSTN (Consulting Services) ==
open rev bn                            5.34         5.34         5.34         5.34   |    5.34
open om                               14.0%        14.0%        14.0%        14.0%   |   14.0%
open P/E                              26.62        26.62        26.62        26.62   |   26.62
open P/B                               5.32         5.32         5.32         5.32   |    5.32
rev CAGR                               4.1%         4.4%         3.0%         2.8%   |    3.6%
om mean                               14.2%        14.3%        13.7%        13.7%   |   14.0%
om min                                 9.4%         8.3%         7.4%         4.1%   |    7.3%
om max                                18.9%        19.2%        19.1%        19.3%   |   19.1%
EPS g SD                              20.5%        18.8%        25.3%        30.2%   |   23.7%
vol                                   23.7%        24.2%        23.6%        27.0%   |   24.6%
beta wk                                0.30         0.35         0.34         0.46   |    0.36
maxDD                                -51.4%       -48.6%       -52.7%       -64.1%   |  -54.2%
div cuts                               0.00         0.00         0.00         0.00   |    0.00
distress q                             0.00         0.00         0.00         0.00   |    0.00
worst rating                              A            A            A            A   | 
dead                                   0.00         0.00         0.00         0.00   |    0.00
sector rev share 0->end        0.017->0.015 0.017->0.014 0.018->0.013 0.018->0.013   | 
b2b p5                                 0.93         0.91         0.92         0.91   |    0.92
b2b p95                                1.08         1.08         1.08         1.08   |    1.08
backlog q min                          1.57         1.65         1.60         1.59   |    1.60
backlog q max                          2.04         2.16         2.09         2.27   |    2.14
stra backlog q min/max            0.53/0.79    0.61/0.80    0.54/0.74    0.58/0.78   | 
tech backlog q min/max            5.11/6.16    5.36/6.21    4.94/6.29    4.66/6.08   | 
risk backlog q min/max            1.42/2.08    1.52/2.25    1.50/2.16    1.42/2.38   | 
corr(strat b2b, gap)                   0.10         0.06         0.06         0.11   |    0.08
corr(strat bookings yoy, gap)          0.47         0.19         0.18         0.45   |    0.32
corr(risk b2b, spread)                 0.42         0.24         0.27         0.28   |    0.30
corr(firm b2b, gap)                   -0.17        -0.39        -0.03        -0.17   |   -0.19
corr(firm b2b, spread)                 0.25         0.16         0.12         0.23   |    0.19
stress spread pp                       0.56         0.32         0.65         0.50   |    0.51
stress rev yoy - mean                  4.8%         1.6%         0.6%         6.4%   |    3.4%
stress NI yoy - mean                   8.9%         1.4%        -5.1%        15.9%   |    5.3%
slump gap                             -1.9%        -1.1%        -1.4%        -3.1%   |   -1.9%
slump rev yoy - mean                   0.7%         6.7%         6.1%        10.9%   |    6.1%
worst b2b                              0.89         0.88         0.89         0.86   |    0.88
d om pp +2q                            1.21         2.55        -1.14         3.53   |    1.54
d rev % +2q                            0.40        -2.15         0.14         2.89   |    0.32
d om pp +4q                           -1.51        -0.44         1.14         0.69   |   -0.03
d rev % +4q                           -3.11        -5.76        -1.79         1.31   |   -2.34

== CLAW (Legal Services) ==
open rev bn                            2.26         2.26         2.26         2.26   |    2.26
open om                               30.0%        30.0%        30.0%        30.0%   |   30.0%
open P/E                              23.15        23.15        23.15        23.15   |   23.15
open P/B                               5.66         5.66         5.66         5.66   |    5.66
rev CAGR                               3.9%         1.8%         2.2%         3.0%   |    2.7%
om mean                               28.8%        28.6%        28.7%        29.4%   |   28.8%
om min                                18.8%        13.7%        17.3%        11.0%   |   15.2%
om max                                34.5%        34.6%        35.8%        37.9%   |   35.7%
EPS g SD                              31.0%        16.2%        20.0%        28.5%   |   23.9%
vol                                   18.9%        16.6%        18.9%        19.3%   |   18.4%
beta wk                                0.40         0.38         0.35         0.44   |    0.39
maxDD                                -33.8%       -35.9%       -57.1%       -49.9%   |  -44.2%
div cuts                               1.00         0.00         1.00         2.00   |    1.00
distress q                             0.00         0.00         0.00         0.00   |    0.00
worst rating                              A            A            A            A   | 
dead                                   0.00         0.00         0.00         0.00   |    0.00
sector rev share 0->end        0.007->0.006 0.009->0.004 0.010->0.005 0.010->0.006   | 

== LYRE (Advertising Agencies) ==
open rev bn                           18.83        18.83        18.83        18.83   |   18.83
open om                               24.0%        24.0%        24.0%        24.0%   |   24.0%
open P/E                              21.59        21.59        21.59        21.59   |   21.59
open P/B                               7.15         7.15         7.15         7.15   |    7.15
rev CAGR                               4.0%         5.7%         3.7%         4.3%   |    4.4%
om mean                               24.5%        23.9%        24.4%        24.8%   |   24.4%
om min                                21.0%        19.2%        19.7%        20.9%   |   20.2%
om max                                27.8%        29.0%        28.5%        28.6%   |   28.5%
EPS g SD                               9.4%        12.1%        12.1%         8.2%   |   10.4%
vol                                   23.7%        20.7%        19.9%        22.4%   |   21.7%
beta wk                                0.42         0.46         0.39         0.55   |    0.46
maxDD                                -48.2%       -39.3%       -52.3%       -47.2%   |  -46.7%
div cuts                               0.00         0.00         0.00         0.00   |    0.00
distress q                             0.00         0.00         0.00         0.00   |    0.00
worst rating                              A            A            A            A   | 
dead                                   0.00         0.00         0.00         0.00   |    0.00
sector rev share 0->end        1.000->1.000 1.000->1.000 1.000->1.000 1.000->1.000   | 
```

Stress quarters (top decile spread EMA over trend): max spread over trend 0.39-0.96pp, no credit crunch; seed 4 the only real one (gap EMA -3.0%, defaults +74% over trend): TSTN risk stream +17.8% y/y, GDWT strategy +2.0% vs 6.1% mean. Seeds 1-2 stress quarters have a positive gap EMA. No deep recession in 4 seeds (min gap EMA -4.1%). Caveats: price drawdown excludes dividends; beta on weekly log returns against a cap-weighted board index built in the harness.

## 2026-10-25 after: STRATEGY_GAP_SENSITIVITY 3.0, TECHNOLOGY_GAP_SENSITIVITY 2.0 (4 seeds x 20y, TPY 360)

Arm after vs live (before). Tables after_4seed_20y.out, slopes_live_after.out (slopes.py: revenue y/y less firm-seed mean on raw output gap, pooled, Newey-West 4 lags). The live macro diverges between arms (board feedback), so seed paths are not paired.

```
live   GDWT: slope on gap now  0.31 (0.25), gap 4q back  0.36 (0.28) | worst-gap year: gap -2.0% rev -0.7pp; gap -1.5% rev +0.5pp; gap -1.5% rev -2.3pp; gap -3.2% rev -3.7pp
live   TSTN: slope on gap now -1.03 (0.46), gap 4q back -0.45 (0.43)
after  GDWT: slope on gap now  1.19 (0.29), gap 4q back  1.14 (0.28) | worst-gap year: gap -3.5% rev +4.1pp; gap -2.2% rev -0.9pp; gap -2.8% rev -8.5pp; gap -1.3% rev +1.4pp
after  TSTN: slope on gap now -1.74 (0.42), gap 4q back  0.11 (0.45)
```

## 2026-10-25 (c): opening valuations against the run

Opening P/E is the same every seed (`run.sh open 1`, YEARS 0.1; `board` in the JSON). Board of 91 at the open: median
17.1, p25 14.5, p75 21.1, max 29.0 (SHRK). GDWT 22.0 (19th), TSTN 26.6 (3rd); P/B 5.15 and 5.32 against a board median
of 3.47. Over years 2-20 (`run.sh pe 1|2`, `agg[q].pe` per firm): board median 16.6 / 16.5, so the board holds its
opening multiple; the 23 top-quartile openers fall from 23.6 to 21.1 / 19.7. GDWT 22.0 -> 17.3 / 17.3, TSTN 26.6 ->
24.2 / 18.5. The four after-arm seeds give medians GDWT 17.0, TSTN 20.0, CLAW 18.3 (open 23.1), LYRE 17.3 (open 21.6):
the asset-light professional firms all open 4-6 points above where the market settles them.

## 2026-10-25 (d): board index fixed; betas re-measured (arm `idx`, seeds 1-4, same constants as `after`)

The harness index was a price-return index, so stock splits elsewhere on the board read as crashes (single days of
-30% and -14%), and the first tick compared struck prices with onStart's provisional ones (index 1 -> 0.2). It now
compounds market-value returns and records only on the first tick. Every beta reported before this section is void.
Weekly beta to the board: GDWT 1.04, TSTN 0.66, CLAW 0.75, LYRE 0.89 (were reported 0.61, 0.36, 0.39, 0.46). Vols are
unchanged (20.7%, 25.5%, 18.4%, 22.5%). Market value growth a year over 20 years: board 4.2%, GDWT 3.6%, LYRE 3.2%, CLAW
2.4%, TSTN 2.0%. Annual EPS growth (`after` arm): GDWT 7.2%, LYRE 6.5%, TSTN 5.9%, CLAW 3.1%. Opening market value:
board median $145bn; LYRE $79bn (75th of 91), GDWT $62bn (80th), TSTN $16bn (90th), CLAW $13bn (91st).

## 2026-10-25 (e): AVCT added, GDWT x2.5 (arm `r4`, seeds 1-4, 20y, TPY 360, fixed index)

Harness now also logs `cap1` (every firm's tick-1 market value) and daily shares (vol, beta and drawdown on split-adjusted prices). Tables `r4_4seed_20y.out`, `slopes_r4.out`:

```
r4     GDWT: slope on gap now  0.78 (0.22), gap 4q back  0.65 (0.23) | worst-gap year: s1 gap -3.2% rev -8.2pp; s2 gap -5.7% rev -4.6pp; s3 gap -5.4% rev -5.7pp; s4 gap -3.6% rev +2.6pp
r4     TSTN: slope on gap now -1.42 (0.50), gap 4q back -0.08 (0.47)
r4     AVCT: slope on gap now  0.06 (0.25), gap 4q back  0.47 (0.16) | worst-gap year: s1 gap -3.4% rev -1.3pp; s2 gap -5.7% rev +2.4pp; s3 gap -5.3% rev +2.9pp; s4 gap -3.6% rev +4.6pp
r4     AVCT audit_and_tax: slope on gap now  0.18 (0.22), gap 4q back  0.51 (0.16)
```

GDWT's 'div cut' (1 per seed, DPS x0.26) is its 4:1 split read by analyse.py's div/shares; no real cuts. AVCT seed 4 t=1.31: revenue -17.9% q/q and margin -3.7% with book-to-bill 1.0 on every line, so not a booking move (a generic revenue shock). TSTN max drawdown -76%/-79% in seeds 2/4 (price 42.7 -> 6.0 in s4) with no distress. GDWT is now 29-42% of IT-sector revenue.
