# opening_cap evidence

## 2026-10-05: SovereignFundSubsystem::BOARD_OPENING_CAP_USD re-measured after adding PLVR

Question: the board's total market value (USD) over the first quarter, as the constant's docblock says
(FullMarketHarnessTest, first quarter, seeds 11-12), with Plover Savings Bank (PLVR) on the board and without it.
The constant is 19.9e12.

Harness: `OpeningCapTest.php`, a cut-down copy of `../FullMarketHarnessTest.php` (same container, in-memory Redis,
real `app:market-seed`, live macro fed the previous tick's board cap, MarketOperator every tpy/24 ticks). It runs the
first quarter (90 ticks at 360 tpy) and records `StockTracker::updateStocks()['total_cap']` every tick, plus each
firm's price x shares at tick 1 and at the quarter's end. The no-PLVR arm seeds from `ovr_noplvr/Data/InitialMarket.php`
(the working tree's file with the PLVR entry cut, loaded through the bootstrap's `OVR` autoloader; 78 stocks vs 79).
- `run.sh with|without <seed>`, about 2 s per run. `python3 analyse.py > opening_cap.out`.

Seeds 11-12 (the docblock's) and 1-16 for a standard error. Not paired: removing a firm shifts the order of the
random draws, so every other firm's path decorrelates from tick 1. The with-without board difference is therefore
PLVR's value plus board noise; PLVR's own price x shares is the clean delta.

Table: `opening_cap.out`. Headline (USD T):

| quantity | with PLVR | without | difference (se) | n |
|---|---|---|---|---|
| Q1 mean, seeds 11-12 | 20.63 (11: 20.25, 12: 21.00) | 21.23 (21.38, 21.08) | -0.60 (0.52) | 2 |
| tick 1, seeds 11-12 | 21.13 (21.09, 21.17) | 20.66 (20.61, 20.72) | +0.47 (0.01) | 2 |
| Q1 end, seeds 11-12 | 20.67 | 21.79 | -1.12 (1.70) | 2 |
| Q1 mean, seeds 1-16 | 21.24 (0.17) | 21.60 (0.24) | -0.36 (0.30) | 16 |
| tick 1, seeds 1-16 | 20.98 (0.03) | 20.77 (0.03) | +0.21 (0.04) | 16 |
| PLVR price x shares, tick 1 | 0.236 (0.001) | | | 16 |

Caveats: the Q1 mean has a seed sd of ~0.7T, so two seeds cannot resolve a 0.24T firm; the tick-1 value is the
steadiest reading (sd 0.12T). Both arms sit above 19.9 by about 1T even without PLVR, so the board has grown since
the constant was set (other working-tree and committed changes), not only by PLVR.
