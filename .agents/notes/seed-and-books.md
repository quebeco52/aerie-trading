# Standing decisions: seed data & opening books

- A seed row states character (revenue, margin, moat, addressable share, leverage policy, payout, growth, risk); each
  business model builds its opening books as its own steady state (`resolveOpeningBooks()`, entry `SeedRows::resolve`).
  Size is set by revenue, not equity. Opening market value comes from the valuation engine, so check the opening cap.
- Moat is a 5-step ladder (`Moat`: 0/3/6/10/15pp over the cost of capital) with per-industry defaults
  (`IndustryMoats`); a lore override moves at most two steps. The board's return over cost (~9pp) is above real
  economies and kept.
- Operating firms open with retained earnings at 50% of equity (`OPENING_RETAINED_SHARE`); `retained_share` overrides.
- Payout is a policy step (`PayoutPolicy` retain/low/balanced/income/harvest = 0/.20/.35/.50/.75), reviewed yearly, one
  step at a time with hysteresis, defaults per industry (`IndustryPayoutPolicies`). Continuous payout ladders were
  rejected: policy changes are legible headlines.
- Dividend roll tiers by streak (`DividendTier`): Challenger 5, Contender 10, Champion 25, King 50. Opening streaks are
  derived, not invented; opening dividends sit at the Lintner steady share of target. Smoothing payers raise only at
  fiscal year end by at least `MIN_ANNOUNCED_DIVIDEND_RAISE`; a token raise only up to the policy target; a raise may be
  borrowed only when covered by earnings and under the manager's leverage target.
- REIT books are at historic cost; property return comes from a higher REIT asset beta (0.68), not a separate premium.
- Insurers match reserves with held-to-maturity bonds (life near 1). WELL's reserve-to-premium ratio is 6.0, not
  ACLI's 9.9 (at 9.9 the runoff floor bound and fed back).
- Price risk is two ladders per industry (`IndustryRisks`): `BusinessRisk` (operating idiosyncratic vol, √2 steps
  0.08-0.31; equity for a financial) and `EventRisk` (own-jump arrivals 0.3-1.25/yr, jumps always carry 25% of own
  variance); a lore override moves one step. Opening equity vol and jump size are derived in the opening price loop,
  levered by the beta's Hamada multiplier. Damodaran's industry std devs are small-cap and were rejected as a source.
  After the open the long-run vol is re-levered every tick (`leveredVolatility`); credit compares spot vol with it, so
  leverage reaches credit once, through Merton's V/D. Revenue, margin and fundamental-jump noise stay on the opening
  `volatility` on purpose: a firm's sales do not get noisier because it borrowed.
- No seeded credit spread. A firm pays its rating class's spread restated at its own LGD, plus the volatility premium;
  the rating already carries leverage and coverage, so no per-firm markup sits on top. `Stock::$creditSpread` is a
  record of the opening through-the-cycle spread, not an input. No systemic-support discount either ("No bank
  rescues"; the bank-levy lore's "public guarantee" is deposit protection, which does not reach wholesale creditors).
- A lender whose opening loan spread comes out negative is telling you its moat is too narrow for what its deposit and
  fee book already earns (EGRT, 11-20: narrow -> moderate). Fix the moat, not `TargetCapitalRatio`.
