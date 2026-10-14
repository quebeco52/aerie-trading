# margin_ratchet evidence

## 2026-10-12: removing the plant-modernization margin ratchet (HEAD 00ead0c vs working tree)

Question: does removing `applyAssetDepreciationDecay`'s stored-margin channel stop the board-wide drift in
non-financial operating margins, and does it change firm failures?

Harness: `FullMarketHarnessTest.php` (earnings_b1 fork + stored margin per quarter), arms `before` (HEAD snapshot) and
`after` (main checkout working tree), live macro, no politics, YEARS=30, TPY=360, seeds 1-16 paired. See RUN.md.
Stage 8 (seeds 1-8) had targets 1-2 past |t|=4 but failures at 0.00 (se 0.27), so stage 16 was run. Table:
`stage16.out` (stage 8 in `stage8.out`).

    quantity                                            before     after      diff      se       t
    1 ex-fin ex-REIT EBIT margin drift, pp               3.865     0.605    -3.260   0.447   -7.30
    2 median stored-margin change, pp                    1.990    -0.257    -2.246   0.068  -32.99
    3 failures per run (bk+reorg)                        0.500     0.875     0.375   0.239    1.57
      bankruptcies per run                               0.438     0.625     0.188   0.164    1.14
      share of ex-fin firms with stored margin up        0.734     0.000    -0.734   0.005
      financials EBIT margin drift, pp                   0.524     0.943     0.419   1.465    0.29
      ex-fin EBIT margin, years 0-2, %                  19.558    19.384    -0.173   0.211   -0.82
      ex-fin EBIT margin, last 5 years, %               23.422    19.989    -3.433   0.366   -9.38

Caveats:
- Failures are inconclusive at 16 seeds (rare events, ~0.5-0.9 per 30-year run). 48 seeds would give se ~0.14.
- In `after` the stored margin still moves, only downward: 753 of 1038 surviving ex-fin firm-runs step down a little
  through M&A bolt-on margin blending (MergerAndAcquisitionEngine.php:691-694, revenue-weighted). Median -0.26pp over
  30 years; Semiconductors -3.2pp, Internet Content -3.5pp, Financial Data -2.6pp. The ratchet hid this before.
- Residual ex-fin EBIT drift of +0.6pp in `after` comes from sector mix and cycles (Internet Retail +8.3pp, Semis -7.6pp
  in both arms), not the stored margin.

## 2026-10-14: SAM grown by the sector's faded secular drift (HEAD 2f8bc54 vs working tree)

Question: does growing `calculateAddressableShare`'s SAM by exp(fadedExcessIntegral) take WEAV off the industry
price cap, and what does it do to board margins? Harness: this fork plus `ipl`/`sh` fields; `analyse_sam.py`.
YEARS=30, TPY=360, live macro, no politics. Seeds 1-16 per arm (stopped at 16: the caller's ceiling).
Command: `bin/harness-batch var/harness/margin_ratchet/run.sh 9-16 before after && python3 .../analyse_sam.py`.

    quantity                                       before    after     diff      se       t
    1 WEAV industry price level, y25-30             1.153    1.044   -0.109   0.013   -8.31
    2 mean |price level - 1|, ex-fin, y25-30        0.012    0.010   -0.001   0.001   -1.79
    3 ex-fin ex-REIT EBIT margin drift, pp          0.783   -1.173   -1.956   0.546   -3.58
      failures per run (bk+reorg)                   0.125    0.938    0.812   0.209    3.90
      WEAV share of board cap at end, %             9.222   11.402    2.181   1.059    2.06
      WEAV EBIT margin y25-30, %                   19.081   11.640   -7.441   0.980   -7.60

- Target 3 is all composition: excluding WEAV, the drift difference is +0.41pp (se 0.43). WEAV's share of ex-fin
  ex-REIT revenue in y25-30 rises from 18.2% to 27.5% while its margin falls.
- Other firms move by less than 0.01 in price level. No NaN, and no after-arm quarter below 0.85. Max level 1.275 -> 1.107.
- Failures: TIER (Private Equity) dies in 9 of 16 after-arm runs against 2 of 16 before-arm runs (discordant 9 vs 2,
  sign test p ~0.07). TIER's own SAM factor is 0.96 at y30, so the direct channel is small. Not diagnosed.
- SAM factor at y30: WEAV 3.69, FALC 0.87, GRIP 1.03 (`secular_growth.php`).

### 2026-10-14, stage 48 (seeds 17-48 added; same snapshot and tree; `sam_stage48.out`, `tier_stage48.out`)

    quantity (paired)                              before    after     diff      se       t
    1 WEAV industry price level, y25-30             1.144    1.037   -0.107   0.007  -14.92
    2 mean |price level - 1|, ex-fin, y25-30        0.012    0.010   -0.002   0.000   -4.70
    3 ex-fin ex-REIT EBIT margin drift, pp          0.804   -1.193   -1.997   0.291   -6.86
      WEAV share of board cap at end, %             9.540    9.547    0.008   0.773    0.01
      WEAV EBIT margin y25-30, %                   19.043   10.944   -8.099   0.709  -11.42

    unpaired (the arms diverge within a quarter)  before   after   rate diff (se)   Fisher 1-sided / 2-sided
    failures per run (bk+reorg)                    0.250   0.708   +0.458 (0.124)
    runs with >= 1 failure                         12/48   27/48   +0.312 (0.095)   0.0017 / 0.0034
    runs with TIER bankrupt                         3/48   22/48   +0.396 (0.080)   6.8e-6 / 1.4e-5
    runs with a non-TIER failure                    9/48   10/48   +0.021 (0.081)   0.5 / 1

- The extra failures are all TIER. Mean year of TIER death: before 18.1 (14.7-22.9), after 21.0 (9.5-29.5).
- 21 of 22 after-arm TIER deaths (3/3 before) follow, within 4 years, a quarter where wholesale debt falls 30-50%,
  equity falls 37-86% and net income is near zero, at P/B < 1. That is the dying-state divestiture
  (MergerAndAcquisitionEngine::executeDivestitureSale: lostDebt = wholesaleDebt x fraction, DIV_DYING 0.30-0.50;
  sale value = fraction x market cap x (1 - discount), so below book the loss goes straight to equity). TIER runs
  with such a quarter: before 16/48, after 30/48.
- The direct SAM channel cannot explain it: TIER's equity over its SAM is about 0.05 (saturation threshold 0.50,
  divestiture cash-buffer gate at scaleRatio 1.0), and its SAM factor is 0.96 at y30. In the after arm TIER's median
  ROE runs 1-2pp lower from year 5, with asset yield (rev/ta) 0.6-0.9pp lower and interest/ta 0.2-0.3pp higher, at
  the same leverage and EBIT margin. So it is indirect, through the changed world path. Not traced: the next step is
  a DRIVERS_OUT rerun (TIER stream details) on about 8 seeds per arm.
- The WEAV cap-share rise seen at 16 seeds (+2.2pp) is gone at 48 (0.0 +- 0.8).

## 2026-10-14 TIER deaths under the sector-drift SAM: not caused by it

TIER runs with >= 1 bankruptcy, 30y, unpaired (arms part within the first year):

| arm | TIER dead | Fisher two-sided |
|---|---|---|
| before (HEAD 2f8bc54) | 3/48 | |
| plc (HEAD, scale ratio x (1 + 1e-6), no economics) | 15/48 | vs before 0.003 |
| after (sector-drift SAM) | 22/48 | vs plc 0.21 |
| pen / led / ma (16 seeds; before 2/16 on the same seeds) | 6/16 / 4/16 / 2/16 | |

`ma` is bit-identical to before on all 16 seeds (the divestiture gate never changes a decision). A perturbation with
no economics raises TIER deaths five-fold, so HEAD's 3/48 is the low draw: TIER sits on a knife edge (interest 30% of
EBIT at the open, over 60% by year 12 in both arms), as the 2026-10-06 bank review also found ("replay noise").
TIER-read macro (HY spread +20-70bp, VIX +1.5-2pp, SLOOS tighter) in years 10-16 at t 2-2.7 on 16 seeds, after TIER's
ROE has already parted (years 8-10, t -2.9); 70 cells, read as noise. The fix's own TIER effect (22 vs 15/48) is
unresolved at this n.
