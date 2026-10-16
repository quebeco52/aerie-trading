# Divestiture rule evidence

## 2026-10-16 what triggers plant sales, and can the sale switch the trigger off; 8 seeds x 20y

Question: which branch of `evaluateCorporateDivestiture` fires, on what reading, and whether the sale itself ends
the state that triggered it. Harness `DivestitureHarnessTest.php` + `DivestitureProbe.php` (see RUN.md): the engine's
own context per quarter and per executed sale; run identical to `new_firms/runs/r3v4-1` (checked over 3y).
Working tree as of r3v4, HEALTH=1, POLITICS off, seeds 1-8, 20y, 360 ticks/yr. Table: `r3v4d_8seed_20y.out`.

Classification: 2.5% of 56,947 firm-quarters are eligible (dying 0.3% (se 0.1%), distressed 2.2% (0.1%)); raw
distressed 4.9%, half cleared by the cash buffer. One firm of 89 is structurally distressed at seed ROIC: BRKW
(0.048 vs WACC 0.088), distressed 59% of quarters. Top of the list otherwise: commodity and cyclical firms.

Sales (192, 24/seed): dying 80 (f 0.40, 29% followed by another within 3y, 46% at a loss), distressed 98 (f 0.22,
14% repeat), premium 14. Price = fire-sale floor (0.86 x f x mcap) in the median of every branch. roicTtm change at
the sale exactly 0 (all 192); the engine return next report +0.002 (se 0.001). ND/EBITDA (engine, same tick) median
-1.0; 52% of sales leave the firm net cash; the trailing ND/EBITDA of dying sellers 5.3 -> 0.7 next quarter.
What switches the state off is the proceeds in treasury: 61/80 dying and 95/98 distressed sellers hold the 10% cash
buffer at the next report, which clears both flags. The ROIC never moves.

Dying trigger: 18/80 return < 0, 62/80 EVA < -0.05 with positive return; 30/80 would not be dying at the firm's own
median hurdle, i.e. the hurdle had jumped (rating to CCC/D lifts WACC from ~0.06 to 0.14-0.24).

Cascades (retained < 0.40 within 3y): 10 in 8 seeds. Median time to leave the dying state by itself 0.8y (2 never),
having sold a median 68% of plant by then. WADE seed 1 (only WADE seed with sales; 6 sales y17.3-19.4, kept 0.04):
roicTtm 0.062-0.074 throughout (seed 0.09), ND/EBITDA crossed the 6.5 covenant at y16, rating BB -> CCC, hurdle 0.058 ->
0.152 within a quarter; dying on EVA only. Each sale sheds debt pro rata (f), EBITDA pro rata, and books a loss
(-0.9 to -5.8bn) because the market cap (8bn on 56bn debt) prices the slice below book: ND/EBITDA 6.3 -> 10.3 over the
sales, so the hurdle never falls and the state cannot end. BIRD: 3 single sales in 8 seeds, same hurdle-jump pattern
(seed 8: hurdle 0.237, return 0.082), then the cash buffer stopped it.

Plant sold over 20y, share of opening board net plant: all 0.219 (se 0.024, range 0.13-0.32): dying 0.084,
distressed 0.134, premium 0.001.

Caveats: the opening plant is the first report's net PPE; plant sold is f x net PPE at the sale (no growth
adjustment). The hurdle-jump split (30/80) and debt-shed checks were an ad hoc script, not in `analyse.py`.

## 2026-10-16 r3v5: CAPM hurdle, long-run return, one distress sale per episode; 8 seeds x 20y

Question: does the fix stop repeat sales and cascades, and does the distress go somewhere else? Tree r3v5 = r3v4d
plus (a) hurdle `businessCostOfCapital()` (CAPM, no distress premium), (b) return read on `longRunReturn`, (c) one
distress sale per episode. Probe updated to mirror the method; probe vs production checked bit-for-bit (seeds 1,6,8
x 12y, 0 of 287,365 values differ). HEALTH=1, POLITICS off, seeds 1-8, 20y, 360 tpy; unpaired (seeds diverge).
Table: `r3v5_8seed_20y.out` (`compare.py r3v4d r3v5 1-8`), per-arm detail `r3v5_analyse.out`.

| per seed, mean (se) | r3v4d | r3v5 | diff (se) |
|---|---|---|---|
| dying share of firm-q | 0.003 | 0.000 | -0.003 (0.001) |
| distressed-not-dying | 0.022 | 0.015 | -0.007 (0.002) |
| sales all / dying / distressed / premium | 24.0 / 10.0 / 12.3 / 1.8 | 6.4 / 0 / 4.6 / 1.8 | -17.6 (4.2) |
| plant sold / opening plant | 0.219 | 0.062 | -0.157 (0.027) |
| cascades (< 0.40 in 3y) | 1.25 | 0 | -1.25 (0.45) |
| repeat within 3y (pooled) | 0.19 | 0.04 | |
| episodes open at y20 | 0 | 0.75 (BRKW x5, CNDR x1) | |
| reorganizations | 0 | 1.12 (0.30) | +1.12 (0.30) |
| default events | 0.75 | 2.00 | +1.25 (0.88) |
| SEOs (all emergency/deleveraging) | 1.9 | 8.0 | +6.1 (2.9) |
| revolver refused | 0.25 | 2.62 | +2.4 (0.9) |
| bond-market shut-outs | 27.6 | 15.8 | -11.9 (5.9) |
| failures | 0.25 | 0.38 | +0.13 (0.41) |
| loss quarters / over covenant | 0.021 / 0.030 | 0.021 / 0.033 | inside noise |
| max ND/EBITDA p90 | 10.4 | 11.4 | +1.0 (1.3) |
| median plant multiple y20 | 2.24 | 2.32 | +0.08 (0.08) |
| board mcap y20 / opening | 2.09 | 2.18 | +0.08 (0.17) |

The fix ends the sell-down: no dying sales, no cascades, plant sold down 72%, and sale prices are above book
(distressed p/b 1.62). The distress moves to the financing side for the same firms that cascaded before:
9 reorgs in r3v5 (FALC x3, CASC x2, DIPR, WADE, SINK, ALBT) vs 0, and SEOs concentrated in ALBT 19, SINK 13,
DIPR 12, CNDR 7. Failures: 3 in seed 8 (STRK, TALN, PLVR), not divestiture sellers; 2 TIER in r3v4d.
Board plant, market cap, loss quarters and covenant breaches show no change beyond noise.
Named firms (sales/seed, plant x y20, reorgs over 8 seeds), r3v4d -> r3v5: WADE 0.75/1.65/0 -> 0/1.74/1;
BIRD 0.38/2.05/0 -> 0/2.34/0; BRKW 3.62/0.49/0 -> 1.25/0.89/0; CASC 1.88/1.53/0 -> 0.62/1.80/2;
DIPR 1.38/1.42/0 -> 0/1.88/1; FALC 2.62/1.53/0 -> 0.25/2.38/3; SKUA 0/1.94/0 -> 0/2.17/0.

Caveats: 8 seeds, unpaired; reorg/SEO counts are small and concentrated in a few firms. BRKW stays structurally
distressed (seed ROIC below its hurdle), so its episode never closes: one sale and then it holds.

## 2026-10-16 r3v6: shedding override and return floor removed, episode check as its own step; 8 seeds x 20y

Question: with distressed = long-run EVA < -2pp only (no lifecycle isShedding() override, no DIV_DISTRESS_RETURN_FLOOR)
and the episode check moved to isInOpenDistressEpisode(), are sales driven by the value test alone without more
reorganizations? Probe updated (calls isInOpenDistressEpisode only where the engine does; `dShed` now always false;
new `dEpHeld`); probe vs np* checked bit-for-bit, seeds 1,6,8 x 12y, 0 of 287,365 values differ. Same settings as
r3v5. Table: `r3v6_8seed_20y.out` (`compare.py r3v5 r3v6 1-8`), detail `r3v6_analyse.out`.

| per seed, mean (se) | r3v5 | r3v6 | diff (se) |
|---|---|---|---|
| distressed-not-dying share | 0.015 | 0.009 | -0.006 (0.002) |
| sales distressed / premium / all | 4.6 / 1.8 / 6.4 | 2.0 / 3.3 / 5.3 | dis -2.6 (0.65) |
| plant sold / opening | 0.062 | 0.053 | -0.009 (0.012) |
| cascades / dying sales | 0 / 0 | 0 / 0 | |
| episodes open at y20 | 0.75 | 0.75 (BRKW x5, CASC x1) | |
| reorganizations | 1.12 | 0.12 (SKUA seed 3) | -1.00 (0.32) |
| default events | 2.00 | 0.75 | -1.25 (0.80) |
| SEOs | 8.0 | 7.5 | -0.5 (4.7) |
| revolver refused / shut-outs | 2.6 / 15.8 | 0.75 / 19.9 | inside 2 se |
| failures | 0.38 | 0 | |
| loss quarters / over covenant | 0.021 / 0.033 | 0.021 / 0.032 | inside noise |
| max ND/EBITDA p90 | 11.4 | 11.1 | -0.35 (1.2) |
| median plant multiple / board mcap y20 | 2.32 / 2.18 | 2.32 / 2.08 | inside noise |

Distressed sales (16 pooled) read long-run return 0.051 vs hurdle 0.086, price/book 1.15, no repeat within 3y; BRKW
holds 10 of them (FALC 3, CASC, FNCH, SKUA 1). Premium sales rise 14 -> 26 (p/b 6.5). Reorgs back to the r3v4d
level (0 there); the r3v5 reorgs (FALC, CASC, DIPR, WADE, SINK, ALBT) do not recur, so they came from the override
and floor, or from seed divergence; 8 seeds cannot tell which. SEOs stay above r3v4d (1.9) in both new arms, now
DIPR 20, SINK 14, SKUA 10, CASC 7. Named (sales/seed, plant x, reorgs): WADE 0/1.90/0, BIRD 0/2.24/0,
BRKW 1.25/0.85/0, CASC 0.12/2.09/0, DIPR 0/1.74/0, FALC 0.38/2.32/0, SKUA 0.12/2.13/1.

## 2026-10-16 Corporate distress fixes (worktree-corporate-section1), sanity check
Question: do the distress fixes (Ch11 plan recovery, overdraft booked as emergency debt, bank going-concern test on
wholesale debt, option settlement, BRKW pays for its share of SEOs/buybacks, rescue raise vs buyback count, M&A draws via
MathUtility) behave sensibly. Arms: npbase (HEAD b5492807 with the diff reversed, copy under $TMPDIR) vs npfix (worktree);
NOPROBE, HEALTH=1, seeds 1-4, 20y, 360 tpy; unpaired (stream shifts). Table: corpfix_sanity_4seed_20y.out (compare.py).
Per-seed means: defaults 1.25 vs 1.50, reorgs 0.25 vs 0.25, liquidations 0 vs 0.25 (SHOR, operating, seed 1), SEOs 12.5 vs 4.5
(all emergency, none by financials), shut-outs 26 vs 17, revolver refused 0.75 vs 1.5, ND/EBITDA p90 11.6 vs 10.2,
loss-quarter share 0.027 vs 0.019. All inside 4-seed noise (|t| <= 1.8 on the targets). BRKW cash min 37-81B, end 53-147B
(start 114B) in both arms; no negative-cash firm-quarter in either arm. LAKE wholesale debt 190-280B -> 3.2-5.7T in every
seed of both arms (pre-existing; bond-funded loan-book growth). Fix #3 (bank rescue equity) never exercised: no financial
firm defaulted or raised equity.
