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

## 2026-10-11: rating-class spreads + sector rating tables (C8 vs C10 pre-work), cs3 snapshot, 48 seeds
Question: with rating-class spreads plus sector tables (S&P REIT / asset-manager leverage tables, insurers on capital,
market-implied ratings floored at B), do spreads follow ratings without raising failures or moving valuations?
Snapshot: `cs3tree` = `git archive HEAD` (530c3ec) with 13 src files at 7cb9efa (CreditRisk, DebtEngine, TreasuryEngine,
Reorganization, M&A, DebtMetricsDTO, CreditRatingAgency, OpeningBoardBuilder, HedgeFund/Reit models, FinancialPhysics/
StandardDebtPhysics traits, Stock). C10 = no OVR; C8 = OVR of the working-tree versions of the first 12 (Stock.php
differs HEAD vs 7cb9efa only in docblocks). Checked: ovr8 = working tree for all 12; cs3tree/src = HEAD except those 13.
Uncommitted macro WT changes are in neither arm. Run: `var/harness/leverage/cs3_stage.sh <seeds> <N>` (SNAP pinned in
it), 20 y, 360 ticks/y, POLITICS_SEED pinned. Stages 8 and 16 failed the stop rule (failures 0.25, 0.125 vs 0);
outputs `cs3_s8.out`, `cs3_s16.out`, `cs3_s48.out`.

| quantity (48 seeds, y2-19) | C10 | C8 | diff (se) | paired t | Welch t |
|---|---|---|---|---|---|
| op rank corr rating/spread | 0.074 | 0.629 | +0.555 (0.009) | 59.6 | 57.6 |
| op median spread A, bp | 100.4 | 113.7 | +13.3 (1.4) | 9.5 | 9.4 |
| op median spread BBB, bp | 101.6 | 168.4 | +66.8 (1.9) | 34.9 | 32.6 |
| fin AAA share | 0.386 | 0.000 | -0.386 | -84.9 | -84.9 |
| failures / seed | 0.000 | 0.229 | +0.229 (0.061) | 3.74 | 3.74 |
| payment defaults / seed | 0 | 0.021 | +0.021 | 1.0 | 1.0 |
| revolver draws / seed | 6.71 | 12.44 | +5.73 (1.00) | 5.74 | 5.52 |
| emergency equity / seed | 12.77 | 15.73 | +2.96 (1.52) | 1.95 | 1.87 |
| op median P/E | 14.71 | 14.79 | +0.08 (0.25) | 0.32 | 0.32 |
| op median ICR | 11.20 | 11.11 | -0.09 (0.19) | -0.48 | -0.47 |
| ND/EBITDA y19 | 1.020 | 1.012 | -0.007 (0.020) | -0.37 | -0.35 |
| macro HY median bp | 445.8 | 450.4 | +4.6 (6.6) | 0.69 | 0.69 |

C8 failures (11): TIER bankrupt 9x (seeds 6, 8, 22, 25, 27, 29, 37, 44, 48; years 12.4-19.6; last rating BB 3, B 5,
CCC 1); FALC reorg seed 28 y12.75 B; STRK bankrupt seed 47 y10.4 CCC. C10: none.
TIER: B 99% / 73 bp in C10 -> BB 97% / 311 bp in C8; revolver draws 1.5 -> 5.6/seed (most of the revolver rise).
SWAN B 95% / 53 bp -> BB 78% BBB 22% / 144 bp; SAFE B 80% / 36 bp -> A 100% / 67 bp; FALC 307 -> 229 bp.
Mix C8: insurers 100% A; REIT BBB 46 / BB 52; asset mgr A 46 / BBB 11 / BB 43; bank/broker A 75 / BBB 14 / BB 11;
fin AAA/AA vanish. Caveat: C10 spreads are flat across ratings (op rank corr 0.07, fin -0.005), so C10 is a weak
reference for spread levels. Same TIER failure pattern as the C7 run above (10 vs 0): the spread work still does it.


## 2026-10-12: credit rating stability, time-weighted leverage anchor (R0 HEAD 4b6bb2e vs R2 working tree), 16 seeds

Question: does the time-weighted leverage anchor (+ utilities on the medial table) cut letter-grade rating changes and
one-year reversals without moving spreads' rank fit, failures or the rating mix? Harness: LeverageHarnessTest via
`run.sh R0|R2`, SNAP=base (rttree verified = HEAD for DebtEngine, CreditRatingAgency, EarningsEngine; ovrR2 = working
tree), 20 y at TPY 360, seeds 1-16 paired (1-4 cached). Analysis `rating_guards.py R0 R2`. Wall: 145 s (5-8), 230 s (9-16).
Stopped at 16: both targets |t| > 10; failures 1 vs 1 (R0 FALC y7.9, R2 TIER y18.8), revolver draws 11.0 vs 11.7/seed.

| metric (n=16) | R0 | R2 | diff | se paired | t paired | t Welch |
|---|---|---|---|---|---|---|
| changes / firm-yr | 0.1790 | 0.1194 | -0.0596 | 0.0055 | -10.76 | -10.14 |
| reversals <=1y / firm-yr | 0.0686 | 0.0416 | -0.0270 | 0.0025 | -10.74 | -10.62 |
| op rating-spread Spearman | 0.6136 | 0.6080 | -0.0056 | 0.0119 | -0.47 | -0.31 |
| fin rating-spread Spearman | 0.6181 | 0.6246 | +0.0065 | 0.0109 | 0.59 | 0.57 |
| op share A / BBB / BB / B | .632/.295/.065/.007 | .662/.272/.059/.006 | +.030/-.023/-.006/-.001 | | 4.36/-3.93/-1.94/-0.49 | 3.82/-3.54/-2.17/-0.43 |
| failures / seed | 0.0625 | 0.0625 | 0 | 0.091 | 0 | 0 |
| payment defaults / seed | 0.0625 | 0 | -0.0625 | 0.0625 | -1.0 | -1.0 |
| revolver draws / seed | 11.00 | 11.69 | +0.69 | 2.87 | 0.24 | 0.26 |

Caveat: the op mix moves ~3pp from BBB to A (|t| ~4), a real but small upgrade tilt. Revolver draws counted from
"Drew ... revolving credit facility" event lines. Tables: rating_s8.out, rating_s16.out.
