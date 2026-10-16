# depreciation_schedule evidence

## 2026-10-16: schedule depreciation for all but Mining/OilGasProducer (worktree depreciation-state vs snap-base)

Question: does charging the schedule D (net PP&E x rate / 4) instead of units of production behave sensibly on the
full board? Targets: within-firm EBIT-margin std, seasonal EBIT amplitude, mean D level; producers as control.
Harness: `FullMarketHarnessTest.php` here (see RUN.md); arms rec/base/new/newlive, each on its own tree (verified by
the run's `src` field). Seeds 1-8, YEARS=15, TPY=360, no politics. Stopped at 8: targets past |t| = 4, rest negligible.
Table: `s8.out`. Ops firms (66 non-fin, non-producer), base vs new on the replayed macro path, delta (se):
- sd EBIT margin 0.0343 -> 0.0359, +0.0016 (0.0003), t 6.0 (live pair +0.0032, t 3.1)
- seasonal amplitude (55 seasonal firms) 1.40 -> 2.24 pp, +0.85 (0.04), t 22 (live t 13)
- D / schedule D 1.004 -> 1.000 (mean deseasonalized utilization 1.003-1.004): mean D level -0.35%
- EBIT margin 24.3% both; |surprise EPS|/price 0.33% both; ROIC 14.7-14.8%; capex/D 1.70 both
- PP&E growth 4.53 -> 4.40 %/yr, -0.13 (0.07) on replay, +0.10 (0.12) live: inside noise, signs disagree
- Altman distress firm-quarter share 0.38% -> 0.19% on replay, +0.08 pp live: tiny level, signs disagree
Producers (3 firms): every quantity |t| < 2; ann. variance EMA +5.7 (3.5) x1e-4, the expected-D-on-pre-shock-output
surprise, by design. Board: cap end/start 1.61 -> 1.56 (t -1.7), max drawdown 30 -> 32% (t 2.0) on replay; live pair
reverses both signs. No NaN, no negative prices, failures 0-0.5 per run.
Caveats: replay arms drop the board -> macro loop; live arms have it but pair loosely. 3 producer firms only.
