# GSCPI fit evidence

## 2026-10-14: fit of the rebuilt GSCPI and supplier-deliveries constants (working tree on 8ab67e3)

Question: constants for `MacroTransmission::calculateGscpiComposite` and `DELIVERY_FACTORY_LOADING` so the index hits the
NY Fed GSCPI 1998-2019 moments (mean -0.30, sd 0.475, monthly AR(1) 0.79). Harness and commands in `RUN.md`.
Macro loop + politics, no board, no equity market cap (equity-wealth loop off), 16 seeds x 30 y, 360 ticks/y, monthly
samples, burn-in 3 y. Outputs: `step1_P.out`, `step1_L.out`, `step2_L.out`, `verify_F.out`, `verify_G.out`, `rawdiag_G.out`.

Step 1 (arm P, loading 8): sd ln K(CU)/K(.785) 0.2098, sd ln tightness 0.0667 -> loading 39.49 x 0.0667/0.2098 = 12.55.
Re-measured on arm L (loading 12.55): 12.70 (converged). Delivery raw on L: mean 49.50, sd 4.53, min 33.7, max 65.6,
AR(1) 0.911; target explains 0.778 of its variance; never above 78.8 (max 65.6; 1.5% of months above 60).

Step 2 (arm L): EMA inputs fr 98.17/15.16, inv 0.01034/0.0446, del 49.52/4.14 (mean/sd). Purge R2 (fr, inv, del):
og 0.083/0.148/0.579 (avg 0.270); gg 0.198/0.001/0.418 (0.206); dg 0.002/0.797/0.080 (0.293) -> dg, betas -1.87, 34.16,
10.80. Purged corr fr-inv 0.08, fr-del 0.55, inv-del 0.08; PC1 0.437/0.125/0.437, share 0.523 (>0.5, PC1 kept).
Weighted sd 0.760, mean +0.050 (purge shifts the mean by beta x mean(dg) = -0.0061).

Step 3, paired vs HEAD (H), n = 16. G = F with the purge's mean shift folded into the MEAN constants.

| quantity | G | H | diff | se | t |
|---|---|---|---|---|---|
| CPI inflation sd | 0.01024 | 0.00943 | +0.00082 | 0.00047 | +1.73 |
| CPI inflation mean | 0.02107 | 0.02113 | -0.00006 | 0.00059 | -0.10 |
| core goods inflation sd | 0.00785 | 0.00642 | +0.00143 | 0.00048 | +2.99 |
| PPI inflation sd | 0.02573 | 0.02590 | -0.00017 | 0.00099 | -0.18 |
| output gap sd | 0.01784 | 0.01780 | +0.00004 | 0.00263 | +0.02 |
| mean max(0, index EMA) | 0.0787 | 0.1264 | -0.0478 | 0.0230 | -2.07 |

Index (G): mean -0.300, sd 0.474, AR(1) 0.988 (target 0.79), share > 1 0.64%, > 2 0, max 1.21; EMA sd 0.454.
F (unfolded means): mean -0.278, sd 0.465.

Caveats: AR(1) cannot be reached by constants: the composite reads EMA inputs (AR(1) 0.98-0.995). The same weights on
raw inputs give 0.934, still held up by the inventory gap (raw AR(1) 0.995). dg wins the purge on inventory alone; it
leaves delivery's demand content (R2 0.58 on og) in. Stopped at 16 seeds as asked.

## 2026-10-14 (round 2): raw inputs, one regressor per input (working tree with loading 12.55)

Structure: the caller passes raw freightRateIndex, inventoryStockGap and supplierDeliveryTimes, each purged on its own
regressor (freight on globalDemandGapEma, inventory on domesticDemandGapEma, delivery on outputGapEma). Arms Q (working tree,
placeholders) and R (ovrR, fit2.json); H is the cached HEAD arm. Same 16 seeds x 30 y, 360 ticks/y, monthly, burn-in 3 y.
Outputs `step2raw_Q.out`, `verify_R.out`.

Fit (Q): fr mean 98.41 sd 17.54 AR1 0.915, beta 25.15 (R2 0.171); inv mean 0.00998 sd 0.0457 AR1 0.995, beta 35.68
(R2 0.884); del mean 49.57 sd 4.57 AR1 0.913, beta 35.41 (R2 0.478). Purged corr fr-del 0.30, others ~0. PC1 share 0.434
-> equal weights. Weighted sd 0.4553. Folded MEANs fr 99.82, inv 0.000918, del 50.30.

Verify (R): index mean -0.308, sd 0.460, AR1 0.906 (target 0.79); EMA sd 0.407; >1 0.23%, >2 0, max 1.18.
corr(index, outputGapEma) +0.002 (weighted z before purge +0.636). AR1 contributions: fr 0.489, inv 0.059, del 0.338.

| quantity | R | H | diff | se | t |
|---|---|---|---|---|---|
| CPI inflation sd | 0.01016 | 0.00943 | +0.00073 | 0.00045 | +1.64 |
| CPI inflation mean | 0.02114 | 0.02113 | +0.00001 | 0.00060 | +0.02 |
| core goods inflation sd | 0.00736 | 0.00642 | +0.00094 | 0.00042 | +2.21 |
| PPI inflation sd | 0.02554 | 0.02590 | -0.00036 | 0.00096 | -0.38 |
| output gap sd | 0.01777 | 0.01780 | -0.00003 | 0.00259 | -0.01 |
| mean max(0, index EMA) | 0.0567 | 0.1264 | -0.0697 | 0.0181 | -3.84 |

## 2026-10-14 (round 3): working tree as applied (round-2 means/sds/betas, PC1 weights 0.493/0.013/0.494, WEIGHTED_SD 0.652)

Arm W = working tree, no overrides; 16 seeds x 30 y, 360 ticks/y, monthly, burn-in 3 y; paired vs cached H. `verify_W.out`.
Index: mean -0.310, sd 0.462, AR1 0.899; EMA sd 0.406; >1 0.42%, >2 0, max 1.23; corr with outputGapEma -0.001.
W - H: CPI sd +0.070pp (se 0.045, t 1.6); CPI mean +0.001pp (t 0.0); core goods sd +0.084pp (0.043, t 1.9);
PPI sd -0.042pp (t -0.4); gap sd 0.00pp (t 0.0); mean max(0, EMA) 0.056 vs 0.126, -0.071 (0.018, t -4.0).
