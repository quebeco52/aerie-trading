# Leverage evidence

## 2026-10-11 Net-leverage recap target (L0 gross vs L1 net of idle cash)

Question: does netting idle cash out of the recap leverage ratio move operating firms toward the trade-off target,
without raising failures or emergency equity?
Harness: `LeverageHarnessTest.php` via `run.sh` (see RUN.md); SNAP levtree (no fix) vs ovrL (five fixed files).
48 paired seeds x 20 years, TPY 360, politics on. Staged 8 -> 16 -> 48 (stopped neither at 8 nor 16: BBB share
t 1.4/3.8 and ICR y19 guard t -3.5 at 16). All 96 runs ok. Table: `stage48.out` (also `stage8.out`, `stage16.out`).

| quantity | L0 | L1 | diff | se | t |
|---|---|---|---|---|---|
| nd/EBITDA y9 | 0.775 | 1.089 | +0.314 | 0.017 | 18.9 |
| nd/EBITDA y19 | 0.748 | 1.020 | +0.272 | 0.023 | 11.7 |
| net-cash share y9 | 0.131 | 0.040 | -0.091 | 0.007 | -12.3 |
| net-cash share y19 | 0.134 | 0.043 | -0.090 | 0.008 | -11.1 |
| BBB or below y9 | 0.270 | 0.351 | +0.081 | 0.011 | 7.1 |
| BBB or below y19 | 0.252 | 0.322 | +0.070 | 0.012 | 5.6 |
| median ICR y9 | 14.36 | 12.92 | -1.44 | 0.61 | -2.4 |
| median ICR y19 | 14.61 | 11.49 | -3.12 | 0.54 | -5.8 |
| median P/E y9 / y19 | 15.03 / 14.39 | 14.94 / 14.29 | -0.09 / -0.11 | 0.53 / 0.55 | ~0 |
| failures / seed | 0.042 | 0.042 | 0 | 0.042 | 0 |
| revolver draws / seed | 6.48 | 6.69 | +0.21 | 0.74 | 0.3 |
| emergency equity / seed | 13.17 | 14.96 | +1.79 | 1.66 | 1.1 |
| payment defaults / seed | 0.021 | 0.042 | +0.021 | 0.036 | 0.6 |

Caveats: L1 lands at ~1.0-1.1x median net debt/EBITDA, inside the S&P 500 benchmark band (1.0x 2011, 1.35x 2013,
~1.5x 2016). The ICR fall is the expected mechanical consequence of more debt (still ~11.5x median). Payment
defaults are counted from newsworthy-filtered treasury news lines.


## 2026-10-11: credit spreads on rating classes (C7 vs C0), pinned seeds, 48 seeds

Question: does the JLT rating-class spread curve (macro IG/HY index, LGD scaling, seeded spread kept as a firm multiple)
make spreads follow ratings without raising failures or moving valuations?
Harness: LeverageHarnessTest.php via run.sh; arms C0 = $SNAP/cstree2 (HEAD + other uncommitted work, the 12 spread files
at HEAD), C7 = OVR $SNAP/ovr7 (the 12 files as in the working tree). SNAP = session scratchpad cs/ (not persistent).
48 paired seeds x 20 y, TPY 360, POLITICS=1, POLITICS_SEED derived from SEED. Determinism checked first
(detcheck.sh C0 1 3: two runs identical apart from `secs`). Old unpinned C0/C7 runs moved to runs/old_unpinned/.
Stages 8, 16 ran on: failures unresolved (0 vs 0 at 8, 0 vs 0.06 at 16). Full tables: spread_s8/s16/s48.out.

| quantity (years 2-19 unless noted) | C0 | C7 | diff (se) | paired t | Welch t |
|---|---|---|---|---|---|
| op rating-spread Spearman | 0.074 | 0.669 | +0.596 (0.010) | 58.1 | 64.4 |
| op median spread A, bp | 100.4 | 113.6 | +13.2 (1.4) | 9.2 | 9.9 |
| op median spread BBB, bp | 101.6 | 170.2 | +68.6 (2.2) | 31.2 | 33.4 |
| fin AAA share | 0.386 | 0.000 | -0.386 (0.005) | -84.9 | -84.9 |
| failures per seed | 0.000 | 0.208 | +0.208 (0.066) | 3.14 | 3.14 |
| median P/E | 14.71 | 14.81 | +0.10 (0.23) | 0.42 | 0.40 |
| median ICR | 11.20 | 11.15 | -0.05 (0.19) | -0.28 | -0.27 |
| revolver draws per seed | 6.71 | 11.92 | +5.21 (0.96) | 5.43 | 5.53 |
| payment defaults per seed | 0.000 | 0.167 | +0.167 (0.069) | 2.42 | 2.42 |
| SWAN spread, bp | 52.7 | 247.0 | +194.3 (2.8) | 68.5 | 70.8 |
| TIER spread, bp | 73.3 | 519.9 | +446.6 (6.3) | 70.9 | 72.3 |
| SAFE spread, bp | 36.0 | 239.9 | +203.9 (3.1) | 66.5 | 67.5 |
| net debt/EBITDA y19 | 1.020 | 1.022 | +0.002 (0.018) | 0.11 | 0.10 |
| macro HY median, bp | 445.8 | 449.1 | +3.3 (6.9) | 0.48 | 0.52 |

Failures (failures.py C0 C7): C0 none. C7 10 in 9 seeds: FALC (auto) reorganised 5x at CCC (seeds 16, 24, 26, 34,
years 1.9-19.9) and EIDR (auto) once (seed 23, y3.6, CCC); TIER (private equity) bankrupt 5x (seeds 24, 29, 32, 36, 46,
years 9-19), rated B in four, CCC in one. No PLZA failure.
Caveats: fin AAA/AA vanish entirely in C7 (all to A), a rating-side change, not only a spread one. Emergency equity
raises +3.7/seed (t 2.8). The failure t of 3.1 is below 4 at the 48-seed ceiling, but 10 vs 0 events in the same seeds.
