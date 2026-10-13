# Output-gap floor evidence

## 2026-10-13: how often does the -12% floor bind? (src 3f67b04)

Question: the live 27-year game hit the floor for 5 quarters (clamp ~+2.4pp total) after a credit crisis plus a large
demand disaster. Rare or regular?

Harness `gap_floor.php` (macro + politics, no equity cap, no firms), 3 seeds x 200 years, 48 ticks/year, one arm.
Output `s3_y200.out`.

| seed | quarters | floor % | ceiling % | gap sd | p1 | p5 | min gap | min pre-clamp | max pre-clamp | crises |
|---|---|---|---|---|---|---|---|---|---|---|
| 1 | 800 | 0 | 0 | 2.17 | -7.07 | -4.58 | -8.09 | -8.12 | 6.37 | 11 |
| 2 | 800 | 0 | 0 | 2.27 | -6.61 | -4.09 | -7.75 | -7.77 | 6.19 | 6 |
| 3 | 800 | 0 | 0 | 2.33 | -7.44 | -4.86 | -8.33 | -8.39 | 4.91 | 5 |
| pool | 2400 | 0 | 0 | 2.26 | -7.14 | -4.58 | -8.33 | -8.39 | 6.37 | 22 |

Zero floor or ceiling episodes in 600 seed-years, 22 credit crises, demand-disaster states down to -24pp. Worst
pre-clamp gap -8.39% is 3.6pp above the floor (CBO worst -8.8%, 2009Q2).

Caveats: no equity-wealth loop, no board (bank credit, finance output from board banks, rescue cost scaled by board
credit), 48 tpy not the live rate. The live episode needed channels this loop lacks, or a far tail.
