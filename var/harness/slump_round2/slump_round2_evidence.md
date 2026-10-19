# Slump round 2 evidence

## 2026-10-19: what is left after B + D + E (G), and does any of P1, P2, H2, N1, S1 shorten slumps further?
Question: with the round-1 package committed (2c3c87c8), does it still beat the old model once the mainland's path is
paired, and does a further candidate cut the deep-gap share, the longest run below -1.5% or the cold mean gap?
Harness: SlumpRound2HarnessTest.php via run.sh/setup.sh (see RUN.md). A = 85b1458c, G = HEAD 2c3c87c8 (working src
identical), candidates = G + one edit; every arm carries the mainland pairing overlay. YEARS 52, TPY 360, POLITICS 1,
BURN 2. Stage 8 for all arms (s8.out); A, G, P1 extended to 16 (s16.out), the arms with a target at |t| 1.5-2.5.
No crashes, no NaN, no truncated run; runs took 7-9.6 min.

Pairing check: quarterly fgap correlates with the control's on the same seed at min 0.984 (G vs A, seed 13; 16 seeds),
min 0.989 for the candidates vs G (H2 seed 1, stage 8).

Paired differences, mean (se) t; targets first:

| quantity | A | G | G-A (16) | P1-G (16) | P2-G (8) | H2-G (8) | N1-G (8) | S1-G (8) |
|---|---|---|---|---|---|---|---|---|
| share gap < -2% | 0.220 | 0.138 | -0.083 (0.022) -3.7 | -0.022 (0.016) -1.3 | -0.004 (0.019) | +0.010 (0.025) | +0.005 (0.022) | -0.022 (0.017) -1.3 |
| longest < -1.5% (q) | 20.0 | 14.8 | -5.2 (2.8) -1.8 | -2.1 (1.4) -1.5 | -0.6 (3.2) | -0.5 (2.6) | -2.5 (2.6) | -1.9 (2.0) |
| gap mean % | -0.29 | +0.01 | +0.29 (0.13) +2.4 | +0.06 (0.12) | +0.06 (0.13) | -0.06 (0.17) | -0.24 (0.08) -2.9 | +0.11 (0.13) |
| u - nairu (pp) | 0.58 | 0.32 | -0.26 (0.08) -3.3 | -0.02 (0.06) | -0.02 (0.06) | +0.03 (0.09) | +0.10 (0.06) +1.8 | -0.07 (0.07) |
| infl 4q mean / sd % | 2.14 / 0.86 | 2.17 / 0.84 | +0.03 / -0.02 | -0.03 / -0.01 | -0.02 / +0.03 | -0.03 / +0.04 | -0.01 / -0.01 | -0.04 / +0.05 |
| supercore sd % | 0.64 | 0.60 | -0.03 (0.03) | -0.04 (0.04) | +0.05 (0.05) | -0.00 (0.04) | +0.01 (0.04) | +0.07 (0.04) +1.6 |
| floor share | 0.062 | 0.042 | -0.021 (0.011) -1.9 | -0.018 (0.007) -2.5 | +0.007 | +0.009 | -0.002 | -0.008 |
| recession starts /dec | 1.64 | 1.36 | -0.28 (0.17) -1.7 | +0.15 (0.12) +1.3 | +0.05 | +0.08 | +0.23 (0.22) | +0.03 |
| credit crises /cent | 3.3 | 2.6 | -0.6 (0.5) | +0.6 (0.5) | +0.5 (1.1) | +1.0 (0.5) +1.9 | -0.3 (0.6) | +0.5 (0.7) |
| gap acf 13 | 0.128 | 0.021 | -0.11 (0.04) -2.5 | -0.03 (0.04) | +0.14 (0.05) +2.9 | +0.03 (0.04) | +0.08 (0.05) +1.6 | +0.04 (0.04) |
| corr(infl, gap t-4) | 0.10 | 0.08 | -0.02 (0.05) | -0.09 (0.04) -2.2 | +0.02 (0.05) | -0.07 (0.08) | -0.03 (0.04) | -0.05 (0.04) |
| share gap > +2% | 0.170 | 0.119 | -0.052 (0.018) -2.8 | +0.017 (0.024) | +0.019 (0.024) | -0.016 (0.016) | -0.046 (0.020) -2.3 | -0.001 (0.019) |

Mainland ANCOVA: the requested adjustment (intercept of the paired difference on the paired difference of fgap means)
is in s8.out/s16.out but is not a valid control here. Under pairing the fgap-mean difference (G-A +0.039 pp, se 0.016)
is caused by the arm: the District's gap moves the mainland's mean through the 0.12 import spillover, so the regressor
is a mediator, and the fitted slope (~7-8 ≈ 1/0.12) soaks up the arm's own effect (G-A gap mean +0.29 raw -> -0.00
adjusted). The raw paired figures are the estimates.

Verdict: G beats A with the mainland paired (share < -2% -0.083, t-3.7; gap sd -0.49, t-4.0; gap mean +0.29, t2.4;
longest -5.2 q, t-1.8 at 16 seeds, the same size as round 1's -6.3). No candidate moves a target past |t| 2 against G.
P1 (kink moved out) is the only one leaning the right way on both slump targets (-0.022 share, -2.1 q longest, t -1.3
and -1.5 at 16 seeds; 2-se bands include 0) with fewer floor quarters (t-2.5) and a weaker inflation-gap lead (t-2.2).
P2, H2, S1 are inside noise on all three targets at 8 seeds. N1 makes the mean gap colder (-0.24 pp, t-2.9) and cuts
booms (share > +2% -0.046, t-2.3) with no gain on slumps. P2 adds ringing (acf 13 +0.14, t2.9).
Caveats: stage 8 for P2/H2/N1/S1 detects only large effects (2-se bands on share ~ +/-0.04, on longest ~ +/-5 q);
P1 would need ~48 seeds to resolve a -2 q effect. Wall time: stage 8 (56 runs) ~55 min on 8 slots, stage 16 ~24 min.

## 2026-10-19: mainland echo fix (spillover mean reads domesticDemandGapEma, not outputGapEma)

Question: does removing the mainland's own cycle from its spillover mean (loop gain ~0.10) shorten mainland runs and
change the District's cycle? Harness: SlumpRound2HarnessTest.php, arm X via setup_echo.sh + run.sh X (RUN.md, section
"Mainland-echo check"); G reused from round 2 (HEAD 2c3c87c8 + pairing overlay; runs made after the commit, setup.sh
unchanged, working src then identical to HEAD). YEARS 52, TPY 360, POLITICS 1, BURN 2. Stage 8 (echo_s8.out) put the
longest same-sign mainland run at t -2.0, so extended to 16 (echo_s16.out, below). No NaN, no crash, no short run;
X runs took 6.8-8.8 min.

```
n = 16 paired seeds (1-16), quarters after year 2, 52 years; mean [min, max] over seeds; paired X-G: mean (se) t
flags: none (all finite, all runs complete, OK in every log)
run times: G-1: 09:04.589, G-2: 06:44.207, G-3: 09:08.007, G-4: 08:35.939, G-5: 07:02.445, G-6: 08:40.446, G-7: 08:38.926, G-8: 07:34.011, G-9: 06:43.925, G-10: 08:40.968, G-11: 07:10.285, G-12: 06:44.543, G-13: 06:06.447, G-14: 06:18.550, G-15: 07:45.618, G-16: 07:38.916, X-1: 08:44.050, X-2: 08:46.601, X-3: 08:05.050, X-4: 08:21.587, X-5: 08:29.642, X-6: 08:28.243, X-7: 07:04.699, X-8: 07:38.366, X-9: 08:28.326, X-10: 08:09.266, X-11: 08:30.583, X-12: 07:05.214, X-13: 07:52.278, X-14: 06:45.773, X-15: 07:26.734, X-16: 07:30.629
pairing: corr of quarterly fgap X vs G per seed: min 0.988, mean 0.994
quantity                   G                          X                         X-G             
fgap_mean%        0.102 [ -0.648,  1.025]    0.073 [ -0.584,  0.974]    -0.029 ( 0.015) t  -1.9
fgap_sd%          1.628 [  1.243,  2.592]    1.572 [  1.203,  2.441]    -0.056 ( 0.010) t  -5.5
fgap_acf4         0.643 [  0.406,  0.819]    0.618 [  0.367,  0.802]    -0.025 ( 0.002) t -10.6
fgap_acf8         0.343 [  0.020,  0.616]    0.301 [ -0.052,  0.585]    -0.042 ( 0.005) t  -9.1
f_longest_run    40.562 [ 27.000, 73.000]   37.062 [ 23.000, 59.000]    -3.500 ( 2.947) t  -1.2
f_mean_run       11.013 [  7.346, 23.375]   10.556 [  6.821, 18.250]    -0.457 ( 0.579) t  -0.8
f|>2%|_sh         0.235 [  0.110,  0.510]    0.210 [  0.090,  0.450]    -0.024 ( 0.005) t  -4.5
rf_mean%          3.846 [  2.485,  5.235]    3.771 [  2.448,  5.052]    -0.075 ( 0.021) t  -3.7
rf_max%           9.291 [  7.716, 11.447]    8.994 [  7.393, 11.360]    -0.296 ( 0.072) t  -4.1
rf>6%_sh          0.197 [  0.085,  0.310]    0.177 [  0.050,  0.295]    -0.020 ( 0.004) t  -5.2
rf_floor          0.077 [  0.000,  0.375]    0.070 [  0.000,  0.330]    -0.007 ( 0.003) t  -2.2
fslump_q         15.614 [ 11.000, 27.500]   15.492 [ 11.200, 27.500]    -0.122 ( 0.428) t  -0.3
gap_mean%         0.009 [ -0.711,  0.682]   -0.139 [ -0.543,  0.618]    -0.148 ( 0.116) t  -1.3
gap_sd%           1.802 [  1.254,  2.317]    1.754 [  1.474,  2.349]    -0.048 ( 0.091) t  -0.5
gap<-2%_sh        0.138 [  0.035,  0.260]    0.140 [  0.050,  0.205]    +0.003 ( 0.017) t  +0.2
longest<-1.5     14.812 [  7.000, 28.000]   12.125 [  7.000, 19.000]    -2.688 ( 1.923) t  -1.4
corr(g,f)         0.459 [  0.049,  0.652]    0.439 [  0.216,  0.688]    -0.020 ( 0.031) t  -0.6
hp_corr           0.421 [  0.202,  0.633]    0.429 [  0.188,  0.535]    +0.008 ( 0.042) t  +0.2
infl4_mean%       2.167 [  2.063,  2.312]    2.208 [  2.007,  2.482]    +0.041 ( 0.033) t  +1.3
infl4_sd%         0.840 [  0.653,  0.996]    0.917 [  0.734,  1.206]    +0.076 ( 0.053) t  +1.5
pol_floor         0.042 [  0.000,  0.105]    0.034 [  0.000,  0.100]    -0.008 ( 0.011) t  -0.7
```

Reading: the echo removal tightens the mainland (fgap sd -3.4%, acf4 -0.025, acf8 -0.042, |fgap| > 2% share -2.4pp,
Fed rate mean -7bp, max -30bp, share > 6% -2pp; all |t| >= 3.7). Run lengths do not move: mean same-sign run -0.46q
(t -0.8), longest -3.5q (t -1.2; t -2.0 at 8 was one seed), zigzag slump -0.1q (t -0.3), ~15.5q against the standalone
AR(2)'s ~14.1. District targets inside noise at 16 seeds (gap < -2% share +0.3pp, longest < -1.5% -2.7q t -1.4).
