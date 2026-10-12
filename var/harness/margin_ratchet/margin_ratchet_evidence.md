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
