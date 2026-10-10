# Bank-rescue fiscal cost: evidence

## 2026-10-10 NEW (rescue booked on gross debt) vs BASE (bookBankRescue returns 0.0)

Question: what the bank-rescue cost does to gross debt, the debt brake (0.90) and the sovereign spread.
Harness: FullMarketHarnessTest.php, live macro, POLITICS=1, MACRO_OUT; `run.sh new|base`, `analyse.py 16` (see RUN.md).
16 seeds x 100 years per arm, 360 ticks/year. Stage 8 read first (debt +5y diff 0.100, se 0.021), final on 16.
Output: `rescue_s16_y100.out`.

| quantity (per-seed mean)   | NEW    | BASE    | NEW-BASE (se)   | t    | n  |
|----------------------------|--------|---------|-----------------|------|----|
| debt +5y less pre-crisis   | 0.077  | -0.018  | 0.095 (0.021)   | 4.53 | 16 |
| share of qtrs debt > 0.90  | 0.308  | 0.249   | 0.059 (0.078)   | 0.76 | 16 |
| spread 3y post-crisis (bp) | 9.9    | 0.0     | 9.9 (6.6)       | 1.49 | 16 |
| spread run average (bp)    | 1.98   | 0.18    | 1.80 (0.92)     | 1.97 | 16 |
| mean gross debt            | 0.798  | 0.720   | 0.078 (0.058)   | 1.36 | 16 |
| max gross debt per seed    | 1.196  | 1.018   | 0.179 (0.087)   | 2.05 | 16 |

Crises: NEW 70 (4.4 per seed-century), BASE 58 (3.6); difference within Poisson noise (z ~1.1).
NEW rescue cost/GDP: mean 0.146 (se 0.025), median 0.088, p90 0.294, max 1.394 (seed 10, t~23: debt to 2.33,
net 1.95, spread ~300 bp). Bank credit/GDP the quarter before a crisis: median 1.46 (0.97-2.14).
Rescue assets 10y after peak: median 0.064 of the peak (exp(-10/4.2) = 0.092 less GDP erosion); they wind down.
Debt never hit the 2.5 ceiling. The +5y effect (0.095) matches the arithmetic 0.146 x (1 - 0.54 x (1 - e^(-5/4.2))) = 0.091.

Caveats:
- The full harness is not deterministic run to run on the same seed (seed 1 `new` twice differs in Q1), so the arms
  are not path-identical before the first crisis; pairing is by seed only.
- The spread prices NET debt EMA above 0.90, which BASE almost never reaches; the spread effect is carried by the few
  large draws (log-sd 1.09, untruncated), hence its wide se.
- bankRescueShock() keys its stream on totalTime only, not on the run's seed: every run with a crisis on the same
  tick gets the same z. No two NEW crises shared a tick here.

## 2026-10-10 Crisis -> long slump, bare macro, NEW vs OFF (rescue shock -INF)

Question: how often a credit crisis leads to a long deep slump (gap < -4%), and how much the bank-rescue booking adds
(BusinessCycleRealismTest::testNoSeedIsTrappedAgainstTheCapacityClamp fails on seed 5: 25 quarters, bound < 20).
Harness: crisis_probe.php via crisis.sh new|off <seed>; analyse_crisis.py 1-48. Bare macro as simulateGapPath builds it,
252 tpy, 100y, 5y burn-in, seeds 1-48 (stage 16 inconclusive, ran 48; whole stage ~2 min wall).
Seed 5 reproduces the test: first-40y longest run NEW 25, OFF 15 (crisis t=20.2, cgap +0.149, rescue 0.376 = cap).

```
seeds 1-48 (n=48), 100y, 252 tpy, burn-in 5y; per-crisis windows need t<= 92y
new: crises per century 3.57  (total 163)
off: crises per century 3.55  (total 162)
per crisis                        arm     n   mean   med   p90  max  >=20q
T1 longest run gap<-4% (q)        new   152   2.05   0.0   6.9   41   4.6%
T1 longest run gap<-4% (q)        off   154   2.25   0.0  10.4   33   4.5%
T2 quarters at clamp in 8y        new   152   0.07   0.0   0.0    6   0.0%
T2 quarters at clamp in 8y        off   154   0.03   0.0   0.0    3   0.0%
paired NEW-OFF of per-seed means      diff     se      t   n
T1                                   -0.06   0.22  -0.27  47
T2                                    0.02   0.01   1.38  47
T3 NEW rescue/GDP: n=152 median 0.089 p90 0.311 at cap 9.2%
T3 new gross debt peak 5y: median 1.065 p90 1.269; rise over pre-crisis median +0.116 p90 +0.340
T3 off gross debt peak 5y: median 0.963 p90 1.098; rise over pre-crisis median +0.039 p90 +0.107
whole run per seed: longest run gap<-4% (q)
  95y                      new mean  11.2 share>=20 18.8% | off mean  10.9 share>=20 16.7% | diff +0.40 se 0.89 t 0.44 n 48
  first 40y (test window)  new mean   5.7 share>=20 10.4% | off mean   4.2 share>=20  2.1% | diff +1.50 se 0.62 t 2.43 n 48
```
Runs >= 20q over 48 x 95y tied to a crisis (crisis within 2y before or during the run): NEW 9 (rescues 0.02, 0.08,
0.11, 0.16, 0.28, 0.28, 0.38, 0.38, 0.38), OFF 7; untied 2 in each arm.
Caveats: per-crisis stats only for crises at t in [5, 92]; T1 counts a run already under way at the crisis. Arms are
path-identical only to the first rescue, then diverge; size as unpaired. First-40y t=2.43 is one of several columns
and the 95y version (t=0.44) does not confirm it.
