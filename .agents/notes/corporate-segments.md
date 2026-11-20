# Standing decisions: corporate engine, models & segments

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
