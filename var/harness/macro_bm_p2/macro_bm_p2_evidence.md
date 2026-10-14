# macro_bm_p2 evidence

## 2026-10-14: Part 2 (world gap, metals on world demand, capital-goods prices) vs HEAD 1479ce7

Question: does Part 2 bring LOON capex/revenue toward Verizon's 12.7%, change non-financial ROIC creep, and tie
metals producers' revenue to world demand?
Harness: `MacroBmP2HarnessTest.php` (see RUN.md). Arms a = HEAD 1479ce7 (`/tmp/claude-1000/headtree`, checked: no
CapitalGoodsPrices.php, no worldActivityGap, loads its own src), b = working tree. Live macro in each arm, fund on,
politics off, 30 years, 360 ticks/year, seeds 1-16 (stage 8 then 16; stopped at 16 as the caller asked).
Tables: `macro_bm_p2_s8_y30_t360.out`, `macro_bm_p2_s16_y30_t360.out`.

Targets (n = 16, paired B - A, se):
- LOON capex/rev y20-30: 18.33 -> 12.45, -5.88 (0.56), t -10.5. EBIT margin y20-30: 19.49 -> 18.27, -1.23 (1.45), t -0.8.
- Median non-fin ROIC creep (y25-30 minus y3-6): -0.84 -> -1.49pp, -0.65 (0.43), t -1.5.
- CNDR corr(yoy dlog rev, yoy d globalDemandGapEma): 0.011 -> 0.294, +0.28 (0.054), t 5.3. WING: 0.248 -> 0.239, t -0.2.
  yoy rev sd: CNDR +0.011 (0.008); WING +0.023 (0.006), t 3.6.
Context: drift-firm capex/rev moves with the sign of drift (LOON -4.8, HUMM -4.1, PLZA +6.5, ELDE +6.6, SHOR +4.7pp, all
|t| > 4); REIT ROIC creep -1.4 to -2.6pp (|t| > 10) and EBIT margin -1.5 to -2.1pp; deaths 0.50 vs 0.44 per seed.
B final `state:ppe_relative_price`: LOON 0.689, SHOR 1.233.

Caveats: arms run their own macro and drift apart, so pairing buys little; ROIC is the report's truePostTaxReturn.
