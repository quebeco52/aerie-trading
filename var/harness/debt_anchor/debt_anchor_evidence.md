# Debt anchor evidence

## 2026-10-12: where gross debt settles, after arm only (4 seeds x 100y, 360 tpy)

Question: with the Bohn rule on gross debt (0.10 x (debt - 0.70), two-sided) and net taxes giving back the policy
levies, where does gross debt/GDP settle (to reset INITIAL_DEBT_TO_GDP, now 0.93)?
Harness: debt_anchor/FullMarketHarnessTest.php (fork, + nfl/tariff/y10/levies), POLITICS=1, FISCAL=1, live macro.
Run: `bin/harness-batch var/harness/debt_anchor/run.sh 1-4 after && python3 var/harness/debt_anchor/analyse.py after 1-4`.
~9 min per seed-century, 540 s wall for 4 seeds. Table: `after_s4_y100.out`.

    seed med30+ mean30+   d50  d100 slope20+(pp/dec) nfl100 fund50 fund100 crises tarQ%
       1  0.718   0.727 0.765 0.659  -1.09          -1.050  0.491  1.709      2  91.5
       2  0.717   0.722 0.707 0.757  -2.03           0.080  0.505  0.677      4   8.0
       3  0.722   0.749 0.699 0.747  -1.79          -1.573  1.085  2.303      5  36.0
       4  0.746   0.747 0.771 0.671  +0.14          -0.088  0.457  0.757      6  26.5
    pooled y30-100: median 0.726, mean 0.736, sd 0.069 (1124 quarters); seed median y50 0.736, y100 0.709
    primary balance (+ surplus): tariff>0 +0.53pp (n=648), tariff=0 +0.42pp (n=952); levies 1.58pp in tariff quarters
    floor 0.20: 0% of quarters; >0.90: 8.2% (68 of 132 such quarters in the first 10y, the 0.93 start; rest post-crisis)
    ranges: debt 0.614..1.150, net_tax 0.081..0.126, spread 0..0.0006, y10 0.010..0.093, u 0.033..0.086; 0 NaN/negatives

Caveats: 4 seeds, after arm only. Starting at 0.93 biases y30-100 up (seeds 2, 3 first fell below 0.75 at y38, y44);
slopes are still negative y20-100. Crises (2-6 per century) lift debt and the rule brings it back.
Fund/GDP still grows to 1.7 and 2.3 in seeds 1 and 3 with gross debt anchored, so fund growth is no longer debt
banking. The source (fund return retention or contributions) was not measured.

## 2026-10-12: paired stage 8, before (HEAD, opening 0.93) vs after (working tree, opening 0.72)

Harness fork now also sums fund flows per quarter (f_draw, f_stab, f_inflow, f_stamp) and logs f_ret_idx.
Run: `bin/harness-batch var/harness/debt_anchor/run.sh 1-8 before after && python3 var/harness/debt_anchor/paired.py 1-8`
(1200 s wall). Targets named before the run: |slope| y20-100, tariff surplus, log fund/GDP y100. Table `paired_s8_y100.out`:

    paired before vs after, seeds 1-8, 100y, 360 tpy, POLITICS=1
    quantity            unit   before    after     diff      se      t  n
    slope             pp/dec    3.086    0.962   -2.124   0.632  -3.36  8
    tariff_surplus    pp GDP    1.388    0.106   -1.282   0.111 -11.60  7
    log_fund100          log   -0.591    0.133   +0.724   0.417  +1.74  8
    fund100            x GDP    0.682    1.436   +0.754   0.384  +1.96  8
    debt30             x GDP    0.795    0.728   -0.067   0.082  -0.82  8
    debt30_sd          x GDP    0.105    0.064   -0.041   0.011  -3.62  8
    gt090                % q   46.562    2.875  -43.688  11.839  -3.69  8
    floor                % q    0.000    0.000   +0.000   0.000   +nan  8
    gap                    %   -0.194   -0.141   +0.053   0.189  +0.28  8
    u                      %    4.563    4.549   -0.014   0.189  -0.07  8
    y10                    %    4.627    4.753   +0.126   0.164  +0.77  8
    spread                bp    0.186    0.039   -0.147   0.146  -1.01  8
    net_tax            % GDP   11.238   10.892   -0.346   0.172  -2.01  8
    -- side checks / context --
    tariff_q             % q   29.250   30.812   +1.563   5.525  +0.28  8
    primary           pp GDP    0.476    0.296   -0.180   0.080  -2.24  8
    
    per seed (before / after): slope | tariff surplus | fund100 | debt30
      1:  4.92/ 1.04 |  +1.44/ -0.29 |  1.15/ 2.30 | 0.898/0.748
      2:  0.41/ 0.27 |  +1.97/ +0.45 |  0.19/ 0.95 | 0.860/0.698
      3:  3.96/ 0.07 |  +1.05/ -0.38 |  0.37/ 1.18 | 0.861/0.694
      4:  3.49/ 1.33 |  +1.19/ -0.01 |  0.22/ 1.59 | 0.434/0.717
      5:  0.71/ 1.21 |  +1.02/ +0.13 |  0.86/ 1.12 | 1.005/0.693
      6:  5.54/ 2.42 |  +1.87/ +0.85 |  0.60/ 0.79 | 0.474/0.764
      7:  4.75/ 1.12 |  +1.18/ -0.00 |  1.38/ 0.24 | 0.816/0.760
      8:  0.90/ 0.23 |   +nan/ +1.49 |  0.68/ 3.32 | 1.010/0.747
    
    fund/GDP change over the run, mean over seeds (x GDP): return-less-growth, budget inflow, stamp duty, -(draw+stab), residual = total
      before   +1.211  +0.000  +0.257  -1.203  +0.006 =  +0.270
      after    +2.378  +0.000  +0.263  -1.628  +0.005 =  +1.018
    NaN cells: before 0, after 0

Fund rates, quarters pooled over 8 seeds: before return 7.05%/y, nominal g 4.26, r-g 2.78, outflow (draw+stab)/fund 3.07%/y;
after return 7.57, g 4.27, r-g 3.30, outflow/fund 2.23%/y. The decomposition closes (residual +0.005). Budget inflow is 0 in both arms.
Fund growth is r-g exceeding the payout rate. The after arm pays out less per unit of fund; the cause was not traced.
Stopped at 8: tariff surplus |t| 11.6; slope t -3.36 and log fund t +1.74 are below 4 and not negligible. Tariff surplus n=7
(seed 8 before had no tariff quarters).

## 2026-10-12: paired stage 16 (seeds 1-16), before vs after, + payout split

Added columns draw_law, draw_share (applied), council_line (DebtBrake::councilLine of the live politics state); seeds 9-16
only carry them. Run: `bin/harness-batch var/harness/debt_anchor/run.sh 9-16 before after && python3 var/harness/debt_anchor/paired.py 1-16
&& python3 var/harness/debt_anchor/payout.py 1-16` (1234 s wall). Tables `paired_s16_y100.out`, `payout_s16_y100.out`.

    paired before vs after, seeds 1-16, 100y, 360 tpy, POLITICS=1
    quantity            unit   before    after     diff      se      t  n
    slope             pp/dec    4.186    0.700   -3.485   0.856  -4.07 16
    tariff_surplus    pp GDP    1.478    0.056   -1.422   0.122 -11.66 15
    log_fund100          log   -0.176    0.101   +0.277   0.258  +1.07 16
    fund100            x GDP    1.025    1.358   +0.333   0.242  +1.37 16
    debt30             x GDP    0.826    0.735   -0.091   0.052  -1.75 16
    debt30_sd          x GDP    0.139    0.061   -0.078   0.018  -4.44 16
    gt090                % q   51.953    2.984  -48.969   6.532  -7.50 16
    floor                % q    0.500    0.000   -0.500   0.500  -1.00 16
    gap                    %   -0.224   -0.242   -0.019   0.126  -0.15 16
    u                      %    4.581    4.651   +0.070   0.126  +0.56 16
    y10                    %    4.702    4.638   -0.064   0.110  -0.59 16
    spread                bp    0.113    0.039   -0.074   0.073  -1.02 16
    net_tax            % GDP   11.211   10.718   -0.494   0.126  -3.90 16
    -- side checks / context --
    tariff_q             % q   35.812   40.438   +4.625   4.161  +1.11 16
    primary           pp GDP    0.609    0.274   -0.334   0.110  -3.04 16
    
    per seed (before / after): slope | tariff surplus | fund100 | debt30
    
    fund/GDP change over the run, mean over seeds (x GDP): return-less-growth, budget inflow, stamp duty, -(draw+stab), residual = total
      before   +1.980  +0.006  +0.275  -1.651  +0.005 =  +0.615
      after    +2.316  +0.000  +0.278  -1.656  +0.004 =  +0.942
    NaN cells: before 0, after 0

    fund payout split, seeds 1-16, 100y, 360 tpy
    quantity         unit   before    after     diff      se      t   n
    draw_fund         %/y    2.144    2.134   -0.010   0.005  -1.87  16
    stab_fund         %/y    0.552    0.469   -0.084   0.537  -0.16  16
    stab_gdp         pp/y    0.113    0.084   -0.030   0.055  -0.54  16
    above090          % q   51.953    2.984  -48.969   6.532  -7.50  16
    draw_law        share    0.507    0.508   +0.001   0.002  +0.38   8
    draw_share      share    0.507    0.508   +0.001   0.002  +0.38   8
    above_line        % q   62.438    5.594  -56.844   6.196  -9.17   8
    line            x GDP    0.871    0.871   +0.000   0.000   +nan   8
    applied draw share, before: 0.50: 89.7%, 0.51: 2.2%, 0.52: 0.6%, 0.54: 0.1%, 0.55: 0.4%, 0.56: 0.1%, 0.57: 0.9%, 0.58: 0.8%, 0.59: 2.2%, 0.60: 2.9% (n=3200 q)
    applied draw share, after: 0.50: 90.2%, 0.51: 1.4%, 0.54: 0.1%, 0.55: 0.5%, 0.56: 0.1%, 0.57: 0.2%, 0.58: 1.6%, 0.59: 1.6%, 0.60: 4.2% (n=3200 q)

Stage 8 payout gap (3.07 vs 2.23%/y) did not hold: total draw+stab over the run -1.651 vs -1.656. Draw/fund equal
(2.14%/y), stabilisation noise only. Fund grows in BOTH arms because fund return less nominal growth (~3pp/y) exceeds
the ~2.1%/y draw at the 0.50 share. That is pre-existing, not caused by the change. Fund y100 difference t +1.1, inside noise.
Stopped at 16 as instructed: T1 |t| 4.1, T2 |t| 11.7, T3 inconclusive (t 1.1).
