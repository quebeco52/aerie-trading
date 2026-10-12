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
