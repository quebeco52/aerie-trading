# insurer_capital evidence

## 2026-10-12: insurer cash backing split + NAIC #440 ceiling + taxed divestiture gains, stage 8

Question: does the uncommitted change (manager cash bias scales only the discretionary buffer, insurer target = float
+ 5% of operating base; NAIC #440 cap on insurer div+bb; divestiture gains taxed) stop SAFE over-capitalising and keep
its book written, without breaking other firms?

Harness: `FullMarketHarnessTest.php` (margin_ratchet fork + style, NGDP, float, surplus, written/observed share,
event counts). Arms: `before` = HEAD 1df07df snapshot (scratchpad ic-before), `after` = working tree. Live macro, no
politics, 30 years, 360 ticks/yr, seeds 1-8 paired. Run: see RUN.md; table in `stage8.out`.

    quantity                                         before      after      diff       se      t
    SAFE log chg equity/NGDP (seed -> y30)            0.210      0.242     0.032    0.044    0.7
    SAFE EBIT/rev y10+ (%)                            1.811      2.170     0.359    0.439    0.8
    SAFE written share y10+                           0.708      0.696    -0.011    0.051   -0.2
    SAFE observed share y10+                          0.538      0.543     0.004    0.012    0.4
    SAFE buybacks/yr, % seed eq (real)                2.561      3.172     0.611    0.892    0.7
    SAFE (div+bb)/NI                                  0.662      0.725     0.063    0.040    1.6
    SAFE treasury / float                             1.191      1.235     0.044    0.005    8.7
    DOVE log chg equity/NGDP                          0.508      0.556     0.049    0.049    1.0
    DOVE EBIT/rev y10+ (%)                           -0.102     -1.506    -1.404    1.366   -1.0
    DOVE written share y10+                           0.850      0.796    -0.053    0.023   -2.3
    DOVE (div+bb)/NI                                  0.745      0.716    -0.029    0.048   -0.6
    DOVE treasury / float                             1.186      1.225     0.039    0.005    8.3
    failures/run (bk+reorg)                           0.625      0.375    -0.250    0.250   -1.0
    divestitures/run                                 22.875     26.500     3.625    2.822    1.3
    median ex-fin EBIT-margin drift pp               -0.223     -0.006     0.217    0.267    0.8

Findings: no target moves at 8 seeds. SAFE's equity/NGDP still rises ~0.2-0.24 log points over 30 years in both
arms; the 2-se interval on the difference [-0.06, +0.12] excludes any sizeable reduction. Treasury/float RISES ~4pp
in both insurers (t ~8.5), opposite to the intent: non-fortress managers (bias < 1) used to scale the float backing
down; now backing is fixed at 100% of float plus the buffer. SAFE end equity/quarterly revenue: before 3.0-7.0
(two seeds with a shrinking book, rev/NGDP 0.61-0.64), after 3.3-4.5: the tail looks narrower, but a variance
claim needs 48 seeds. No NaN, negative equity or buyback > 10% of equity in any quarter.

Caveats: SAFE's manager style diverges across arms on the same seed (succession is path-dependent): before 3/8 seeds
fortress throughout y10+, after 5/8. The live macro also drifts apart between arms. Statutory surplus is logged
(`ss`), but whether the NAIC ceiling binds was not tabulated.
Process note: the first `before` run used the stale margin_ratchet snapshot (00ead0c) because a sed edit to run.sh
failed silently. It showed a spurious ex-fin margin drift of -2.0pp (t -6). Rerun on the 1df07df snapshot; the table
above is from the rerun.

## 2026-10-12: fix C (premium = min(D x written share, 1.5 S), D on trend NGDP x fixed franchise share), stage 8

Question: does fix C cap SAFE's equity growth by anchoring premium to trend-NGDP demand, and does a capital shortage
now start hard markets, without breaking DOVE or the board? Arms: `before` = ds-after snapshot, `after` = c-after
snapshot (RUN.md section of the same date). Live macro, no politics, 30 y, 360 tpy, seeds 1-8 paired. Table: `fixc_stage8.out`.

    quantity                                         before      after      diff       se      t
    SAFE log chg equity/trend NGDP                    0.172      0.084    -0.088    0.029   -3.1
    SAFE log chg premium/trend NGDP                  -0.082     -0.236    -0.154    0.110   -1.4
    SAFE hard-market share y10+                       0.058      0.044    -0.014    0.054   -0.3
    SAFE EBIT/rev y10+ (%)                            2.669      3.046     0.377    0.399    0.9
    SAFE written share y10+                           0.691      0.759     0.068    0.010    6.8
    SAFE (div+bb)/NI y10+                             0.754      0.702    -0.052    0.016   -3.2
    SAFE equity / annual premium, end                 1.003      1.060     0.057    0.108    0.5
    DOVE log chg equity/trend NGDP                    0.507      0.127    -0.380    0.024  -16.2
    DOVE log chg premium/trend NGDP                   0.297     -0.213    -0.510    0.037  -13.8
    DOVE written share y10+                           0.795      0.831     0.036    0.010    3.7
    DOVE equity / annual premium, end                 0.872      0.998     0.125    0.027    4.6
    failures/run (bk+reorg)                           0.750      0.375    -0.375    0.263   -1.4
    median ex-fin EBIT-margin drift pp               -0.306     -0.184     0.121    0.463    0.3
    insurers stuck hard / premium halved              0       0

Findings: in the after arm the capacity cap never binds. 1.5 S / D sits at 1.0-1.2 (a permanent glut), so premium =
D x written share. Written share falls from ~0.92 at the open to ~0.7-0.75 in the soft market, and premium/trend NGDP
falls with it by ~0.25 log points (SAFE), the same for DOVE. Equity/NGDP growth roughly halves for SAFE and falls ~4x
for DOVE. Equity/annual premium ends ~1.0-1.06 against the Kenney 0.67, so capital is still ~1.6x what the book written
needs. Hard markets stay rare (~5% of quarters) in both arms. No NaN, negative equity or runaway buybacks.
Stop rule: targets 1-3 are not past |t| 4 and target 3 is not tight; stopped at 8 for the caller's decision.

## 2026-10-12: fix C + insurer capital target (c2-after) vs ds-after, stage 8

Question: with each insurer steering to its opening premium-to-surplus (1.5 x opening written share) through
capitalTargetRepurchase, does SAFE keep its premium in line with trend NGDP, stop accumulating equity, and pay out?
Arms: `before` = ds-after (runs reused from the fix C stage), `c2` = c2-after; ctx = c-after (fix C alone). Live
macro, no politics, 30 y, 360 tpy, seeds 1-8 paired. Table: `fixc2_stage8.out`.

    quantity                                         before         c2      diff       se      t   fixC
    SAFE log chg premium/trend NGDP (T1)             -0.082     -0.063     0.018    0.104    0.2   -0.236
    SAFE log chg equity/trend NGDP (T2)               0.172     -0.019    -0.191    0.032   -6.0    0.084
    SAFE (div+bb)/NI y10+ (T3)                        0.754      0.842     0.088    0.018    5.0    0.702
    SAFE hard-market share y10+                       0.058      0.062     0.005    0.023    0.2    0.044
    SAFE EBIT/rev y10+ (%)                            2.669      5.293     2.624    0.459    5.7    3.046
    SAFE written share y10+                           0.691      0.859     0.168    0.014   12.0    0.759
    SAFE equity / annual premium, end                 1.003      0.798    -0.205    0.101   -2.0    1.060
    SAFE NAIC ceiling binds, share y10+               0.002      0.533     0.531    0.017   30.6    0.000
    DOVE log chg premium/trend NGDP                   0.297     -0.047    -0.344    0.045   -7.6   -0.213
    DOVE log chg equity/trend NGDP                    0.507      0.003    -0.503    0.037  -13.8    0.127
    DOVE (div+bb)/NI y10+                             0.749      0.868     0.119    0.020    5.8    0.711
    DOVE hard-market share y10+                       0.048      0.080     0.031    0.033    0.9    0.036
    DOVE EBIT/rev y10+ (%)                           -0.483      5.016     5.499    0.600    9.2    0.557
    DOVE written share y10+                           0.795      0.946     0.151    0.010   14.5    0.831
    DOVE NAIC ceiling binds, share y10+               0.019      0.570     0.552    0.033   16.5    0.002
    failures/run (bk+reorg)                           0.750      0.500    -0.250    0.250   -1.0    0.375
    median ex-fin EBIT-margin drift pp               -0.306     -0.178     0.127    0.337    0.4   -0.184

Findings: T2 and T3 pass |t| >= 4. T1: c2 holds SAFE premium/trend NGDP flat (c2 range -0.11..+0.02, against fix C
alone at -0.24), but the before arm's spread (-0.59..+0.19) makes the paired se 0.10, so it is neither past t 4 nor
tight. Premium/surplus runs ~1.25-1.35 against targets of 1.36-1.39. The NAIC ceiling binds in ~55% of y10+ quarters
and throttles the repurchase, so equity/premium ends at 0.80 against the target's ~0.73. Seeds with hard-market share
> 25%: DOVE seed 1 (0.30; also above 25% in before), none for SAFE in c2. No NaN, negative equity or runaway
buybacks; no insurer stuck. NAIC binding is approximated: div+bb >= 98% of max(10% surplus, trailing-4q NI)/4.
