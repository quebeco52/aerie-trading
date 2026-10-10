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
