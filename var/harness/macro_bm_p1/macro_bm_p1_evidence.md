# macro_bm_p1 evidence

## 2026-10-14: Part 1 macro -> business-model wiring, HEAD e2c56af (A) vs working tree (B)

Question: do REIT rents stop tracking the real 10y, insurer loss ratios load more on claims inflation, and bank NIM
fall with the policy rate under the new deposit beta. Harness `MacroBmP1HarnessTest.php` (RUN.md), LIVE macro per
arm (replay impossible: B adds `commercialMarketRent` and changes `systemDepositBetaEma`), POLITICS off, fund on.
Seeds 1-16 per arm, 20 years, 360 ticks/yr, quarters after year 2. Stage 8 then 16 (caller's cap). Table:
`macro_bm_p1_s16_y20_t360.out` (stage 8: `macro_bm_p1_s8_y20_t360.out`).

| target | A | B | B-A (se) | t |
|---|---|---|---|---|
| REIT corr(d in_place_rent_index, d real10y), pooled | +0.227 | +0.268 | +0.040 (0.060) | +0.7 |
| REIT corr(dlog lease rev, d real10y) | -0.011 | -0.015 | -0.003 (0.019) | -0.2 |
| SHOR corr(level releasing_spread, real10y) | -0.154 | +0.502 | +0.657 (0.127) | +5.2 |
| SAFE slope loss_ratio ~ excess, lag 2 (peak t) | 2.24 | 3.68 | +1.44 (0.63) | +2.3 |
| DOVE slope loss_ratio ~ excess, lag 2 (peak) | 2.48 | 3.06 | +0.58 (1.05) | +0.6 |
| LAKE / RIVR / PLVR slope NIM ~ policyRateEma | -0.08/-0.10/-0.08 | -0.31/-0.35/-0.33 | -0.23/-0.25/-0.25 (0.03) | -7.8/-9.2/-8.1 |

Context: bank mean NIM -0.16 to -0.23pp (t -2 to -3), ROE -0.9 to -1.3pp (t -1.1 to -1.6); SAFE/DOVE mean loss ratio
+0.4pp (t 1.7 / 1.0); BIRD margin slope on y30 1.70 -> 0.15 (t -2.5), WADE ~0; housing-starts yoy sd 0.0675 -> 0.0696
(t 0.5); importers' cost-ratio slope on fx gap unchanged (t 0.4); PENG shortage rate GSCPI<0 1.7% -> 0.8%, 0-1 2.2% ->
3.3% (16-21 events in all); no non-financial firm's mean EBIT margin moved by > 1pp outside noise (ALBT +1.7, FNCH
-1.9, GANN +1.2, all |t| < 1.3).

Caveats: arms run different macro paths, so pairing gains little. The REIT rent KPI is purely macro-driven (identical
across the four REITs). HEAD shows no negative change-on-change link to remove: in-place rents already co-move
positively with real-rate changes in both arms (rates rise in booms). Insurer slope difference inside 2.5 se: stage 48 needed.
