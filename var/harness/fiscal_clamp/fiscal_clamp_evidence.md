# fiscal_clamp evidence

## 2026-10-10 How often the corporate-tax clamp binds

Question: share of quarters the unclamped target of CreditFiscalSubsystem::calculateDynamicFiscalPolicy() is outside [MIN 0.08, MAX 0.26], and what drives it.
Harness: see RUN.md. Arm A full ticker POLITICS=1, 16 seeds x 100 y, tpy 360. Arm B macro+politics (no board, no fund, no equity cap), 16 seeds x 200 y, tpy 48. src at 4920a31. Wall 27 min.

```
== Arm A: 16 seeds, 100-100 years, 6400 quarters
1. binding share | pooled | mean(se) across seeds | max seed | seeds ever
   below MIN | 0.0000% | 0.0000% (0.0000%) | 0.000% | 0/16
   above MAX | 0.0000% | 0.0000% (0.0000%) | 0.000% | 0/16
2. decomposition at binding quarters (means; law/stab/stance sum with base 0.17 to target)
   side | n | target | law | stab | stance | bohn | deficit | cut | net_debt | gap_ema | rate | funded
   below | 0
   above | 0
   all | 6400 | +0.1691 | -0.0003 | -0.0015 | +0.0008 | +0.0024 | +0.0000 | +0.0015 | +0.4287 | -0.0042 | +0.1691 | 1.00
3. distribution p1/p5/p50/p95/p99 (min, max)
   target | 0.1520/0.1578/0.1693/0.1796/0.1840 (0.1369, 0.1899)
   rate | 0.1540/0.1596/0.1692/0.1781/0.1815 (0.1428, 0.1863)
   stab | -0.0158/-0.0110/-0.0012/+0.0073/+0.0109
   stance | +0.0000/+0.0000/+0.0000/+0.0047/+0.0078
   law | -0.0050/-0.0050/+0.0000/+0.0050/+0.0050
   net_debt | -1.0715/-0.3913/+0.5602/+0.8310/+0.9111
== Arm B: 16 seeds, 200-200 years, 12800 quarters
1. binding share | pooled | mean(se) across seeds | max seed | seeds ever
   below MIN | 0.0000% | 0.0000% (0.0000%) | 0.000% | 0/16
   above MAX | 0.0000% | 0.0000% (0.0000%) | 0.000% | 0/16
2. decomposition at binding quarters (means; law/stab/stance sum with base 0.17 to target)
   side | n | target | law | stab | stance | bohn | deficit | cut | net_debt | gap_ema | rate | funded
   below | 0
   above | 0
   all | 12800 | +0.1629 | -0.0005 | -0.0014 | -0.0052 | +0.0163 | +0.0200 | +0.0015 | +0.8559 | -0.0039 | +0.1630 | 0.00
3. distribution p1/p5/p50/p95/p99 (min, max)
   target | 0.1439/0.1498/0.1629/0.1758/0.1807 (0.1293, 0.1921)
   rate | 0.1453/0.1508/0.1629/0.1749/0.1792 (0.1314, 0.1899)
   stab | -0.0202/-0.0143/-0.0008/+0.0099/+0.0142
   stance | -0.0200/-0.0200/-0.0040/+0.0046/+0.0084
   law | -0.0050/-0.0050/-0.0012/+0.0050/+0.0050
   net_debt | +0.4751/+0.6576/+0.8605/+1.0258/+1.1475
```

Result: never binds (0 of 6400 and 0 of 12800 quarters, 0/32 seeds). Closest approach: target 0.129 (4.9pp above MIN, arm B) and 0.192 (6.8pp below MAX, arm B).
Caveats: target recomputed from the post-step DTO, one tick after the engine computes it; arm B excludes the equity-wealth loop; sampled quarterly, so a sub-quarter excursion could be missed (implausible given 5pp margins and slow EMA inputs).
