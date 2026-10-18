# Cycle amplitude evidence

## 2026-10-12 Baseline cycle size on HEAD a10a390 (one arm)
Question: how large are booms and busts on current code, before tuning, vs World Bank growth sd 1985-2019 (US 1.46, CH 1.64, UK 1.83, DE 1.92, JP 2.31, LU 2.93, SG 4.01; negative years per 40 US 3, financial centres 4-7; u sd US 1.59, CH 0.84, SG 0.97).
Harness: CycleAmpHarnessTest.php (RegimeHarnessTest fork + OutputGapProbe), POLITICS=1, TPY 360, 16 seeds x 45 y, burn-in 5 y, snapshot = git archive HEAD. Run: see RUN.md. Wall 562 s.

```
arm R1, n=16 seeds, years 6-45 (growth base year 5), blown/short: none
quantity                             mean      se      min      max
growth sd %                         1.384   0.070    1.137    2.359
growth mean %                       2.089   0.079    1.555    2.679
worst year %                       -1.300   0.250   -4.108    0.755
best year %                         5.110   0.182    3.838    6.583
negative years /40                  2.562   0.418    0.000    7.000
crises /40                          1.188   0.245    0.000    3.000
gap sd %                            1.563   0.085    1.060    2.429
gap min %                          -3.998   0.272   -6.620   -2.208
gap max %                           3.309   0.211    2.168    4.660
supply gap sd %                     0.993   0.061    0.569    1.719
supply share of var(gap)            0.376   0.030    0.161    0.558
net export gap sd %                 0.871   0.040    0.595    1.107
net export share of var(gap)       -0.004   0.038   -0.215    0.323
finance gap sd %                    0.724   0.046    0.396    1.169
finance share of var(gap)           0.179   0.027    0.017    0.447
u annual sd pp                      0.839   0.055    0.534    1.309
u max %                             6.796   0.194    5.568    8.393
u mean %                            4.602   0.108    4.066    5.423
Sahm starts /40                     6.750   0.403    4.000   10.000
policy at floor share               0.004   0.003    0.000    0.044
balance sheet on share              0.148   0.029    0.000    0.412
d(gap) quarterly sd %               0.442   0.019    0.357    0.672
post_crisis_worst % (pooled, n=19)    0.245   0.271   -2.255    1.642
quiet_worst % (pooled, n=16)       -0.998   0.255   -4.108    0.755

gap channels, quarterly: sd of contribution (% of potential) and cov(contribution, d gap)/var(d gap), seed means
channel                          sd %  cov share      se
fundStabilisation              0.4824      0.359   0.019
monetaryDrag                   0.1680      0.132   0.011
productivitySupply             0.1597      0.121   0.015
automaticStabiliser            0.5345     -0.111   0.025
netExports                     0.1595      0.110   0.012
financeOutput                  0.1161      0.108   0.007
demandDisaster                 0.3915      0.096   0.024
premiumDrag                    0.1115      0.076   0.013
householdDeleveragingDrag      0.0983      0.071   0.005
diffusion                      0.0982      0.047   0.006
crisisDeleveragingDrag         0.2303      0.044   0.019
demandShock                    0.2024      0.038   0.023
householdNewBorrowing          0.4288     -0.035   0.019
fiscalStimulus                 0.2435     -0.024   0.014
inventoryDrag                  0.0891     -0.022   0.005
capitalDrag                    0.0198      0.015   0.001
cubicConstraint                0.0650     -0.013   0.004
housingWealthEffect            0.1149     -0.011   0.008
energySupplyDrag               0.0524     -0.005   0.004
momentum                       0.0214      0.004   0.001
catastropheSupplyDrag          0.0343      0.004   0.003
equityWealthEffect             0.0492     -0.004   0.005
policyUncertaintyDrag          0.0133     -0.003   0.001
lendingStandardsDrag           0.0596      0.002   0.005
creditFrictionDrag             0.0422     -0.001   0.002
freightSupplyDrag              0.0050      0.000   0.000
disasterCompensator            0.0009      0.000   0.000
premiumCompensator             0.0000      0.000   0.000
crisisCompensator              0.0000      0.000   0.000
clamp                          0.0000      0.000   0.000
sum of cov shares                          1.000  (1 minus unexplained)
```
Caveats: post-crisis worst is a min over 3 years per crisis, quiet worst a min over ~30 years per seed, so they are not like for like. Sahm starts counted on quarterly samples. fundStabilisation has the largest positive cov share with the quarterly gap change (0.36): it moves with gap changes, not against them; worth a look.

## 2026-10-12 Fund countercyclical spending off (R2 vs R1)
Question: how much does CreditFiscalSubsystem::calculateFundStabilisation (Norway reaction function) shrink booms and busts?
Arm R2 = R1 (HEAD a10a390) with MacroEngine::FUND_STABILISATION_GAP_RESPONSE = 0.0 via $SNAP/ovr2; verified sovereignFundStabilisationToGdp (row `fstab`) = 0 in every R2 row. POLITICS=1, TPY 360, 16 seeds x 45 y, burn 5; R1 reused from cache. Run: RUN.md (R2 line), `python3 compare.py R1 R2`. Wall 577 s.
Targets named before the run: growth sd, negative years /40, crisis gap fall.

```
R2 minus R1, paired by seed, years 6-45, seeds ok 16/16 (short: none)
quantity                         R1       R2     diff      se      t   n
-- targets
growth sd %                   1.384    1.590    0.206   0.134   1.54  16
negative years /40            2.562    4.375    1.812   0.720   2.52  16
crisis gap fall pp            2.657    3.171    0.003   0.442   0.01  10  (arm n 12/13)
-- guards
gap sd %                      1.563    2.212    0.648   0.233   2.78  16
gap min %                    -3.998   -5.914   -1.915   0.760  -2.52  16
u annual sd pp                0.839    1.398    0.559   0.161   3.47  16
u max %                       6.796    8.672    1.876   0.635   2.95  16
Sahm starts /40               6.750    6.188   -0.562   0.456  -1.23  16
balance sheet on share        0.148    0.214    0.065   0.057   1.15  16
policy at floor share         0.004    0.054    0.051   0.014   3.65  16
inflation mean %              2.131    2.230    0.099   0.035   2.87  16
end fund/GDP                  0.681    0.778    0.097   0.100   0.96  16
end gross debt/GDP            0.577    0.627    0.050   0.036   1.37  16

R2 gap channels, top 8:
channel                          sd %  cov share      se
monetaryDrag                   0.2147      0.193   0.014
demandDisaster                 0.3546      0.143   0.032
financeOutput                  0.1230      0.120   0.008
crisisDeleveragingDrag         0.2712      0.118   0.032
productivitySupply             0.1502      0.099   0.013
premiumDrag                    0.1250      0.094   0.012
householdDeleveragingDrag      0.1170      0.089   0.007
netExports                     0.1524      0.082   0.015
```
Caveats: crisis gap fall pairs only seeds with a crisis in both arms (10), and crisis dates differ between arms, so that row is close to unpaired; arm means 2.66 vs 3.17 pp. Growth sd t=1.5 at the 16-seed cap: +15%, not negligible, unresolved. In R1 the fund channel has cov share +0.36 with the quarterly gap change: its annual budget-round steps are themselves a source of quarterly gap moves even though levels show it damps the cycle.

## 2026-10-13 Mainland AR(2) refit 1985-2019 (R3 vs R1)
Question: does the mainland gap refit (AR1 1.197->1.235, AR2 -0.296->-0.300, sigma 0.00503->0.00490; stationary sd 1.37%->1.65%) widen the District cycle?
Arm R3 = R1 (HEAD a10a390) + working-tree AssetMarketSubsystem.php via $SNAP/ovr3 (diff = the 3 constants and docblocks). POLITICS=1, TPY 360, 45 y, burn 5, seeds 1-16 (stage 8 then 16; no target reached |t| 4). R1 from cache. Run: RUN.md (R3 line), `python3 pairhp.py R1 R3`. Wall ~9 min.
Targets named before the run: HP(6.25) cycle sd of annual real GDP, negative years /40, quarterly gap sd.

```
R3 minus R1, paired by seed, seeds ok 16/16 (short: none)
quantity                       R1       R3     diff      se      t   n
-- targets (cycle min is a reported extra)
HP6.25 cycle sd %           0.841    0.790   -0.050   0.081  -0.62  16
HP6.25 cycle min %         -1.937   -1.645    0.292   0.211   1.38  16
negative years /40          2.562    2.500   -0.062   0.595  -0.11  16
gap sd % (quarterly)        1.563    1.421   -0.142   0.131  -1.09  16
-- guards
u annual sd pp              0.839    0.744   -0.095   0.086  -1.10  16
policy at floor share       0.004    0.005    0.002   0.006   0.26  16
inflation mean %            2.131    2.206    0.075   0.037   2.06  16
nx sd %                     0.871    1.013    0.142   0.075   1.89  16
mainland gap sd %           1.288    1.571    0.283   0.077   3.67  16
growth sd %                 1.384    1.311   -0.073   0.105  -0.69  16
```
Caveats: snapshot rebuilt 10-13 (old scratchpad gone; cache-catree deleted). 4 stage-1 logs (seeds 1,5,6,8) showed one PHPUnit warning; a rerun of seed 1 with --display-warnings gave none and a bit-identical macro path, so it was the cold container cache, not the model. Mainland gap sd rises 0.28pp (t 3.7) as designed, yet no District cycle target moves past |t| 1.4: the mainland channel is not what limits cycle amplitude. Inflation +0.08pp (t 2.1) and nx sd +0.14pp (t 1.9) are guards, at the chance rate for 7 guards.

## 2026-10-13 Swiss trade openness (R4 vs R3)

Question: does the Swiss openness change (exports 0.30 -> 0.662 of GDP, baseline trade balance -2.5% -> +9.3%,
value-added export / domestic-use import shares in netExportGapAt, EXCESS_IMPORT_LEAKAGE and FOREIGN_IMPORT_SPILLOVER,
clamps baseline +-0.055) change cycle size or the District's loading on the mainland gap?
Arm R4 = R3 + working-tree MacroEngine.php, AssetMarketSubsystem.php, MacroAggregateSubsystem.php, MacroTransmission.php
via $SNAP/ovr4 (diffed vs the snapshot: only the listed changes, plus the tariff-retaliation term in netExportGapAt also
scaled by domestic content). POLITICS=1, TPY 360, 45 y, burn 5, seeds 1-16 (stage 8 then 16; no target reached |t| 4).
R3 from cache. Run: RUN.md (R4 line), `python3 pairtrade.py R3 R4`. Wall ~4 min per 8-seed stage.
Targets named before the run: HP6.25 cycle sd (+min), beta/corr of gap on fgap, quarterly gap sd.
```
R4 minus R3, paired by seed, seeds ok 16/16 (short: none)
quantity                       R3       R4     diff      se      t   n
-- targets (cycle min is a reported extra)
HP6.25 cycle sd %           0.790    0.822    0.031   0.068   0.46  16
HP6.25 cycle min %         -1.645   -1.851   -0.206   0.196  -1.05  16
beta gap on fgap            0.256    0.369    0.113   0.080   1.41  16
corr gap, fgap              0.282    0.375    0.092   0.087   1.06  16
gap sd % (quarterly)        1.421    1.599    0.178   0.132   1.35  16
-- guards (rest = gap - nx - supply - fin)
negative years /40          2.500    3.062    0.562   0.764   0.74  16
u annual sd pp              0.744    0.906    0.162   0.113   1.43  16
inflation mean %            2.206    2.227    0.021   0.033   0.62  16
policy at floor share       0.005    0.005    0.000   0.007   0.06  16
nx sd %                     1.013    1.382    0.369   0.117   3.15  16
tb mean %                  -1.950    9.759   11.709   0.182  64.35  16
tb at clamp share           0.000    0.000    0.000   0.000    nan  16
growth sd %                 1.311    1.372    0.060   0.098   0.62  16
fund/GDP end                0.772    0.830    0.058   0.210   0.28  16
corr nx, rest              -0.633   -0.574    0.060   0.062   0.97  16
mainland gap sd %           1.571    1.576    0.005   0.118   0.04  16
```
Caveats: at 8 seeds beta was +0.198 (t 2.36) and corr +0.191 (t 2.10); both halved at 16 (stage-8 t's of 2-3 are
unreliable). All three targets inside noise at 16; every point estimate points to a slightly larger, more mainland-loaded
cycle. Trade balance sits at +9.8% mean (baseline 9.3%), never at a clamp in either arm. nx sd +0.37pp (t 3.2) is the
expected mechanical effect of the larger shares. Clamp share uses R3 bounds -0.08/+0.03 and R4 bounds 0.038/0.148.

## 2026-10-13 Household DTI drift, full game (DA politics on vs DB off; DT = TPY 3600)

Question: does householdDebtToIncome drift down in the full game (live dump 1.00 -> 0.70 over 16.75 y) when the
macro-only probe (fc_crisis_drag) holds ~0.96? Harness: CycleAmpHarnessTest.php + dtiProbe (per-tick exact terms),
dti_run.sh, dti.py; HEAD ac41afe snapshot; 16 seeds x 20 y at TPY 360 per arm; DT 8 seeds x 15 y at TPY 3600.

| DTI | DA (politics) | DB (no politics) |
| y5  | 0.961 / 0.045 / 0.876 | 1.020 / 0.042 / 0.959 |
| y10 | 0.895 / 0.083 / 0.766 | 1.011 / 0.072 / 0.854 |
| y15 | 0.911 / 0.108 / 0.700 | 0.991 / 0.110 / 0.788 |
| y20 | 0.897 / 0.072 / 0.787 | 0.967 / 0.062 / 0.850 |
Paired DA-DB at y15: -0.080 (se 0.032, t -2.50, n 16). Stopped at 16 (caller's stage; 48 = ~1 h).

Log decomposition y0-15, DA | DB | paired DA-DB (se): house price -0.070 | -0.025 | -0.044 (0.034);
reversion +0.052 | -0.010 | +0.062 (0.012); DSR 0 | -0.005; charge-off +0.016 | +0.018 | -0.002 (0.007);
capital squeeze -0.032 | -0.004 | -0.028 (0.003); LTV squeeze -0.046 | 0 | -0.046 (0.013);
residual -0.020 | +0.012 | -0.032 (0.026); total -0.101 | -0.016 | -0.085 (0.035).
TPY 3600 (DT vs DA, 8 seeds): y15 0.930 vs 0.917; squeezes equal; residual -0.066 (0.025) vs -0.010 (0.032).
Quarterly-sampled reconstruction of hp/rev/dsr matches the per-tick sums within 0.004 log per seed.

Live dump 18 (q0-67) reconstructed with baseline 1.0: hp -0.094, rev +0.189, dsr 0, total -0.358, residual -0.453
before charge-off/capital/LTV. Reversion is overstated if the LTV cut was built (baseline exp(-cut)); the dump lacks
ltv_cut / mortgage_ltv_cap / bank_capital_required_last, so the live split cannot be closed.
Caveats: residual t -1.2 (DA-DB) and -2.7 (DT) are inside 1-2.7 se of noise; DA seeds 1 and 9 (0.70 at y15) fall on
house prices (hp -0.31, -0.36), not on the levers.

## 2026-10-18 Gap persistence and the policy-rate lag (A HEAD / B speed 1.4 / C speed 1.4 + gap weight 1.0)
Question: at HEAD 51b1d2c2 (8 meetings a year, 25bp steps) is the output gap too persistent, and how much is the rate
trailing its rule? Live dump: gap acf4 0.74, acf8 0.35, mean spell 18q, rate peaks 7q behind the gap.
Harness `PersistenceHarnessTest.php` + `pers_run.sh` (see RUN.md), live macro, whole board, POLITICS=1, TPY 360,
33 years with years 0-2 dropped (124 quarters a seed). Seeds 1-16 per arm, paired. Staged 8 -> 16 (gap acf4/acf8/spell
not clear at 8). 48 runs, ~22 min wall. No blow-ups. Table `pers_s16.out` (excerpt):

    quantity        A       B       C       B-A (se) t           C-A (se) t
    gap_acf4        0.577   0.620   0.644   +0.043 (0.036) +1.2   +0.067 (0.051) +1.3
    gap_acf8        0.166   0.297   0.279   +0.131 (0.046) +2.8   +0.114 (0.077) +1.5
    gap_spell_q    11.88   12.82   13.83    +0.95 (1.50) +0.6     +1.96 (1.42) +1.4
    hp_ar1          0.913   0.887   0.905   -0.026 (0.010) -2.5   -0.008 (0.009) -0.9
    hp_acf4         0.227   0.147   0.205   -0.079 (0.038) -2.1   -0.022 (0.047) -0.5
    g_ar1           0.731   0.671   0.688   -0.060 (0.022) -2.7   -0.042 (0.022) -1.9
    lag_gap_k (q)   4.56    2.31    2.63    -2.25 (0.40) -5.6     -1.94 (0.36) -5.4
    lag_rule_k (q)  3.69    2.00    2.00    -1.69 (0.12) -14.1    -1.69 (0.12) -14.1
    sd_rate_rule    2.17    1.55    1.01    -0.62 (0.25) -2.5     -1.15 (0.15) -7.7
    cut4q_pp       -2.28   -3.16   -2.34    -0.88 (0.25) -3.5     -0.06 (0.15) -0.4
    gap_sd          2.03    2.01    2.03    -0.03 (0.20) -0.1     -0.01 (0.23) -0.0
    infl_mean       2.10    2.06    2.01    -0.04 (0.04) -1.1     -0.09 (0.04) -2.4
    elb_share       0.001   0.016   0.013   +0.015 (0.006) +2.5   +0.012 (0.010) +1.2

Reading: the rate's lag is set by the smoothing speed (4.6q -> 2.3q behind the gap), but the gap's persistence is not:
faster smoothing leaves gap acf4 flat and raises acf8/acf12 slightly; it trims HP-cycle and growth persistence a little.
Arm A's gap (acf4 0.58, spell 12q) is US-like; the live path (acf4 0.74, spell 18q) is a tail draw (2/16 and 1/16 seeds
at or above). HP AR1 0.91 and growth AR1 0.73 sit at or above the top of the 15-economy range in every arm.
Caveats: TPY 360 vs the live 3600; supply-gap variance share 0.26 here vs 0.40 on the live path.

## 2026-10-18 Inventory investment as a level part of GDP, with a production cost shock (A HEAD / B-E SD sweep)
Question: does inventory investment as a level part of the gap (Δg/dt, replacing the inventoryDrag rate channel) plus
an OU production cost shock (κ 2/yr) give plausible high-frequency GDP, and at what INVENTORY_COST_SHOCK_SD?
Harness `InventoryHarnessTest.php` + `inv_run.sh` + `inv_analyse.py` (see RUN.md). A = HEAD d191ee10 (CB speed 1.4);
B-E = working-tree src with SD 0 / 0.003 / 0.006 / 0.012. Live macro, whole board, POLITICS=1, TPY 360, 33 y,
years 0-2 dropped (124 q). Stage 8 (all five arms, `inv_s8.out`), then 9-16 on A-D (`inv_s16.out`: recession call
unclear at 8; E clearly out of range at 8). 72 runs, ~35 min wall. No blow-ups.

    quantity (16 seeds; E at 8)   A      B      C      D      E(8)   C-A (se)        D-A (se)        US ref
    g_ar1 (end of quarter)      0.671  0.796  0.661  0.412  0.075  -0.010 (0.019)  -0.259 (0.019)  0.37
    pub_ar1 (quarter averages)  0.740  0.831  0.765  0.601  0.365  +0.025 (0.015)  -0.139 (0.015)
    g_sd                        0.585  0.547  0.606  0.739  1.062  +0.021 (0.034)  +0.153 (0.032)  0.56
    g_skew                     -0.83  -0.45  -0.48  -0.26  -0.20   +0.35 (0.18)    +0.57 (0.20)   -1.33
    fs_g_ar1                    0.671  0.700  0.722  0.693  0.682  +0.051 (0.021)  +0.022 (0.016)  0.28
    inv level sd (% GDP)        0      0.191  0.295  0.481  0.887                                  0.40
    inv level AR1 / acf4        -      .72/-.16 .59/-.11 .51/-.10 .51/-.07                         0.65/0.15
    inv contribution sd (pp)    0      0.140  0.264  0.472  0.875                                  0.36
    inv contribution AR1        -      +0.22  -0.10  -0.18  -0.21                                  -0.25
    inv share of growth var     0     -0.012  0.133  0.378  0.702                                  0.28
    corr(fs growth, contrib)    -     -0.29  -0.14  -0.08  +0.04                                  -0.19
    var(g)/var(fs growth)       1.000  0.915  1.083  1.545  3.72                                   >= 1
    stock sd (%)                1.12   1.04   1.69   3.02   5.82
    corr(stock, final-sales gap)-0.72 -0.77  -0.49  -0.31  -0.03
    recessions / decade         1.33   1.35   1.37   1.71   2.30(8: A 1.05)  +0.04 (0.19)  +0.38 (0.22)
    hp_ar1                      0.887  0.923  0.907  0.862  0.743  +0.020 (0.011)  -0.025 (0.012)
    gap_acf4 / acf8             .62/.30 .58/.18 .64/.28 .51/.13 .42/.09

Reading: B (level part alone) makes production smoother than sales (var ratio 0.92), raises growth AR1 (+0.13, t 7)
and HP AR1; the stock buffers demand. C gives inventory moments a little below the US with var ratio > 1 and leaves
growth AR1 and every cycle guard and the recession count at A's values. D gets growth AR1 to 0.41 only by inventory
noise above the US (share 0.38, contribution sd 0.47) and +0.38 recessions/decade (t 1.8). Final-sales growth AR1
~0.70 in every arm: the excess persistence is in final sales, not inventories. Symmetric shock dilutes growth skew.
Caveats: TPY 360 (live 3600); point samples of inv at quarter end (not quarter averages).

## 2026-10-18 Inventory accelerator step 2 (A step 1 / B accel + look-through / C no look-through / D mu 0.3 / E lambda 1.6)
Question: does a target stock that follows expected sales, with the rule seeing through inventory investment, make
inventories deepen recessions without breaking the cycle? Harness InventoryHarnessTest.php via inv2_run.sh (RUN.md),
16 seeds x 33 years (first 2 dropped), TPY 360, POLITICS 1, SD 0.003 in all arms. Table: inv2_s16.out (stage 8:
inv2_s8.out). Stopped at 16 (user cap); recession targets inside noise.

| quantity | A | B | C | D | E | US | B-A (se) t |
|---|---|---|---|---|---|---|---|
| recession quarters (16 seeds) | 215 | 266 | 235 | 237 | 274 | | |
| rec GDP growth | -0.073 | -0.129 | -0.013 | -0.038 | -0.005 | -0.36 | -0.056 (0.042) -1.3 |
| rec final-sales growth | 0.037 | -0.021 | 0.099 | 0.031 | 0.134 | -0.07 | -0.058 (0.041) -1.4 |
| rec inventory contribution | -0.108 | -0.104 | -0.109 | -0.066 | -0.135 | -0.29 | +0.003 (0.025) +0.1 |
| pooled rec share sum ic/sum g | 1.57 | 0.79 | 1.70 | 1.57 | 3.56 | 0.8 | |
| slope ic on fs_g same q | -0.068 | -0.045 | -0.041 | -0.004 | +0.023 | -0.04 | +0.022 (0.015) +1.5 |
| slope ic on fs_g last q | 0.087 | 0.111 | 0.122 | 0.081 | 0.157 | +0.22 | +0.023 (0.010) +2.3 |
| g skew (end) | -0.418 | -0.317 | -0.325 | -0.259 | -0.289 | -0.29 / -1.33 | +0.101 (0.141) +0.7 |
| skew published | -0.408 | -0.321 | -0.316 | -0.243 | -0.297 | | +0.087 (0.147) +0.6 |
| overhang sd pp | 1.64 | 2.67 | 2.27 | 1.97 | 1.48 | | +1.04 (0.15) +6.8 |
| corr(overhang, dd gap) | -0.47 | -0.66 | -0.61 | -0.52 | -0.56 | | -0.19 (0.07) -2.6 |
| corr(overhang, fs gap) | -0.33 | -0.41 | -0.42 | -0.35 | -0.36 | | -0.08 (0.08) -1.0 |
| gap sd | 1.73 | 2.10 | 1.97 | 1.93 | 1.82 | | +0.37 (0.12) +3.0 |
| gap acf8 | 0.13 | 0.26 | 0.30 | 0.21 | 0.19 | | +0.13 (0.05) +2.5 |
| u sd | 0.99 | 1.26 | 1.21 | 1.12 | 1.04 | | +0.27 (0.09) +3.0 |
| longest neg gap spell q | 28.3 | 32.5 | 43.2 | 33.8 | 32.9 | | +4.3 (3.8) +1.1 |

C-B: longest negative spell +10.7 (3.5) t 3, infl sd -0.17 (0.05) t -3, overhang sd -0.41 (0.18); targets ~0.
Reproduction: A vs the 10-18 arm C (runs/IC) on seeds 1-16: inventory rows equal within noise (rec_ic -0.011 (0.022),
slopes +0.004/-0.001), but gap sd A 1.73 vs 2.12: HEAD d191ee10 raised CB_SMOOTHING_SPEED 0.65 -> 1.4 after that run.
Caveats: recession = recessionDeclared; the model's recession GDP growth is near zero so the share ratio is unstable.
