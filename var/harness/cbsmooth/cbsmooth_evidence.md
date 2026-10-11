# CB_SMOOTHING_SPEED evidence

## 2026-10-10: does the policy-rate partial-adjustment speed explain the short, oscillatory cycle?

Question: live game gap ACF4 0.37 (US 0.65), policy ACF4 0.01, 10Y ACF4 0.05 (US ~0.9). Does CB_SMOOTHING_SPEED = 4.0/yr
(63% of the gap closed a quarter; its docblock cites the US at 13-15%) cause it?

Harness: `CbSmoothHarnessTest.php` (FullMarketHarnessTest copy + `y10`), live macro, whole board, POLITICS=1, on a frozen
snapshot of the working tree at HEAD ae20b98 (src tree 'git rev-parse HEAD:src' at the time), arm differences by OVR copy of
MonetaryPolicySubsystem.php. Arms A 4.0, B 0.65, C 1.4. Seeds 1-48 per arm, 21 years, year 1 dropped, 360 ticks/year.
Staged 8 -> 16 -> 48 (gap ACF4 B-A fell from t 2.5 to 1.1 at 16, so 48 was needed). 144 runs + 2 smoke, ~37 min wall.

Table (`stage48.out`), means with se, paired difference vs A (se) t:

    quantity        A        B        C       B-A (se) t           C-A (se) t
    gap_acf4        0.444    0.523    0.425   +0.079 (0.028) +2.8   -0.019 (0.033) -0.6
    pol_acf4        0.428    0.683    0.549   +0.255 (0.035) +7.3   +0.122 (0.037) +3.3
    y10_acf4        0.460    0.479    0.454   +0.019 (0.035) +0.5   -0.006 (0.037) -0.2
    gap_sd%         1.405    1.718    1.595   +0.313 (0.068) +4.6   +0.190 (0.081) +2.3
    infl_mean%      2.216    2.175    2.253   -0.041 (0.051) -0.8   +0.037 (0.058) +0.6
    infl_sd%        1.249    1.295    1.243   +0.046 (0.037) +1.3   -0.006 (0.024) -0.3
    u_sd%           0.697    0.896    0.851   +0.199 (0.046) +4.3   +0.154 (0.057) +2.7
    elb_share       0.030    0.000    0.003   -0.030 (0.007) -4.2   -0.027 (0.007) -3.7
    max|dpol|4q%    4.659    2.671    3.894   -1.988 (0.165) -12.1  -0.765 (0.187) -4.1

Reading: the speed sets policy-rate persistence (B +0.26) and the largest 4-quarter swing (4.7 -> 2.7pp), but explains
only a small part of gap persistence (+0.08, B still 0.52 vs US 0.65) and none of the 10Y's (flat at ~0.46-0.48).
Slower smoothing raises gap sd (1.41 -> 1.72) and u sd; inflation unchanged; no blow-ups.

Caveats: harness arm A gap ACF4 (0.44) is above the live 35-quarter reading (0.37), inside its noise. US policy ACF4 not
reached by any arm, so the Taylor target itself is noisy. The 10Y's low persistence lies outside the policy-rate path.

## 2026-10-10: does a slower policy-rate adjustment shrink the fund's effect on public debt?

Question: at CB_SMOOTHING_SPEED 0.65, SovereignFundDynamicsTest's gross-debt assertion (median late debt with fund
at least 10pp below without) fails (0.93 vs 0.98; 0.86 vs 0.99 at 4.0). Is the fund's debt effect smaller at 0.65?

Harness: `fund_debt.php` (the test's `simulate()` verbatim: macro only, synthetic board, 80 years, 24 ticks/yr, no
politics) plus a per-tick decomposition of the gross debt ratio into the public accounts' lines, closing to 1e-4.
`fund_run.sh A1|A0|B1|B0 <seed>` on the snapshot tree (A = 4.0, B = 0.65 via ovrB; 1 = fund, 0 = none).
`bin/harness-batch var/harness/cbsmooth/fund_run.sh 1-48 A1 A0 B1 B0 && python3 var/harness/cbsmooth/fund_analyse.py 1-48`
(~30 s for 192 runs). Target: D = mean lateDebt(off) - mean lateDebt(on) per seed (lateDebt = the test's 30 annual
samples, years 51-80) and DiD = D(0.65) - D(4.0).

Check: the test's own seeds reproduce its medians (A1 0.859, A0 0.992, B1 0.930, B0 0.981).
Stages: 16 seeds DiD -0.006 (se 0.037), not negligible against the 10pp margin, so 48.

48 seeds (fund_stage48.out):
  D(4.0) = -0.082 (se 0.036, t -2.3); D(0.65) = -0.072 (se 0.036, t -2.0); DiD = +0.010 (se 0.020, t 0.5).
  Pooled medians: A1 0.996, A0 0.954, B1 1.015, B0 0.983. The fund RAISES mean late gross debt at both speeds.
  On the test's six seeds D(4.0) = +0.017 (se 0.159); its 13pp median gap is a six-seed draw.
Mechanism (measured): the Bohn rule reads net financial liabilities (gross less the whole fund). With the fund NFL
  sits at 0.40-0.52, under the 0.70 threshold, so the Bohn surplus is active 27-35% of ticks (100% without): stance
  0.3-0.5% of GDP with the fund vs 0.6-0.8% without (-2% structural deficit + 2.6-2.8% Bohn). Primary balance is
  0.2pp a year lower with the fund. Cross-seed: D = 0.085 - 0.275 x late fund/GDP (se 0.043, R^2 0.30, n 96).
  DiD lines: primary deficit +0.036 (0.027), (r-g)d -0.046 (0.019), rescue +0.020 (0.036): they offset. At 0.65
  the fund ends smaller (fund/GDP 0.53 vs 0.64, DiD se 0.031), so Bohn binds more often (+7.9pp of ticks).
Caveats: no politics, synthetic board, 24 ticks/yr; means not medians as the primary statistic.

## 2026-10-10: does a learned inflation endpoint (gain 0.10/yr) move the breakeven and long end without moving the cycle?
- Arms: Y0 = `$SNAP/y10tree` (HEAD f187f55); Y1 = OVR `$SNAP/ovr1` (the five changed src files, cmp-identical to the working tree).
- Board: `y10_run.sh`, 21 y at 360 ticks/yr, politics on, year 1 dropped. Stages 8, 16, 48; stopped at 48 (ceiling).
  Tables `y10_stage8.out`, `y10_stage16.out`, `y10_stage48.out`. Macro-only: `y10_macro_run.sh` MY0/MY1, 16 seeds,
  same years/ticks, no board, no fund (`y10_macro16.out`).
- Board, n=48 (paired d, se, t): y10 ACF4 -0.039 (0.032) -1.2; tp10 ACF4 -0.020 (0.040) -0.5; be10 sd +0.002pp (0.040) +0.0;
  be10 ACF4 +0.192 (0.051) +3.8. No guard past |t| 1.5 (infl sd -0.061pp, 0.040).
- Macro-only, n=16: y10 ACF4 +0.009 (0.004) +2.2; tp10 ACF4 -0.001 (0.005); be10 sd +0.001pp (0.005) +0.1;
  be10 ACF4 +0.188 (0.042) +4.4; guards all |t| <= 1.5, crisis seeds identical.
- Verdict: the endpoint makes the breakeven more persistent (ACF4 0.24 -> 0.43) but does not raise its sd or move the
  ten-year/term-premium persistence; cycle untouched. Board be10 sd has a fat right tail (a few seeds), so its se is large.

## 2026-10-11: does a recession-regime cut speed (0.65 + 1.8/yr while cutting in the regime) give US-sized easing without moving persistence?
Harness: `CbSmoothHarnessTest.php` via `crisis_run.sh` (K0 = crtree with CB_RECESSION_CUT_SPEED 0.0, behaviourally the
pre-change tree; K1 = ovrK, constant 1.8). Live macro + politics, 21 years (year 1 dropped), 360 ticks/yr, seeds 1-48
paired. Seeds 1-16 and 18 come from the interrupted run (its K0 used the true pre-change tree); seeds 17 and 19-48 were
run on the rebuilt snapshot. Sanity: K0 seed 1 rerun (as K9) was clean and inside the cross-seed range of the stored K0-1.
Targets: cut4q_rec%, elb_share, trough_gap% + rec_q. Analysis: `crisis_analyse.py K0 K1` (`SEEDS=1-16` for the stage).

Stage 16 (seeds 1-16, `crisis_s16.out`):
```
n=16 paired seeds; blown up: K0 [], K1 []
quantity                     K0                K1                dK1-K0 (se) t  n
cut4q_rec%       2.105 (0.110)    3.885 (0.235)      +1.780 (0.256)   +7.0  16
cut4q_all%       2.105 (0.110)    3.885 (0.235)      +1.780 (0.256)   +7.0  16
elb_share        0.000 (0.000)    0.024 (0.012)      +0.024 (0.012)   +2.0  16
trough_gap%     -2.193 (0.178)   -2.230 (0.243)      -0.037 (0.300)   -0.1  16
rec_q            8.000 (1.396)    9.938 (1.644)      +1.938 (2.472)   +0.8  16
pol_acf4         0.661 (0.035)    0.605 (0.019)      -0.055 (0.047)   -1.2  16
gap_acf4         0.444 (0.027)    0.468 (0.041)      +0.024 (0.055)   +0.4  16
gap_sd%          1.625 (0.102)    1.569 (0.076)      -0.055 (0.114)   -0.5  16
infl_mean%       2.320 (0.074)    2.247 (0.086)      -0.073 (0.107)   -0.7  16
infl_sd%         1.262 (0.048)    1.260 (0.038)      -0.002 (0.064)   -0.0  16
y10_mean%        4.798 (0.113)    4.801 (0.160)      +0.003 (0.166)   +0.0  16
gap_mean%       -0.275 (0.103)   -0.252 (0.146)      +0.023 (0.190)   +0.1  16
pol_mean%        4.020 (0.182)    3.593 (0.144)      -0.427 (0.244)   -1.8  16
crisis           0.625 (0.125)    0.562 (0.128)      -0.062 (0.170)   -0.4  16
evans_t          0.000 (0.000)    0.014 (0.009)      +0.014 (0.009)   +1.6  16
regime_t         0.341 (0.021)    0.349 (0.027)      +0.008 (0.032)   +0.3  16
switches/yr      0.497 (0.028)    0.412 (0.026)      -0.084 (0.038)   -2.2  16
fastcut_t        0.255 (0.014)    0.213 (0.015)      -0.041 (0.022)   -1.9  16
hike4q%          2.556 (0.263)    2.695 (0.203)      +0.138 (0.360)   +0.4  16
recession seeds: K0 16, K1 16
```
Final 48 (`crisis_s48.out`, + `gap_semidev.py` mechanism check):
```
n=48 paired seeds; blown up: K0 [], K1 []
quantity                     K0                K1                dK1-K0 (se) t  n
cut4q_rec%       2.122 (0.074)    3.729 (0.154)      +1.607 (0.173)   +9.3  48
cut4q_all%       2.122 (0.074)    3.776 (0.141)      +1.653 (0.160)  +10.4  48
elb_share        0.000 (0.000)    0.012 (0.005)      +0.012 (0.005)   +2.5  48
trough_gap%     -2.549 (0.155)   -2.209 (0.144)      +0.339 (0.187)   +1.8  48
rec_q            9.646 (0.993)    8.875 (0.865)      -0.771 (1.249)   -0.6  48
pol_acf4         0.654 (0.019)    0.593 (0.014)      -0.061 (0.026)   -2.4  48
gap_acf4         0.491 (0.020)    0.421 (0.025)      -0.070 (0.034)   -2.1  48
gap_sd%          1.750 (0.054)    1.558 (0.039)      -0.193 (0.058)   -3.3  48
infl_mean%       2.326 (0.051)    2.245 (0.036)      -0.081 (0.063)   -1.3  48
infl_sd%         1.314 (0.029)    1.255 (0.021)      -0.059 (0.037)   -1.6  48
y10_mean%        4.896 (0.081)    4.767 (0.079)      -0.129 (0.109)   -1.2  48
gap_mean%       -0.369 (0.074)   -0.133 (0.074)      +0.236 (0.105)   +2.2  48
pol_mean%        4.000 (0.101)    3.606 (0.094)      -0.394 (0.143)   -2.8  48
crisis           0.542 (0.073)    0.583 (0.072)      +0.042 (0.089)   +0.5  48
evans_t          0.000 (0.000)    0.010 (0.004)      +0.010 (0.004)   +2.7  48
regime_t         0.371 (0.014)    0.347 (0.015)      -0.024 (0.021)   -1.1  48
switches/yr      0.464 (0.016)    0.450 (0.017)      -0.014 (0.022)   -0.6  48
fastcut_t        0.279 (0.009)    0.207 (0.009)      -0.072 (0.013)   -5.5  48
hike4q%          2.796 (0.157)    2.777 (0.108)      -0.019 (0.184)   -0.1  48
recession seeds: K0 48, K1 48
n=48
down_sd%    1.481   1.170  -0.311 (0.089) t -3.5
up_sd%      1.001   1.053  +0.051 (0.070) t +0.7
p05%       -3.323  -2.591  +0.732 (0.202) t +3.6
p95%        2.469   2.618  +0.149 (0.162) t +0.9
```
Reading: the cut size moves at t 9 (2.1 -> 3.7pp), still short of the US 4.75-5.0pp. The lower-bound share is 1.2% against the US ~25%,
which r* sets, not the speed. Trough and recovery length do not move at the 2-se level. Guard flagged: gap sd -0.19pp
(t -3.3). The whole drop is on the downside (down semi-sd -0.31 t -3.5, p05 +0.73 t +3.6; upside unchanged), so slumps
are shallower and the cycle is not damped. pol ACF4 -0.06 (t -2.4) and gap ACF4 -0.07 (t -2.1) fall slightly, under 3.
The 4-seed 10Y +0.67pp is NOT real: -0.13 (0.11) at 48. The mean policy rate FALLS 0.39pp (t -2.8) while the mean gap rises 0.24
(t 2.2), so the reading that faster cuts lift the mean rule rate and so the 10Y is contradicted. Crisis incidence is unchanged (+0.04, se 0.09).
Regime share 0.35-0.37 against the US ~0.22 (context).

## 2026-10-11: which leg of the recession regime over-fires, and the latch entry matched to the US share
Harness: `RegimeHarnessTest.php` (fork of CbSmoothHarnessTest: engine's own latch `authorityStressRegime`, per-tick
`sahm_t latch_t both_t ebp1_t` and counterfactual latches `lat<bp>_t`/`uni<bp>_t` at entry 1.00-2.50pp, exit 0, on the run's
EBP path) via `regime_run.sh` on a `git archive HEAD` snapshot (rgtree). R1 = HEAD; R2 = CB_STRESS_ENTRY_EBP 0.0125;
R3 = R2 + SAHM_RECESSION_THRESHOLD 0.0065. 21 years (year 1 dropped), 360 ticks/yr, seeds 1-48 paired, live politics.
US (regime_us.py on GZ ebp_csv + FRED SAHMREALTIME, USREC; monthly): 1985-2019 EBP mean 0.107 sd 0.595pp, >=1pp 0.067,
ACF1 quarter-end 0.81; Sahm>=0.5 0.162 (0.180 to 2021); USREC 0.081; latch(1.0/0.0) 0.105, latch-only 0.040, union 0.202 (0.221 to 2021).
Sim R1 (48): EBP mean 0.115 sd 0.446, >=1pp 0.047 (ticks), ACF1q 0.68; Sahm 0.229, NBER-like (technical recession on
deflated nominal GDP) 0.160; latch 0.193, latch-only 0.126, both 0.067, union 0.354. Latch counterfactual grid (R1):
1.00 0.193, 1.25 0.142, 1.50 0.111, 1.75 0.086, 2.00 0.063 -> US 0.105 at ~1.55pp; R2's own grid gives 1.50 -> 0.090.
Final 48 (`regime_s48.out`), selected rows:
```
quantity          R1        R2        R3      dR2-R1 (se) t       dR3-R1 (se) t
regime_t        0.354     0.297     0.274    -0.057 (0.020) -2.9  -0.080 (0.016) -5.1
gap_acf4        0.455     0.449     0.442    -0.006 (0.030) -0.2  -0.013 (0.035) -0.4
cut4q_rec%      3.700     3.817     3.892    +0.117 (0.217) +0.5  +0.192 (0.242) +0.8
latch_t         0.193     0.123     0.123    -0.070 (0.021) -3.3  -0.070 (0.021) -3.3
sahm_t          0.229     0.216     0.189    -0.012 (0.011) -1.1  -0.039 (0.011) -3.6
gap_sd%         1.632     1.661     1.655    +0.028 (0.072) +0.4  +0.022 (0.095) +0.2
pol_acf4        0.591     0.603     0.551    +0.012 (0.032) +0.4  -0.040 (0.030) -1.3
```
Guards (infl mean/sd, 10Y, crisis, ELB, blow-ups) all inside 1.1 se. Reading: both legs over-fire. The latch (0.19 vs 0.105)
does so although the sim's EBP is LESS volatile than GZ (sd 0.45 vs 0.60, tail >=1pp 0.047 vs 0.067); its entries come from
short, less persistent spikes (ACF1q 0.68 vs 0.81) that the exit at 0 then holds, mostly outside Sahm recessions (latch-only
0.126 vs 0.040). Sahm (0.23 vs 0.16-0.18) over-fires because the sim spends ~2x the US time in recession (0.16 vs 0.08);
per recession quarter it fires less than the US (1.4x vs 2.0x). Gap ACF4 does not respond to the regime share. Caveat: the
8- and 16-seed grids pointed at 1.25pp; at 48 the match is ~1.5pp (not run as an arm).
