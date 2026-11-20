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
- No seeded operating margin on an operating firm (11-20). The row states moat and, optionally, `capital_intensity`
  (±2 steps of 1.25x); the margin is (CAPM + moat) / ((1 - t) x turnover), with turnover from `IndustryTurnovers`.
  Keyed by board industry, not Damodaran: capital per sales dollar is a property of the business, and Damodaran's
  buckets mix ports with logistics and law firms with prisons. Board turnovers sit 1.5-2x under Damodaran US on
  purpose (moat ladder caps opening ROIC near 25%). A firm whose margin will not fit is a different business, not a
  bigger step: JDAW became Online Marketplaces (revenue is commission, not merchandise). Financials and REITs keep a
  stated margin; it means something else there.
- A row never states a default (11-20): `sector` comes from `Sectors::INDUSTRY_SECTORS` (OWLS and GDWT file elsewhere
  and say so), `public_float` defaults to 85% (`SeedRows::DEFAULT_PUBLIC_FLOAT`), `beta_tilt` to 0.
  `InitialMarketTest` refuses a row that restates one.
- No seeded coupon or floating share (11-20). Inside the opening price loop each pass sets the fixed coupon to
  `yield5yEma` + the through-the-cycle spread at that pass's rating, and the floating share from
  `CreditRatingAgency::OPENING_FLOATING_DEBT_RATIO` (A 15% .. CCC 60%), then strikes opening earnings and dividend on
  them. No vintage discount: the old 3.5-4.5% seeds were an opening windfall that rolled up to market within 10 years.
  Exceptions: the 8 deposit lenders keep both keys, because their books are built before they have a rating. The 9
  dealers and funds keep their floating share, because repo and prime funding is a business-model fact.
- No seeded fixed-cost share on 92 rows (11-20). `IndustryFixedCosts` gives it by board industry, and `CostStructure`
  moves it ±0.10 per step (only TICK rigid and SHRK flexible use one). Damodaran gross margins were rejected because
  GAAP cost of goods holds fixed plant costs, and the model's input-cost mix misses resold goods.
  - Kept seeded: deposit lenders, SAFE, DOVE, WELL and PELI, where the ratio is a loss/expense split or the medical
    loss ratio.
  - CASC stays near 0.025, because the refiner charges crude separately; above about 0.04 it double-counts.
  - Open lore questions: the REIT spread (0.25-0.85, gross vs triple-net leases?), ACC 0.30 vs ROOK 0.75, PHIL, CROP,
    LYRE and CLAW.
- No `systemic_importance` (11-20). Column and all 103 seed keys retired; Penrose saturation runs un-dampened (`1.0x`),
  analyst coverage scales smoothly with log live real market cap (`COVERAGE_SIZE_*_GDP_SHARE`), and Glasswater Row
  plot widths/beacons follow live share of street market cap (`LANDMARK_CAP_SHARE` / `WIDE_CAP_SHARE`).
