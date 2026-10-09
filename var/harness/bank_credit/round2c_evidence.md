# bank_credit round 2c evidence

## 2026-10-06: retail-default intercept (RETAIL_CREDIT_INTERCEPT = -0.06) and the STRK tail at 48 seeds

Question: (1) what the intercept does to the round-2 tables (seeds 1-16); (2) whether STRK's worse tail under the
working tree is real or noise at 48 seeds, and what drives its bond-market shut-outs.

Harness: `BankCreditHarnessTest.php` (round-2 copy kept as `runs/r2/BankCreditHarnessTest.r2.php`) plus a recording
subclass `BankCreditDebtTap` swapped into the TreasuryEngine's DebtEngine by reflection. It logs every maturity roll of
the six lenders (refinanced?, ICR, dynamic spread, rating, rating-floor test, capital-target exemption, repaid,
unfunded shortfall) and every `issueDebt()` called from `processEmergencyBorrowing` / `processRevolverDraw`, then
defers to the parent unchanged. Tap neutrality: the tapped BEFORE rerun reproduces `runs/r2/before-*` exactly
(macro path, six lender ledgers, deaths) on 16/16 seeds. Quarter rows gain `rvd` (revolver drawn) and `pdef`.
`run2c.sh <after|before> <seed>` writes `runs/r2c/`; BEFORE = HEAD c2f29f4 (scratchpad `wt_c2f29f4`), AFTER = working
tree with the intercept; each on its own live macro path, same `mt_srand(seed)`. Three batches of 32 at `xargs -P 7`
(~12 min each). 48 seeds x 20 y x 360 tpy per arm, all 96 runs OK, tree md5 unchanged across the runs.
`python3 analyse_r2c.py > round2c_seeds1-48.out` (part 1 also compares PREV = `runs/r2/after-*`, no intercept).
48-seed macro rows: `macro_r2c_seeds1-48.out`.

### Part 1, seeds 1-16, BEFORE -> AFTER+intercept (PREV no intercept), mean (se)

| quantity | BEFORE | AFTER+int | PREV | AFTER-PREV |
|---|---|---|---|---|
| retail default EMA mean, % | 2.32 | 2.44 | 2.15 | +0.28 (0.09) |
| retail default EMA sd, pp | 0.71 | 0.64 | 0.57 | +0.07 (0.06) |
| NCO/TTC LAKE / RIVR / PLVR | 0.83 / 0.78 / 0.87 | 0.92 / 0.89 / 0.92 | 0.83 / 0.82 / 0.83 | +0.09 / +0.07 / +0.10 (0.03-0.04) |
| NCO/TTC TALN / STRK / POOL | 0.98 / 0.98 / 0.98 | 0.95 / 0.92 / 0.88 | 0.87 / 0.85 / 0.80 | +0.08 / +0.06 / +0.09 (0.02-0.04) |
| STRK ROE TTM mean / p5, % | 14.9 / -3.9 | 8.7 / -22.4 | 13.3 / -9.2 | -4.7 (3.0) / -13.2 (8.1) |
| STRK allowance peak, % book | 7.2 | 14.2 | 13.5 | +0.7 (0.5) |
| STRK recession cost, % book | -0.3 | 5.2 | 5.2 | -0.3 (2.5) |
| CET1 min LAKE / RIVR / PLVR, % | 12.2 / 16.6 / 11.4 | 12.3 / 16.1 / 11.1 | 12.2 / 16.1 / 11.1 | ~0 |
| PLVR reported NIM, % | 6.07 | 5.32 | 5.47 | -0.15 (0.11) |
| shut-out lines / seed STRK / TALN / PLVR | 4.75 / 0.12 / 0.81 | 3.75 / 0.94 / 0 | 2.75 / 0 / 0 | +1.0 (1.0) / +0.9 (0.5) / 0 |
| board bankruptcies / seed | 0 | 0.12 | 0.06 | +0.06 (0.11) |
| non-finite samples | 0 | 0 | 0 | 0 |

48 seeds: retail EMA mean BEFORE 2.45 (0.05) -> AFTER 2.40 (0.05), sd 0.84 -> 0.65 (-0.19, 0.06); NCO/TTC AFTER
LAKE 0.91, RIVR 0.88, PLVR 0.91, TALN 0.94, STRK 0.91, POOL 0.87 (se 0.01-0.02).

### Part 2, seeds 1-48, STRK and TALN, BEFORE -> AFTER+intercept, mean (se), diff per seed

| STRK | BEFORE | AFTER | diff (se) |
|---|---|---|---|
| quarterly ROE p5 / p1 (per seed, annualised), % | -26.1 / -45.2 | -34.9 / -62.7 | -8.8 (7.6) / -17.5 (11.5) |
| pooled quarterly ROE p5 / p1, % | -17.0 / -55.9 | -32.3 / -76.5 | |
| TTM ROE p5 / mean, % | -16.2 / 11.6 | -24.6 / 8.4 | -8.4 (5.8) / -3.2 (1.9) |
| min equity / opening equity | 0.93 | 0.82 | -0.11 (0.05) |
| max book-equity drawdown, % | -28.1 | -39.6 | -11.5 (4.7) |
| quarters with NI < 0 / seed (seeds with any) | 10.8 (48/48) | 18.9 (48/48) | +8.2 (1.4) |
| refinancing refusals / seed (seeds with >= 1) | 7.1 (83%) | 14.2 (100%) | +7.1 (1.4) |
| shut-out lines (> $0.5B) / seed | 3.96 | 5.19 | +1.2 (1.0) |
| principal repaid in cash when refused, $B / seed | 6.7 | 8.2 | +1.4 (1.9) |
| unfunded shortfalls; emergency debt; revolver; emergency equity | 0 | 0 | 0 |
| failures / reorganisations / payment defaults | 4 / 0 / 0 | 4 / 0 / 0 | |

Refusal reasons (tap): ICR<1 342/342 BEFORE, 681/681 AFTER; spread closure 0 (max dynamic spread 0.095 / 0.071 vs the
0.10 closure), rating floor 0. Rating when refused AFTER: AAA 1, AA 5, A 61, BBB 202, BB 371, B 24, CCC 17; 269/681
(40%) investment grade, 487/681 in a loss quarter, median ICR -0.80. BEFORE: 185/342 (54%) investment grade.
Capital-target exemption applied to 0 refusals: STRK and TALN have no tuned TargetCapitalRatio, so the round-2 refi
gate (`DebtEngine.php:828`) does not cover them.

TALN: TTM ROE p5 10.5 -> 8.8 (-1.7, 1.4), loss quarters 1.3 -> 2.2 (+0.9, 0.6), refusals 0.42 -> 1.35 (+0.94, 0.44),
seeds with >= 1 refusal 12% -> 31%; all refusals ICR<1, 48/65 investment grade; no shortfalls, no failures.

Board, 48 seeds: bankruptcies 8 -> 10 (0.17 -> 0.21 / seed, +0.04, 0.12). BEFORE: STRK 4, TIER 2, RIVR 1, PLVR 1
(RIVR/PLVR/STRK all in seed 37). AFTER: STRK 4, TIER 5, CASC 1. Every STRK failure, both arms, is the financials'
balance-sheet test (alternative Z-score, "collapsed into insolvency"), with equity at 0.14-0.28 of opening, never a
payment default. Non-finite samples 0.

Finding: STRK's body of losses worsened for real (loss quarters +8.2 (1.4)/seed, drawdown -11.5 (4.7) pp, min
equity -0.11 (0.05)); its extreme tail did not move outside noise (TTM p5 -8.4 (5.8), quarterly p1 -17.5 (11.5),
failures 4 vs 4). It follows the TTC 3.5 -> 7.5% book (twice the loss rate, twice the cyclical amplitude), not the
intercept. Shut-outs are ICR<1 refusals during loss quarters, 40% at investment grade, all repaid from cash: a
deleveraging drain (~20% of equity cumulatively per seed), not a failure channel.

Caveats: arms on different macro paths; BEFORE's retail-default tail is fatter (p95 4.1 vs 3.6), which feeds
STRK's BEFORE failures (peaks 6-8%) while AFTER failures come at 4.8-7.1%. Quarterly ROE p1 from 80 quarters is
near the minimum. Part 1 AFTER-PREV differences for tail cells are at n=16.

## Round 2d: lender refinancing exemption (2026-10-06)

Question: `DebtEngine::hasPrimaryMarketAccess` now skips the ICR<1 refusal for every `CommercialBankBusinessModel`
(adds STRK, TALN, POOL to the capital-target institutions); CCC floor and 10% spread closure unchanged. Does keeping
access let a lender lever up into trouble?

Harness: `BankCreditHarnessTest.php` with the tap, unchanged. Arms: R2C tree = scratchpad `wt_r2c` (HEAD archive +
working-tree files, lender clause removed by hand; its live runs `runs/r2d/rec-*` reproduce `runs/r2c/after-*` on
16/16 seeds, every field) vs the working tree. Live arms do NOT pair: macro shocks come from the shared MathUtility
stream (e.g. `CreditFiscalSubsystem.php:353`), so the first firm-side difference re-deals every later macro draw
(paths diverge at quarter 6-68). Primary comparison is therefore REPLAY: both arms on the macro path recorded by
`rec-<seed>` (`run2d.sh rec|rc|rd <seed>`; paths in scratchpad `r2d_macro/`, 313 MB, regenerable). Live working tree
runs `runs/r2d/after-*` via `OUTDIR=runs/r2d ./run2c.sh after <seed>`. Seeds 1-16, 20 y, 360 tpy, 6 workers;
64 runs all OK, tree md5 unchanged. `python3 analyse_r2d.py > round2d_seeds1-16.out`.

Replay pair, mean (se), r2c -> r2d, paired diff, n = 16:

| quantity | STRK | TALN | POOL |
|---|---|---|---|
| refusals / seed | 13.0 -> 0 (-13.0, 1.7) | 1.0 -> 0 (-1.0, 0.5) | 0 -> 0 |
| ICR<1 rolls now refinanced / seed | 14.9 (2.1) | 1.0 (0.5) | 0 |
| wholesale debt y20, $B | 21.1 -> 32.5 (+11.5, 7.6) | 59.7 -> 78.6 (+18.9, 14.2) | 1159 -> 1108 (-50, 33) |
| wholesale debt mean, $B | 13.1 -> 19.1 (+6.0, 2.6) | 36.4 -> 41.1 (+4.8, 4.0) | 722 -> 709 (-13, 11) |
| wholesale debt / equity mean; max | 0.24 -> 0.33 (+0.09, 0.02); 0.47 -> 0.66 (+0.18, 0.07) | 0.31 -> 0.32 (+0.01, 0.02); 0.52 -> 0.59 (+0.07, 0.05) | 3.38 -> 3.34; 4.66 -> 4.66 |
| wholesale debt / assets y20, % | 3.3 -> 4.9 (+1.6, 1.1) | 4.8 -> 5.9 (+1.1, 0.9) | 74.7 -> 73.3 (-1.3, 1.0) |
| cash mean, $B | 43.3 -> 44.7 (+1.4, 1.1) | 80.2 -> 80.5 (+0.3, 0.5) | 47.2 -> 46.5 (-0.8, 0.6) |
| quarterly ROE mean, % | 7.5 -> 8.9 (+1.3, 2.0) | 22.6 -> 22.6 (0.0, 0.2) | 21.8 -> 21.8 (0.0, 0.1) |
| quarterly ROE p5, % | -33.2 -> -31.0 (+2.2, 5.7) | 7.9 -> 7.5 (-0.4, 0.6) | 12.8 -> 12.7 (0.0, 0.3) |
| quarterly ROE p1, % | -64.3 -> -54.2 (+10.1, 11.7) | 2.5 -> 0.9 (-1.6, 1.0) | 10.7 -> 10.7 (0.0, 0.3) |
| max book-equity drawdown, % | -36.9 -> -38.7 (-1.8, 1.3) | -6.3 -> -6.9 (-0.5, 0.8) | -2.5 -> -2.3 (+0.2, 0.2) |
| loss quarters / seed | 17.1 -> 19.4 (+2.4, 1.05) | 1.7 -> 1.7 (0.0, 0.3) | 0 -> 0 |
| shortfalls; emergency debt; revolver; emergency equity | 0 / 0 / 0 / 0 both | 0 both | 0 both |
| failures / reorgs / payment defaults | 1 / 0 / 0 -> 0 / 0 / 0 | 0 -> 0 | 0 -> 0 |

Board (replay): bankruptcies 2 -> 0 (STRK s9, TIER s12 -> none; -0.12, 0.09 / seed); tapped-lender refusals 14.0 ->
0 / seed; shut-out lines, all firms, 24.9 -> 21.3 (-3.6, 2.0); firms with payment-default audits 0.19 -> 0.06;
non-finite 0 -> 0. r2c refusals were all ICR<1 (STRK 208, 40% investment grade; TALN 16); r2d has none.

Live pair (unpaired macro, for reference): STRK refusals 12.1 -> 0.19 / seed, all 3 r2d refusals spread closure
(+ICR<1) at B/CCC; POOL 0 -> 2 refusals, both spread closure at B, in seed 8. Seed 8's r2d live path re-dealt
into a deep crash (retail default EMA peak 12.8% vs 4.4%, gap -8.7% vs -2.6%, retail credit factor -0.54) that kills
STRK, TALN, LAKE, RIVR, PLVR, EIDR and TIER at t 6.9-8.3; LAKE/RIVR/PLVR were already exempt, STRK held $11B debt vs
$9B, so the cascade is the macro draw, not the exemption. Board bankruptcies live 2 -> 8 (all but one in seed 8).

Finding: the exemption removes the ICR<1 refusals (14 -> 0 per seed) and the cash drain; STRK keeps ~$6B more debt on
average (wholesale/equity +0.09, max +0.18), still ~5% of assets. Drawdown (-1.8, 1.3), ROE tails and failures do not
worsen; loss quarters +2.4 (1.05), +1.5 (0.67) without seed 9, from carrying the extra coupon (interest/revenue 2.50 ->
2.76%). No lender levers up into trouble at n = 16.

Caveats: n = 16; quarterly p1 from 80 quarters is near the minimum. Replay fixes the macro but drops the equity-cap
feedback within the run (the path itself was generated with it). Board-wide refusals are counted only for the six
tapped lenders; other firms are covered by the shut-out lines (> $0.5B repaid).
