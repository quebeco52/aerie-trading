# autoshort evidence

## 2026-10-27 CAPITAL_ERROR_CORRECTION_SPEED 0.062 -> 0.15 / 0.25, 16 seeds

Question: does a faster plant error-correction speed (roster accelerator, fringe build, entry offset, valuation
catch-up) close industry shortages without raising failures or moving valuation?
Harness: AutoShortHarnessTest.php via run.sh, arms head (src at 26e589c8, 0.062), ovr15 (0.15), ovr (0.25).
Seeds 1-16, 25 years, 360 ticks/year, outlook salt 1000+seed. Metrics y5 on. Analysis:
`cd var/harness/autoshort && python3 compare16.py head ovr15,ovr 1,...,16` -> speed_16s25y.out (full table there).
Caveat: AutoShortHarnessTest.php mtime (19:41) is after head 1-8 / ovr 1-8 ran (19:25-19:40); row width is the same
(19 columns) and head 9-16 ran on the current file, so taken as a comment/format edit, not verified.

| quantity | head | 0.15 diff (se) t | 0.25 diff (se) t |
|---|---|---|---|
| auto share P>1.05 | 0.235 | -0.069 (0.027) -2.6 | -0.101 (0.032) -3.2 |
| auto share P<0.95 | 0.240 | -0.033 (0.017) -1.9 | -0.062 (0.031) -2.0 |
| auto P, head-shortage seeds (n 7) | 1.050 | -0.018 (0.002) -8.5 | -0.028 (0.003) -10.6 |
| board mean abs log P | 0.0259 | -0.005 (0.000) -22 | -0.008 (0.000) -23 |
| board P p5 / p95 | 0.942 / 1.065 | +0.011 / -0.014 | +0.015 / -0.021 |
| board under-build G | 0.041 | -0.008 (0.002) -3.5 | -0.013 (0.004) -3.3 |
| deaths+reorgs per run | 0.375 | +0.125 (0.180) 0.7 | +0.062 (0.170) 0.4 |
| median P/E y5+ | 17.55 | +0.01 (0.04) 0.3 | -0.01 (0.05) -0.1 |
| E/P Q5-Q1 next-yr return | 0.207 | -0.001 (0.007) -0.1 | -0.037 (0.034) -1.1 |
| default firm-sweeps | 4.13 | +0.13 (1.67) 0.1 | -0.31 (1.42) -0.2 |
| FALC rev - trend GDP %/yr | -2.02 | +0.71 (0.46) 1.5 | -0.26 (0.41) -0.6 |
| EIDR rev - trend GDP %/yr | -1.77 | +0.32 (0.33) 1.0 | +0.47 (0.23) 2.0 |

Failures: head 6 (TERN x2, SKUA, FALC x2, EIDR), 0.25 7 (TERN x3, SKYL, FALC x2, EIDR), 0.15 8 (FALC x5, EIDR,
TERN x2). Every auto event is in an auto glut except head s15 FALC (shortage, P 1.08). The failures row is inside
noise at 16 seeds; 0.15's five FALC events (vs 2) are a count of 5 vs 2, not a rate the run can separate.
