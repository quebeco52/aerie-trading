# Standing decisions: world & firm lore

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
