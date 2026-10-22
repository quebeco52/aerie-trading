# Interbank reference rate: evidence

## 2026-10-22: floating debt on policy + interbank spread, lenders priced on charged funding (uncommitted vs 2f2b6f39)

Question: do lenders open and stay on their ROE target after the change, and is anything not sensible (non-financial
interest burden, credit, the board)? Harness `FullMarketHarnessTest.php` here, `run.sh before|after`, 4 seeds x 8 years,
360 ticks/yr, live macro + Diet. Table: `stage4_y8.out` (runs in `runs/`, untracked).

Pairing: the macro path is identical at tick 1 in every seed and differs from the first quarter row (t=0.25) on;
seeds do not pair, so arm means (se over seeds) are the comparison.

First report, annualised NI / tangible equity over target (mean of 4 seeds; first quarter barely diverged):

| lender | before | after |
|---|---|---|
| LAKE | 1.07 | 1.06 |
| RIVR | 1.18 | 1.17 |
| PLVR | 1.31 | 1.14 |
| STRK | 1.37 | 1.36 |
| EGRT | 1.03 | 1.00 |
| BNTH | 1.02 | 0.96 |
| POOL | 1.02 | 1.04 |
| TALN | 1.03 | 1.02 |

Mid (yr 4) and end (yr 8) ROE - target: within +-4pp in both arms, se 1-4pp; no systematic drift in either arm.
Seed 3 after has a crisis in year 7 (gap -4.5%, interbank EMA 0.88%): all lenders end 7-15pp under target.

Stress quarters (interbank EMA > 0.40%): before 4 (seeds 1,0,1,2), after 21 (11,2,8,0), t ~1.7 unpaired; EBP is an
exogenous Schwartz + jump process fed only by the gap, so this reads as stream divergence. Lender PTI per stress
quarter: before 100-143bn, after 65-88bn, against ~105-130bn in calm quarters.

Non-financial: interest +7.8bn/yr (se 6.5) on ~107bn; ICR median 10.6 vs 10.4, p5 3.3 vs 3.3; downgrades 46.5 vs
44.8 notches; no payment defaults, bankruptcies or primary-market shut-outs in either arm. Board: index vol 15.2 vs
15.3%, max drawdown 26.9 vs 33.0% (se of diff 4.1), no NaN/INF, no negative equity, policy 0.75-5.25%, max firm spread
3.5% (cap) in both.

Caveats: target taken as max(opening market cost of equity 9.1%, baseline ROE) = baseline ROE for all eight; ROE
on tangible equity. 4 unpaired seeds: only large effects are visible.
