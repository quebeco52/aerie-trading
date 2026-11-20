# Seed streamline: what is left (handover to Gemini)

Status: 2026-11-20. Rows average 10.67 keys (target ~8; floor 5: ticker, name, industry, revenue, addressable_share).
Read `.agents/AGENTS.md` and `.agents/notes/seed-and-books.md` first; that file records every derivation done so far
(margin, credit spread, coupon, floating share, fixed costs, defaults). Claude will not be verifying, so verify your
own work the way Claude did:
- Run a Step 0 that reads and measures before you build.
- Write tests that compute expected values from inputs, never by calling the function under test.
- Break each guarded term by hand once and watch the test fail.
- Run `bin/verify --full`.
- For anything that moves the opening books, run settle with `RATINGS=1`. For anything that moves the dynamics, run the
  paired scorecard (`var/harness/risk-ladder/`, HEALTH=1).
- Report keys per row before and after.

Reseed pending for the credit, margin, debt-terms and fixed-cost changes. No migrations so far.

## The rule every item follows

A seed row states what makes the firm different. Anything that follows from its industry, size, rating or business
model is derived in one place, and `SeedRows` refuses it on rows where it is derived. Lore offsets are steps on a short
ladder, not free numbers. Don't tune a table, constant or ladder to pass a gate. A firm that misses is a lore question:
write it down and leave the seed.

## Order and rough plans

### 1. `systemic_importance` (103 rows, ~1.0 key/row)

Values are none 24, base 35, systemic 35, titan 9.

Readers:
- `FinancialConstants::SYSTEMIC_SATURATION_DAMPENERS` (`CorporateMetrics` L170/L244, `IndustryPositionBuilder` L160);
- `StandardOperatingPhysicsTrait` L155;
- the District map (plot widths, `DistrictMap` L209, `DistrictWardComposer` L161);
- the admin CRUD;
- the openers (`OpeningBoardBuilder` L116, `BaseFinancialBusinessModel` L230, `CommercialBankBusinessModel` L492).

Step 0:
- what each reader does with each tier;
- how the seeded tiers line up with opening size (revenue, invested capital, assets for financials) and with board
  share;
- whether a size threshold reproduces them.

Build: derive the tier from size, as a share of the board or of District GDP, with financials ranked on assets. One
threshold table, one function. Keep a lore override only where a row's comment explains the tier.

Gates:
- the opening books should not move (check with settle);
- scorecard saturation and market-share metrics within 2 SE;
- the District map renders (`bin/render-pages`).

### 2. `public_float` trim (79 rows stated, ~0.5 key/row)

Most stated floats are 0.88-0.95 set by feel, against the 85% default (`SeedRows::DEFAULT_PUBLIC_FLOAT`).

Step 0:
- every reader of the float (index weights, liquidity, order flow, `AnchorHoldings::tradableFloat`);
- for each firm, whether its row comment or its `StockInfo` profile names a block holder;
- the tests that pin floats in prose (`CompanyProfilesTest`: LAKE, SWAN, SAFE, BRKW, IBHI, GRIP, OSPR, CORV;
  `StrategicHoldingsTest`: ACC).

Build:
- Keep a stated float only where lore names who holds the rest (founders, mutual, state, parent; e.g. SKUA's mainland
  parent), or where it is fully public by lore.
- Let the rest default. Set the default to the median of the floats you remove, if that differs from 0.85; then
  restate the 24 rows that sat at 0.85 only if they have lore.

Gates: index composition and turnover within 2 SE; the prose pins hold.

### 3. `addressable_share` (98 rows, ~1.0 key/row; design first)

Revenue and share together state each industry's market size (revenue / share). Readers are in
`IndustryShareLedger` (L135 fringe revenue, L199, L492, L991, L1293, L1340-1348) and `sam_ratio` in the opening books.

Step 0:
- what the share drives (fringe competitors, saturation, entry, sam_ratio);
- the implied market size per industry;
- in multi-firm industries, whether the firms' implied market sizes agree and whether their shares sum to ≤ 1.

Build, if the implied sizes agree within an industry: an industry market-size table (as a District GDP share, so it
grows with the economy), with share = revenue / market. If they don't agree, stop and write up why before building.
This changes what "size" means in a seed row, so it needs the user's sign-off before Step 1.

### 4. Small per-firm items (together ~0.9 key/row)

- **`depreciation_rate`** (20 rows, none equal to the industry default): check each. Lore gets a comment; a leftover
  is deleted.
- **`capex_ratio`** (21 financials, their reinvestment rate): derive from sustainable growth, g = retention × ROE, with
  retention from payout policy.
- **Dealer/fund `floating_debt_ratio`** (9 rows): a constant per business model (repo and prime-broker funding).
- **`retained_share` on the 8 bank-family rows**: give the bank model the default other models have; keep the history
  lore on the rest.
- **Lender coupon and floating share** (8 deposit lenders): needs their rating to exist inside
  `CommercialBankBusinessModel::resolveOpeningBooks()`. Bigger; do it last.
- **`operating_margin`** on 14 financials, 4 REITs and BRKW: a per-model derivation (banks already have one). Each
  model's margin means something different, so take one model at a time.
- **`fixed_cost_ratio`** on deposit lenders, insurers and PELI: a per-model rule (the loss/expense split, the medical
  loss ratio). PELI and CASC break if theirs rise 0.10, so treat both as fragile.
- **BRKW's hand-set books**: build them from its anchor holdings.

### 5. Lore pass (values, not keys)

Questions left open by the fixed-cost work:
- the REIT fixed-cost spread (SHOR 0.25 / KITE 0.30 vs ELDE 0.75 / PLZA 0.85; gross vs triple-net leases?);
- ACC 0.30 vs ROOK 0.75;
- PHIL, CROP, LYRE and CLAW.

Answer each from the firm's lore with one comment line, or leave it.

## Keep (lore a table can't know)

`moat`, `beta_tilt`, `management_style`, `capital_intensity`, `cost_structure`, the `risk` / `events` / `payout`
overrides, `wholesale_funding_share`, `aoci_filtered`, and the two sector exceptions (OWLS, GDWT).

## Other open items (not seed keys)

- After the reseed: a live read (`make macro-dump`), watching TERN's reorganisations.
- Dividend tier smoothing.
- The noiseless macro running hot.
