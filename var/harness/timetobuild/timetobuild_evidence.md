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
