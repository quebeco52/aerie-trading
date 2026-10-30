# BRKW evidence

## 2026-10-30: marked-share repricing + expected holding gain (working tree on 907746ce)

Question: do the two BRKW fixes (FairValue reprices only the unmarked share; getExpectedHoldingGainReturn in EVA,
buyback spread, payout saturation, levered-beta business value, divestiture test) behave sensibly?
Harness: `BrkwHarnessTest.php`, arms `base` (HEAD 907746ce archive) and `fix` (working tree), seeds 1-4 x 25 years,
360 ticks/year, live macro, no politics. Table: `brkw_4s25y.out`. Probe read-only (probe0 seed 1 identical).

Key lines (base/fix per seed; paired fix - base, se):
- levered beta max 1.09/0.95, 2.77/0.99, 1.54/0.97, 2.77/0.96; -1.07 (0.43). Mean 1.17 -> 0.93.
- headline EVA negative 100% -> 67-81% of quarters (-0.24, se 0.03); mean -16.6 -> -3.6 $bn/q (+13.1, se 2.7).
- buybacks at P/B > 1: 0/0, 198/0, 0/0, 249/0 $bn. P/B max 0.91/0.98, 2.16/0.88, 0.88/0.93, 1.95/0.87.
- P/B mean ~0.61 both arms; price / perceived fair value mean 0.98 -> 0.94 (inside noise).
- filed ROIC 4.96% -> 5.13% (+0.2pp, se 0.1; unchanged within noise).
- divestitures 3.0 -> 2.0 per run (-1.0, se 0.4); none in year 1.5 in either arm.
- payout (div + bb) / NI 1.44 -> 1.39 (inside noise); debt end 145 -> 196 $bn (+51, se 23); split-adjusted shares
  end/start 0.053 -> 0.095 (both retire 83-97% of shares over 25 years).
- board: end cap 72.1 -> 71.0 $T (-1.1, se 1.4), deaths 0.25 -> 0, defaults +0.75 (se 0.48); SKUA bimodal (fails or
  booms) in both arms.

Caveats: 4 seeds; firm paths desync across arms (shared firm stream), so other firms do not pair. coe is CAPM at the
stored levered beta without the debt-rate floor.

## 2026-10-31: buyback fix (discount past NAV_DISCOUNT_BASE, no recap for the trust)

Question: with buybacks firing only when the discount is wider than 30% and isUnderLeveraged false for the trust, are
BRKW's payout, shares, debt and cash sensible? Arm `bb` (working tree) against `fix` and `base` from 10-30, seeds 1-4 x
25 years, 360 ticks/year. Table: `brkw_bb_4s25y.out` (`analyse3.py 1-4 base fix bb`).

Means base / fix / bb; paired bb - fix (se):
- payout (div+bb)/NI 1.44 / 1.39 / 0.75 (-0.64, 0.08); div/NI 0.29 / 0.30 / 0.34 (+0.05, 0.03); bb/NI 1.14 / 1.10 / 0.41.
- shares end/start (split-adj) 0.053 / 0.095 / 0.55 (+0.45, 0.04). Buyback $ total 1690 / 1680 / 711 bn; fires in
  99% / 99% / 59% of quarters, median P/B at firing 0.54 / 0.59 / 0.56; buybacks at P/B > 1: 112 / 0 / 0 bn.
- debt 75.6 -> end 145 / 196 / 73 bn (-123, 18); debt rises with no BRKW deal 23 / 30 / 0 quarters.
- treasury 126 -> end 115 / 93 / 593 bn (+500, 53); max 226 / 152 / 739; cash/NAV mean 0.10 / 0.08 / 0.25 (+0.18, 0.02).
  Cash/NAV climbs to ~0.22 by year 3 and holds 0.2-0.4 for 22 years; acquisitions draw it down (s4 y8 0.09).
- NAV end 1530 / 1560 / 2630 bn (+1075, 118); marked share 0.57 / 0.58 / 0.39 (cash dilutes the stake book).
- P/B mean 0.61 / 0.61 / 0.67 (+0.06, 0.01), min 0.39, max 0.99, end 0.74; price/anchor 0.98 / 0.94 / 0.99.
- levered beta mean 1.17 / 0.93 / 0.90, max 2.04 / 0.97 / 0.90: pinned at 0.90 every quarter in all 4 seeds (net cash).
- EVA negative share 1.00 / 0.76 / 0.92 (+0.15, 0.07), mean -16.7 / -3.6 / -6.2 bn/q (-2.6, 1.0): idle cash drags EVA.
- divestitures 3 / 2 / 0.75 (-1.25, 0.48); acquisitions 2.5 / 4 / 7.25 (+3.3, 2.8, noise).
- board: cap 72.0 / 71.0 / 72.0 $T, deaths 0.25 / 0 / 0, defaults 1.25 / 2 / 0.5 (inside noise).

Caveats: 4 seeds; other firms do not pair (median firm |end-cap log ratio| 0.18). Recap bond issues are not captured as
events; the zero is the debt-rise proxy.

## 2026-10-31 (2): dividend 0.40 -> 0.60 (arm `pay`)

Question: does raising BRKW's target_payout_ratio to 0.60 (on top of `bb`) hold cash near ~10% of NAV? Arm `pay`
(working tree) vs `bb`, seeds 1-4 x 25 years, 360 ticks/year. Table: `brkw_pay_4s25y.out` (`analyse.py 1-4 bb pay`
then `payx.py 1-4 bb pay`).

Means bb / pay; paired pay - bb (se):
- cash/NAV mean 0.25 / 0.26 (+0.01, 0.02), end 0.23 / 0.26 (+0.04, 0.02); path 0.16 y1, 0.20 y3, 0.24-0.34 y10-y25 in
  both arms. Treasury 142 -> end 593 / 648 bn (+55, 68). Not fixed.
- div/NI 0.34 / 0.52 (+0.18, 0.03); bb/NI 0.41 / 0.31 (-0.10, 0.05); total payout/NI 0.75 / 0.83 (+0.07, 0.02).
  Higher dividend displaced buybacks (fire share 59% -> 51%) and acquisitions (7.25 -> 3.5 deals, 468 -> 165 bn,
  -304 (54); crisis deals 1.25 -> 0.25), so cash barely moved.
- Cash grew ~510 bn while retained NI was ~270 bn (bb: 450 vs 410 with 470 bn of cash deals): ~250-500 bn of cash
  over 25 years comes from outside retained NI (divestiture proceeds, stake distributions). The payout ratio is not the
  lever that sets the pile.
- DPS (split-adj) 0.82 -> 1.25 at start, end 3.8 / 4.7, CAGR 6.2% / 5.4%; cuts 2.25 / 1.25 per run, worst -5.6% to
  -6.6%; div > 2x NI 1.5 / 0.75 quarters; div > NI with borrowing 0 / 0.
- shares end/start 0.55 / 0.62 (+0.08, 0.04); debt end 73 / 74 bn; P/B mean 0.67 / 0.67, min 0.22-0.57, max 0.88-1.05.
- EVA mean -6.2 / -6.2 bn/q, negative share 0.92 / 0.91. Beta pinned 0.90.
- board: cap 72.0 / 71.1 $T, deaths 0 / 0, defaults 0.5 / 0.75 (noise).

Caveats: 4 seeds; other firms do not pair (median |end-cap log ratio| 0.17). Crisis = output gap < -2% in the deal
quarter.
