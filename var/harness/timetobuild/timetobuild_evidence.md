# Time-to-build evidence

## 2026-10-28 growth capex queued as CIP (working tree vs HEAD 6ec4db3b)

Question: does queueing ordinary growth capex of non-financial firms as construction in progress (placed in service at
CAPEX_COMPLETION_RATE; segment pipeline counts the queue) create capacity cycles without harming the board?

Harness: `TimeToBuildHarnessTest.php` via `run.sh base|ttb <seed>`, live macro (own stream, pairs: output gap mean
diff -0.003pp), 16 seeds x 20 years, TPY 360. Stage 8 then 16 (end cap t -1.9 at 8). Table: `ttb_n16_y20.out`
(events and PPE-stall lists in `ttb_n16_y20_full.out`).

| quantity | base | ttb | diff (se) t |
|---|---|---|---|
| CIP>0 share, non-fin firm-quarters | 0.212 | 0.877 | +0.664 (0.007) 92 |
| CIP/net PPE median / p90 | 0 / 0.003 | 0.037 / 0.078 | +0.037 / +0.075, t 37 / 47 |
| firms ever building | 34.4 | 79.1 | +44.6 (1.0) |
| idle>0.001 share | 0.0042 | 0.0035 | -0.0007 (0.0002) -4.4 |
| idle max | 0.125 | 0.126 | +0.001 (0.003) 0.4 |
| pl p1 / p5 | 0.932 / 0.963 | 0.935 / 0.965 | +0.003 (2.1) / +0.0025 (11) |
| pl p95 / p99 | 1.038 / 1.069 | 1.047 / 1.083 | +0.009 (9.2) / +0.014 (8.7) |
| pl outside +-5% | 0.053 | 0.067 | +0.014 (0.003) 5.3 |
| pl AR(1) industry quarterly | 0.964 | 0.969 | +0.006 (0.001) 5.7 |
| pl mean run one side of 1 (q) | 9.6 | 10.3 | +0.64 (0.13) 4.8 |
| end board cap $tn | 52.5 | 51.2 | -1.31 (0.54) -2.5 |
| median op margin | 0.215 | 0.217 | +0.002 (0.002) 1.0 |
| payout (div+bb)/NI y10-20 | 0.881 | 0.869 | -0.012 (0.004) -2.7 |
| ND/EBITDA y20 median / p90 | 1.29 / 5.81 | 1.30 / 5.63 | inside noise |
| defaults+deaths+reorgs / seed | 1.75 | 1.94 | +0.19 (0.36) 0.5 |
| board revenue growth %/y | 4.38 | 4.30 | -0.08 (0.05) -1.7 |
| real GDP growth %/y | 2.317 | 2.317 | -0.0001 (0.0003) |

Distress mix: TERN defaults in 8/16 ttb seeds vs 4/16 base (discordant 5 vs 1, not significant); FALC 1/16 vs 5/16.
No firm with CIP > 25% of invested capital for 8+ quarters; max CIP/net PPE 0.30 (SKUA). PP&E-stall count unchanged.

Caveats: price-level tails widen, but idle plant does not appear (Grocery, Residential Construction only, both arms).
The queue is short (median CIP 3.7% of net PPE, ~1 quarter of growth capex at completion 0.33).


## 2026-10-28 IBIS plant gap restated in plant units (working tree vs HEAD 0e429349)

Question: does adding log(structuralRevenueMultiplier) to the ledger's trend gap (CapitalBudgetEngine::plantGap) stop
IBIS rebuilding plant when patent cohorts fold out of its book (10-28: revenue flat ~$20bn/q y6-y20, PPE 140->264bn)?
Harness: TimeToBuildHarnessTest.php via run.sh, arms base (BASE = git archive 0e429349) and ttb (working tree),
TAG=-ibis, live macro, 20 years, TPY 360, seeds 1-8 (stage 8; stopped: capex/dep and idle diffs tight around 0).
Analysis: `TAG=-ibis python3 var/harness/timetobuild/ibis.py 1,2,3,4,5,6,7,8 base ttb 1` -> ibis_n8_y20.out.

```
n=8 seeds; quantity | base | ttb | ttb-base (se) t n | ttb range
IBIS ln(PPE y20/y6) | 0.8118 | 0.8488 | +0.0370 (0.1017) t 0.4 n 8 | 0.679..1.1
IBIS ln(rev y20/y6) | 0.5467 | 0.6893 | +0.1426 (0.0964) t 1.5 n 8 | 0.423..1.01
IBIS PPE y6 $bn | 140.5 | 149.3 | +8.7929 (5.0483) t 1.7 n 8 | 139..168
IBIS PPE y20 $bn | 324.5 | 352.4 | +27.9257 (39.6611) t 0.7 n 8 | 277..445
IBIS rev y20 $bn/q | 40.57 | 47.52 | +6.9497 (2.9039) t 2.4 n 8 | 31.5..67.3
IBIS annual rev/netPPE y20 | 0.5071 | 0.538 | +0.0310 (0.0398) t 0.8 n 8 | 0.401..0.617
IBIS capex/dep y6-20 | 1.6 | 1.59 | -0.0097 (0.0426) t -0.2 n 8 | 1.34..1.82
IBIS CIP/netPPE mean | 0.07826 | 0.08573 | +0.0075 (0.0061) t 1.2 n 8 | 0.052..0.125
IBIS idle mean y6-20 | 0.1016 | 0.09997 | -0.0016 (0.0032) t -0.5 n 8 | 0.0937..0.108
IBIS idle max | 0.2178 | 0.2141 | -0.0037 (0.0081) t -0.5 n 8 | 0.189..0.228
IBIS median om y6-20 | 0.3269 | 0.3215 | -0.0053 (0.0378) t -0.1 n 8 | 0.268..0.46
IBIS end cap $bn | 944.9 | 924.3 | -20.6215 (95.4231) t -0.2 n 8 | 522..2.05e+03
IBIS ever B-or-worse/fail | 0 | 0 | +0.0000 (0.0000) t nan n 8 | 0..0
board end cap $tn | 52.48 | 51.23 | -1.2452 (1.1769) t -1.1 n 8 | 36.8..94.1
board idle>0.001 share | 0.5173 | 0.5177 | +0.0004 (0.0040) t 0.1 n 8 | 0.436..0.602
board defaults+deaths+reorgs | 1.25 | 1.75 | +0.5000 (0.4226) t 1.2 n 8 | 0..5
real GDP growth %/y | 2.256 | 2.255 | -0.0008 (0.0004) t -1.9 n 8 | 1.91..2.82

per seed IBIS ln(PPE y20/y6) A/B, rev/PPE A/B, idle mean A/B:
  s1: +0.68/+0.68  0.30/0.40  0.122/0.103
  s2: +0.54/+1.10  0.66/0.48  0.096/0.099
  s3: +1.21/+0.80  0.44/0.62  0.103/0.100
  s4: +0.87/+0.87  0.43/0.53  0.104/0.102
  s5: +0.67/+0.95  0.55/0.55  0.102/0.099
  s6: +0.80/+0.72  0.52/0.51  0.094/0.108
  s7: +0.90/+0.97  0.65/0.60  0.097/0.095
  s8: +0.82/+0.69  0.51/0.61  0.096/0.094

seed 1 IBIS trajectory (year-end report, $bn/q): year | rev ppe capex dep idle om [base] || [ttb]
  y 2 |  14.9  113.7  4.32  2.82 0.000 +0.17 ||  14.6  113.7  4.36  2.82 0.000 +0.14
  y 4 |  19.7  122.9  4.74  3.04 0.000 +0.28 ||  19.3  123.3  5.05  3.04 0.000 +0.30
  y 6 |  22.4  134.6  5.09  3.32 0.000 +0.40 ||  22.7  159.6  7.14  3.93 0.000 +0.31
  y 8 |  21.0  146.2  4.15  3.63 0.166 +0.12 ||  23.2  178.9  5.86  4.41 0.167 +0.32
  y10 |  20.1  153.6  4.35  3.82 0.143 +0.22 ||  28.7  195.0  5.46  4.83 0.141 +0.50
  y12 |  19.6  161.6  6.41  4.00 0.180 +0.34 ||  23.0  207.4  7.37  5.14 0.167 +0.34
  y14 |  20.2  180.1  9.91  4.43 0.039 +0.36 ||  26.8  225.2  9.09  5.57 0.033 +0.45
  y16 |  21.3  214.1  9.62  5.25 0.095 +0.25 ||  28.7  246.8  8.81  6.10 0.083 +0.34
  y18 |  18.8  245.0  8.40  6.04 0.198 +0.10 ||  32.2  303.8  8.89  7.55 0.115 +0.38
  y20 |  20.5  266.7  9.27  6.61 0.143 +0.22 ||  32.0  314.6  8.57  7.84 0.152 +0.37
```

Reading: the fix does not cut IBIS's build-up. capex/dep (-0.01 +- 0.04), idle mean (-0.002 +- 0.003) and PPE log
growth (+0.04 +- 0.10) are unchanged; revenue y20 is higher (+$7bn/q, t 2.4), rev/PPE unchanged. Seed 1 builds
earlier (PPE y6 160 vs 135bn): with the book above 1 the restated gap is larger, so more plant goes up when launches
lift the multiplier. IBIS idle ~10% mean / ~21% max persists in both arms, so it is not from the cohort fold-out.
Board sanity inside noise. Multiplier itself is not logged by the harness.
