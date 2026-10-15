# disclosure evidence

## 2026-10-15 Securities Commission arms (light 0.30/1.5, founding 0.20/1.0, strict 0.10/0.5)

Question: does disclosure enforcement (profit-warning threshold, earnings-management scale) behave sensibly?
Harness: `DisclosureHarnessTest.php` (fork of FullMarketHarnessTest, live macro, no Diet), `run.sh <arm> <seed>`,
4 seeds x 30 years x 360 ticks/yr per arm, working tree as of 2026-10-15 (uncommitted Securities Commission change).
Table: `stage4_y30.out` (mean [min..max] over seeds; paired difference vs founding, se over 4 seeds).

Key rows:
- profit warnings: 0 in every run of every arm (0 per firm-year).
- sd report-day log move: 2.34 / 2.28 / 2.37 % (diffs +0.06 (0.21), +0.09 (0.14)): inside noise.
- announcement variance per firm-year x1e4: 22.1 / 21.0 / 22.4: inside noise.
- accrual bank / TA: 0.11 / 0.07 / 0.04 bp (light +0.046 (0.020), strict -0.026 (0.018)).
- reports in [0, +1c] of consensus: 1.92 / 1.68 / 1.36 % (light +0.24 (0.13), strict -0.32 (0.12));
  [-1c, 0): 0.45 / 0.74 / 0.80 %.
- index vol 16.1 / 16.4 / 16.4 %; board cap x3.1-3.8 over 30 y; max firm price x6-7 seed; deaths 0-2 per run.

Diagnostic (DIAG=1, founding, seed 1, 30 y, 10,320 firm-quarter evaluations): the warning's shortfall ratio
(reversal due + unseen cost squeeze, over structural quarterly earnings) has median 0, p99 0.010, p99.9 0.026,
max 0.046; accrual part max 0.026, cost-squeeze part max 0.046; projected drag change p99 0.6 pp, max 1.3 pp.
The 0.10 strict threshold is never reached, so the warning channel and the economic-shortfall pricing fix are
untested by these runs. The probe counts drag 0 where projectInputCostDragChange is null (25% of rows); the engine
then uses a fallback gap, but the engine also printed no warning, so the conclusion stands.

Caveats: live macro, so arms drift apart on a seed; 4 seeds; no Diet.
