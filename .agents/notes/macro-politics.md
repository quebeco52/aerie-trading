# Standing decisions: macro, policy & politics

## Macro and policy

- No Council debt line: each party's plank sets a debt target and pace (`AerieDiet::DEBT_PLANKS`), a cabinet blends
  them by seats, and the default premium disciplines. A slower debt rule (debt over potential) was tested and reverted.
- No bank rescues: one automatic stabiliser plus the Diet's draw on the sovereign fund.
- The Authority meets 8 times a year in 25bp steps; the Charter rule weights inflation and the gap 1.5 each (the user
  overrode 1.5/0.5 after measuring). The rate committee's supermajority is 6 of 7 seats.
- The Authority's stance is a District choice, working only as a set: symmetric monetary drag, sees through only a
  positive productivity gap, mainland cycle as a level part outside domestic demand. Its rule also sees through the
  inventory, spending-timing and services-repricing level parts. The District's own rate asymmetry
  (`DomesticDemand::monetaryDrag()` against its 10y trend) costs slightly deeper slumps, kept; a demand compensator for
  it was rejected as free money (if needed, a learning neutral rate).
- A recession is two falling quarters. The crisis scar is 3% of potential, absorbed over 3y (hysteresis, not TFP).
  Crisis odds have no bank-capital term on purpose (capital predicts severity, not crises).
- Mortgages float (debt service on the policy rate); house prices value on the 10y plus a spread.
- The mainland has a compensated crash jump in its gap innovation (one shared GFC crash belongs in the common factor).
  Spillover back to the mainland reads District domestic demand, not its output gap.
- One-sided drags (credit premium noise, catastrophe burden, policy-uncertainty noise) keep their asymmetry and are made
  mean-preserving by compensators.
- Declined: a term-premium trim, a breakeven simplification, a demand-block tidy, wider Diet lever ranges. Raising
  `TFP_POTENTIAL_ABSORPTION_SPEED` to 1.0 (a US fit), a rule on today's gap with no forecast, a Phillips kink at 6% and
  higher wage rigidity were all tested and rejected. `WAGE_DOWNWARD_RIGIDITY_FACTOR` is the downward speed as a share of
  the upward: lowering it means MORE rigidity.
- Intended, not bugs: Okun reads the whole gap including the productivity supply gap; the services-repricing shock adds
  to the measured supercore rate directly; 30y > 10y because there is no pension LDI buyer; net debt falling for
  decades as the fund grows; unemployment above NAIRU most quarters (plucking); headline inflation barely correlated
  with the gap; five models read `governmentSpendingIndexEma` against 100 (purchases as a share of GDP against the
  Year-1 share).

## Politics

- Party normal votes pull back toward their founding shares (half-life ~40y), not toward equal shares.
- Coalition pledge (option E): if a bloc holds a majority, a government led from the other bloc pays an anti-pact
  utility; losing parties stay free to deal. Penalising every cross-bloc government over-corrects.
- In a coalition the PM's party carries the growth vote at about half the single-party slope; the cabinet fall hazard
  reads the year's change in the gap. Adding the unemployment change as well double-counts growth (Okun).
- Budgets are package deals in the status quo's win set; each supporter keeps a red line on the question it owns.
- The opening Council is recentred so its first appointments loosen as often as they tighten.
- The election forecast runs off the tick: tick time, not CPU, is the constraint.
