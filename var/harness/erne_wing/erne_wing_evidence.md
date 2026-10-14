# ERNE / WING evidence

## 2026-10-14 Comm-equipment observable/shortage fix, ERNE Ericsson retune, steel seasonality

Base = 277d009 (HEAD at run time; the brief said c156377, but HEAD had moved on with the LOON commit, so 277d009
isolates the ERNE/WING changes). B = working tree. Seeds 1-16 (stage 8 left the revenue-error target unresolved),
12 y, 360 tpy, macro replayed from var/harness/loon/macro. Table: `erne_wing_s16_y12_t360.out`.

| quantity | a | b | b-a (se) | t |
|---|---|---|---|---|
| ERNE EBIT margin % (Ericsson ~12) | 19.14 | 12.75 | -6.39 (0.54) | -11.8 |
| ERNE EBITDA margin % (~13.8) | 28.79 | 17.30 | -11.48 (0.41) | -28.0 |
| ERNE capex/rev % (~2.2) | 11.76 | 5.21 | -6.56 (0.16) | -41.2 |
| ERNE D&A/rev % (~3.9) | 9.65 | 4.56 | -5.10 (0.13) | -37.8 |
| ERNE sd (act-cons)/act revenue % | 6.03 | 6.02 | -0.00 (0.37) | 0.0 |
| WING sd q/q log revenue | 0.25 | 0.12 | -0.13 (0.01) | -16.6 |
| WING EBIT margin Q1/Q2/Q3/Q4 % | 12.3/22.9/22.5/11.2 | 17.7/18.9/17.7/16.9 | max-min -9.5 (0.6) | -16.6 |

ERNE shortage rate by GSCPI EMA bin, pooled: a <1: 12/633, 1-2: 0/7; b <1: 13/633, 1-2: 2/7. In-game GSCPI EMA
sd 0.37 (mean 0.05, range -0.93..1.53): the GSCPI threshold shift almost never matters. ERNE opening-year revenue
66.4 vs 65.2 $bn; cap/GDP 1.18 vs 0.73 %; ND/EBITDA 0.32 vs 0.21. ERNE rated A throughout, no deaths.
WING: no deaths; seeds rated B at some point a 3/16, b 8/16 (5 seeds b-only, 0 a-only); unexplained.

## 2026-10-14 (b) WING B-or-worse attribution: seasonality, tariff or noise

Arms on seeds 1-16, same macro paths, 12 y, 360 tpy, RATING_PROBE=WING: a (277d009), s (a + steel seasonality
[1.00,1.02,1.00,0.98] patched into treeS_ew only), b (working tree). Probed a and b reproduce the unprobed seeds
exactly (a 5,8,10; b 1,3,5,7,8,10,11,13). Table: `wing_rating_attr_s16.out`.
Tariff: `tariff_check.py 1-16`: max |importTariffRate| = max |tariffTradeLag| = 0 over all 69,120 recorded ticks,
so every tariff term in the steel model is exactly zero.

Seeds at B or worse: a 3/16, s 4/16 (+seed 7), b 8/16 (+1,3,11,13 over s; none lost). Quarters at B or worse:
a 35, s 37, b 42; share of quarters after y2 b-s +0.8pp (se 3.0). In every B-or-worse quarter, all arms, the
leverage anchor is above BB and the Altman zone is Safe: the grade is set by Merton d2 alone (median 1.2-1.3).
Rating inputs (LTM ICR, LTM and time-weighted ND/EBITDA, d2, Altman Z) do not differ between arms (|t| <= 1.7).
Quarterly-only moments moved with the season: min quarterly ICR -0.57 -> 1.27 (s-a t 4.3); mean quarterly x4
ND/EBITDA 2.12 -> 1.68 (t -3.3). The rating reads trailing twelve months (EarningsEngine.php:1259-1261 into
DebtEngine::calculateInterestExpense, rating at DebtEngine.php:175-177) and market d2 (DebtEngine.php:361).
