# Standing decisions and lessons

What past work settled: the user's decisions, approaches tried and rejected, choices that look like bugs but are
intended, and measurement traps. Read the section for your area before proposing a change. Reopening a rejected
approach is allowed, but say that you are doing it and why. Where the code now disagrees with a line here, the code
wins; tell the user so the line can be fixed. Status and open work do not belong here.

## Working with the user

- Explain a choice in plain words: what is wrong, why it matters, and what each option does to the game a player sees
  (bigger or smaller firm, steadier or lumpier earnings, needs a reseed). Say plainly when an option keeps something
  known to be wrong. Model internals in a question get answered without being understood.
- A live macro dump is a normal run of the model, never an outlier or a tail draw. Start from its anatomy (what drove
  each slump, what held it there). If a harness disagrees, that is a question about the harness or the model.
- Core loop over features: weigh work by how often a player sees its effect. Earnings, prices, cycles and gluts come
  every quarter; rare events (market entry, ~0.2 a year board-wide) do not earn long calibration rounds.
- All the way or not: a partial overlay on a mechanism (per-line drifts on 7 models) was reverted. A redesign comes
  back whole or not at all.
- Stop tuning to a target (roll size, a moment) and fix defects by rule.
- A plan file in `docs/plans/` written for a handoff is deleted once its work is verified; the commit message and
  RUN.md keep the record.
- A diagnosis that drives a mechanism change, or a handoff plan that hits its stop rules, gets an independent second
  opinion (symptoms, not the diagnosis) before the next attempt.

## World and lore

- District GDP is $6T (`MacroConstants::DISTRICT_GDP_USD`): its corporations operate heavily on the mainland and
  abroad. Lore headcanon: ~35M residents, ~1 in 10 a millionaire, 3-4M mainland commuters, Charter of 1950. A 20M
  population was rejected (home-market firms would sell 2-5x real per-head spending).
- The District is on the US east coast (Albemarle-Pamlico), joined to the mainland, and the mainland is its main
  trading partner: trade links are sized Canada-like (exports 0.32 of GDP, `OpenEconomy::DISTRICT_EXPORT_SHARE`, ~4.5%
  surplus). Cycle co-movement is big-pair, compared with EA/JP/DE against the US, not Canada-US. The mainland is the US
  by lore, so mainland tests fitted to US data stay.
- Gray underbelly: gray conduct (SILV landlording, SWAN boardroom coups, LAKE private dividends, FINC payment for order
  flow, HONY contingent commissions) is lore that sets per-firm parameters, with no routine enforcement drag. Tax fraud
  against the District is the exception: a rare, catastrophic event that destroys the business, not frequent fines.
- The Council is appointment-only; the Diet has no power over it ("removal" in lore is a censure motion).
- Merger review belongs to the Fair Trading Board alone, off the Diet (stance +1 strict, `FOUNDING_LENIENCY` 0.6875).
  House-style names ("Fair Trading Board", not "Competition Commission").
- The Securities Commission sets margin requirements and enforces against earnings management. Short-sale bans and
  halts were declined; the profit-warning threshold was dropped (warnings never fired); maintenance margin stays fixed
  (scaling it would call open positions); initial margin 40-80% with 3 months' notice.
- The Security Bureau, Coast Guard and State Guard are lore only, no mechanism (their lore lives in `Office` cases).
  The Council alone commands and calls out the guards; the Diet funds them with no say. Commanders serve 5y, the Bureau
  director 10y with no stance. The guards are a deliberate "wolf in sheep's clothing" (Japan SDF): the Charter forbids
  an army and the guards are one in practice. Official pages keep the fiction; the Almanac states the gap.
- Everything in the District Almanac (`content/almanac/`) is set before Year 1; the user writes the incidents.

## Firm lore

- FIRE (Firecrest Atomic): reactor vendor with an installed fleet (fuel, outages, occasional giant fixed-price builds
  that overrun), ~$80bn; not an SMR startup, not naval. Overruns are operating cost, not `specialCharge` (a recurring
  expected loss there would never be priced). Accidents are transitory; the fleet is not a level state (plan capacity
  already follows demand).
- SKYL (Skylark Orbital): SpaceX-like launcher growing a satellite-internet business that becomes most of its sales;
  widely held, starts a dividend once internet matures. The internet market size is a hidden value drawn at listing and
  learned from sign-ups (Kalman belief), chosen over a deterministic S-curve. The market prices current growth, faded.
- SPRW (Sparrow Markets): supermarket with fuel forecourts, ~$80bn, own GroceryBusinessModel (a reseller earns a
  percentage markup). Price wars sized on the UK 2014-17 war.
- TERN (Tern Airways): mid-size (~$50bn), widely held, mostly home market; visitors ~20% of leisure demand.
- BELL (Bellbird Media): Fox-like broadcaster, ~$200bn, sells political ads to District parties; no partisan lean.
- Consultancies GDWT (IT), TSTN (restructuring), AVCT (Deloitte-like, ~40% audit and tax) share ConsultingBusinessModel
  and differ by line weights.
- NCKR (cash and vaults) and NJAR (investigations) are rivals of WATCH and OSPR in 'Security & Protection Services' on
  one 7-line model; WALL (prisons) has its own CorrectionalServicesBusinessModel.
- ROOK (Rookery Holdings): exchange group with the Commodity Exchange; the District holds 20%
  (`StrategicHoldings::EXCHANGE_STAKE`); dual-class control is lore only (`public_float` 1.00).
- FINC (Finch Securities) replaced the old ROOK prop-trading firm as a retail broker.
- WELL (Wellspring Life) and HONY (Honeyguide) are widely held, no link to Tiercel.
- REDW: ore, stone and grain under take-or-pay hold through the cycle; steel, timber and containers follow factory
  output (lore matched to the model).
- CASC is an export refinery, which explains its size.
- BRKW (the trust) buys only in its charter industries, sells only in distress, trades at a closed-end discount
  (`NAV_DISCOUNT_BASE` 0.20), NAV is sum-of-parts, buybacks gated at NAV; held firms return surplus by pro-rata
  redemption; minimum tradable float 10%.
- STRK keeps deposits (a non-bank variant was declined); TALN is 25% wholesale-funded.

## Seed data and opening books

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
- `credit_spread` is read (TreasuryEngine's junk baseline); the seed field is not dead.

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

## Corporate engine and business models

- Excess cash is valued at face, $1 per $1 (inside the trust it takes the structural discount).
- One-off charges go to `SectorPhysicsResult::specialCharge`, never the cost ratio; ongoing regimes stay in the cost
  ratio. A biotech trial failure is operating R&D.
- Non-financial growth plant must earn the firm's true cost of capital whoever runs it; management style shows only in
  borrowing, cash, payouts and takeovers. Ventures are judged at management's applied hurdle (empire builders enter
  cheaply) on purpose. Growth capex is built as construction in progress before it runs.
- Idle plant is a reporting figure; normal is 0%, it shows in recessions and gluts. Plant rarely idles by design.
- Fee earners grow only to their capacity share (their seeded share), with no debt-funded expansion past it.
- Financial books inside operating firms (captive finance, conglomerate float, trade credit) stay inside their models,
  deconsolidated, never plant.
- All floating debt pays interbank plus the issuer's spread; deposit flight happens only on real outflows. Credit trends
  open at their measured long-run means.
- Franchise power moves by the local slope of value (`Valuation::franchiseValueElasticity`); running costs scale by
  (1-m')/(1-m), never by subtracting from fixed costs.
- Levered beta comes from D/E on the business valued at its asset beta, cash-blended. Cost of equity is floored at the
  debt's expected return (absolute priority, pinned by DebtEngineTest for negative-beta firms; do not remove it).
- Rejected: per-line trend drifts as an overlay; "a won customer stays won" persistent share; plant following a falling
  belief; mean-reverting industry outlooks; capitalising policy earnings at the firm's P/E (marginal value is 1/(r-g));
  the staples hedge as a permanent input-cost cut (free money) and the commodity-desk squeeze bonus; an EPS-accretion
  brake on empire builders beyond the existing screen (slowing GULL is a lore choice).
- Intended, not bugs: a board EPS beat rate near 75%; the dividend-yield drift term and the ex-dividend floor in
  `MarketEngine`; corporate flow permanent impact 0; P/B above 10 on high-ROE firms; TERN and FALC drawdowns of
  -80 to -95%; lumpy revenue at TIER, SWAN, PTRL/KING, SKYL and CLAW; SINK's high peak margins (no price-linked
  royalty); IBIS building plant to hold share while revenue rides the pipeline.
- REITs fade ~14% against GDP over 20 years (rent on net book wears faster than upkeep replaces); accepted 11-20.
  Plan C (rent on market-price property capital, patch in `var/reit-plan-c-round2.patch`) was parked after two rounds:
  with no moat a trust sits at NPV zero and a binary build gate flickers on ERP noise; growth needs new buildings
  worth slightly more than cost, which rent rising on scarce space (DiPasquale-Wheaton) would give. A per-share DDM must
  not grow a full-payout trust's dividend at the property's nominal growth (that is equity-funded); the consistent rate
  is (inflation x P + retained AFFO) / NAV.

## Segments and industry outlook

- Segments are in `src/Data/Company/Segments.php`. Entry is by build and by buy, no full pivot (the entrant keeps its
  label, beta and model). Failed builds are sold like failed purchases. A venture's target is the smallest of a typical
  incumbent's share, the room the fringe leaves and the growth it can manage. Entry work is parked.
- Segment accounting rules: revenue counted once with intersegment elimination; working capital, leases and debt not
  kept per segment (a sale takes its share of each); per-entry goodwill in state; one cost shock applied once; one
  pricing-power path; tax at the firm; a sum-of-parts value replaces the whole-firm value, never adds to it; commodity
  lines get no second price channel.
- Every industry with a drift has an outlook: true demand a random walk, beliefs a local-level filter that never
  settles. Outlooks shift spending shares zero-sum and never change total demand. Pages show belief, never truth;
  outlook draws come from a salted hash, never the global stream.
- Prices carry only the catch-up the engine will actually build, and management values the same catch-up.
- Shared `ModelParam` keys (SubscriptionWeight, ConsumerWeight, ...) mean different things in different models.

## Market, valuation and pages

- One equity premium; the market factor loads on economic news. Pricing discount-rate news into price at once was tried
  twice and reverted (fair value capitalised a short-lived premium as permanent).
- Stored `fairEquityValue` excludes market repricing; a board-wide price/fair ratio away from 1 is the discount-rate
  cycle, not mispricing. Fair value must not price the output gap as perpetual growth.
- The analyst consensus anchor rolls on the market quarter, not the public quarter (that double-counted firm news).
  Analyst targets near 1.2x price match real sell-side optimism.
- `SVENSSON_SLOPE_LAMBDA` 0.30 is calibrated to the empirical 10y beta; it is not a yield-curve bug.
- Rivalry is an exact industry match, the rival drain weighted by revenue-mix overlap; line weights are the plan and do
  not adapt to realised shares. The endpoint the user wants is fully separate line streams, built whole.
- Goodwill impairment values the unit at max(model value, quoted price).
- Pages: the rivals chart compares at least 2 same-industry rivals, else the firm's own 3y average (an "industry incl.
  unlisted" line was rejected); "what moved the shares" is a weekly regression on market and peers; key stats show
  measured beta. Acquired private targets are named coastal word + industry noun.

## Code structure

- One refactor step per commit, proved byte-identical on seeded whole-board runs (mask the timing field); page
  refactors by diffing page arrays or renders.
- Kept large on purpose: MacroStateDTO, the bank and insurance models, the ticker's constructor (each dependency a
  phase), the macro-subsystem formulas sector models read.
- Page assembly lives in `*PageBuilder` classes; one chart factory; '—' for a missing figure.
- A Redis store that rebuilds records from a fixed field list drops new fields silently: give a new field a round-trip
  test.

## Measurement lessons

- Always split-adjust prices, returns and drawdowns in harnesses (~70 splits a run). Raw prices twice produced a false
  ~1.5x "vol overshoot". Harness DPS is `getLastDividend()`, not total dividends over end shares.
- Arms pair only when the macro draws from its own stream and the outlook salt is fixed (BoardRun does both). Before
  trusting a paired t, check that two short runs of one seed match. Pair on the mainland: its mean drives about half the
  cross-seed variance of the District gap; do not ANCOVA on it (the arm moves it through spillover).
- For board-wide effects, record a seed's macro and replay it into both arms; a neutral all-zero replay is biased for
  convex terms, so average antithetic pairs.
- Back-of-envelope estimates came in at about twice the harness effect: measure, don't extrapolate. Test an attribution
  with its own arm.
- Check the denominator before reading a correlation (buybacks/cap vs the market factor was all cap).
- A 20-year variance ratio has no power; a smooth paired log path proves a fade. The per-tick change in the output gap
  is not news; build news from the true shocks.
- REIT net income is already FFO (the engine adds depreciation back); adding it again in a script double-counts.
  Dividend-roll benchmarks are a share of payers, not of all firms.
- `energyPriceShock` is a level against 100 with a non-zero mean; read log(EMA / decade trend) for a cyclical term. A
  field read against its trend belongs in `MacroDriverAttribution::TREND_FIELDS`.
- Ratings read Merton distance to default on market equity and realised vol, so a vol overshoot can chain CCC ->
  shut-out -> default.
- An "exact" identity check must include reports after a deal or sale.
- PHPUnit names mock classes with `mt_rand`: seed after building mocks, and give each seeded run a fresh `MathUtility`
  (the Box-Muller spare leaks across `mt_srand`). `phpunit.dist.xml` stops on the first defect in a file.
- Delete `var/harness/cache/test` after a config or constructor change. Never leave source-tree copies under
  `var/harness` (its .php files are tracked).
- When changing a rule, grep for the behaviour's tests, not only the method name.
- Profile the prod ticker's TickProfiler window, not the dev env with xdebug; time each PHP variant in its own process.
