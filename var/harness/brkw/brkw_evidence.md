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

## 2026-10-31 (3): anchor-stake rework (arm `rw`) vs HEAD 93fde30b (`base`)

Question: what the uncommitted anchor-stake rework (BRKW sits out held firms' buybacks, is paid their dividends,
sum-of-parts NAV, charter-only buying, distress-only divesting, stake deferred tax) does to BRKW and its held firms.
Harness `BrkwHarnessTest.php` (extended: per-tick tenders out of BRKW's blocks, dividends collected inverted from the
trailing rate, cap-return realised vol, held fractions/floats, `nav_sop`, `dtax`, NI split; `end` row). Seeds 1-4 x 25
years, 360 ticks/year; both arms ran the same harness. Table: `brkw_rw_4s25y.out` (`analyse.py`, `payx.py`, `anchor.py`
1-4 base rw). Means base / rw, paired rw - base (se):
- cash/NAV (sum of parts) mean 0.26 / 0.17 (-0.10, 0.02), y10 0.27 / 0.16, y25 0.27 / 0.20, max 0.36 / 0.24.
- fractions rise as designed; float floor binds by y25 in every seed for ALBT (0.35 -> 0.83, float 0.05), CBIL
  (0.50 -> 0.80, float 0.05), ALCA in 3 of 4 (0.83, float 0.05; s4 0.155), ERNE in s2. KSTL 0.41-0.42, BIRD 0.42-0.58.
  Board min float y25 0.05 (ALBT) vs 0.15 (PLZA). Floor tenders 11-169 per run, 42-410 bn.
- dividends received + receivable / paid on blocks 0.993 (0.992-0.996); base receives none (443 bn paid vanished).
  Gap consistent with the probe applying the post-buyback fraction to the pre-buyback share count.
- NI 66 / 87 bn/y (+21, 3.4); split (rw, share of NI): EBIT 0.92, dividends received 0.24, interest ex dividends 0.09,
  interest expense 0.04, tax 0.21. div/NI 0.52 / 0.57, bb/NI 0.31 / 0.21; DPS CAGR 5.4% / 2.8% (opening DPS 1.25 ->
  2.8); cuts 1.25 / 0.25 per run; yield 3.3% / 3.8%.
- P/NAV (engine's NAV: sum of parts in rw, marked book in base) mean 0.67 / 0.72, min 0.22-0.59 / 0.32-0.62, max
  0.88-1.03 / 0.94-1.11, share < 0.5 0.20 / 0.16. Filed P/B mean 0.67 / 0.81 (+0.14, 0.04).
- lbeta 0.90 pinned / 0.82 (-0.08, 0.01). EVA negative share 0.91 / 0.96. Filed ROIC 0.049 / 0.042 (-0.007, 0.003).
- divestitures 0.75 / 0; acquisitions 3.5 / 3.25, all tools except one 29.7 bn horizontal buy of private Driftwood
  Capital (investment industry, 59% goodwill; s4 y23.6). Goodwill impairments 1.25 / 0.75 per run, 32 / 24 bn.
- stake deferred tax (rw): -35 to +50 bn by y5, 205-356 bn at y25 (signed, starts as an asset in two seeds).
- realised vol (cap returns): BRKW 0.177 / 0.177, CBIL 0.174 / 0.180, ALCA 0.207 / 0.209, board 0.138 / 0.138.
- board: cap 71.1 / 71.7 $T (+0.55, 0.22), deaths 0 / 0, defaults 0.75 / 1.25 (noise).

Caveats: 4 seeds; other firms do not pair (median |end-cap log ratio| 0.17); P/NAV compares two NAV definitions.

## 2026-10-31 (4): whole holding return, dividends on shares of record, charter deals (arm `rw2`)

Question: what the three fixes after `rw` (getExpectedHoldingReturn = (k x stakes - t x trailing dividends) / IC;
dividends accrued on sharesAtStart; investsByCharter calls off own-industry deals) do to BRKW. Harness
`BrkwHarnessTest.php`, now also logging the allocator's trueReturn + holding return - hurdle on each BRKW rep row
(`spread`, trailing health read after the report) and `paid_on_blocks_record` (fraction at tick start x total paid =
held shares x DPS). Read-only checked: `probe0` seed 1 OUT record equals `rw2` seed 1 except `secs`. Seeds 1-4 x 25
years, 360 ticks/year; `base` and `rw` are the stored runs. Table: `brkw_rw2_4s25y.out` (`rw2.py 1-4 base rw rw2`).
Means base / rw / rw2, paired rw2 - rw (se):
- EVA negative share 0.91 / 0.96 / 0.56 (-0.40, 0.06); mean -6.2 / -5.4 / -1.5 bn (+3.9, 0.5).
- filed ROIC 0.049 / 0.042 / 0.041 (-0.001, 0.001). Allocator spread (rw2 only) +0.16pp mean, holding return 4.6%
  of IC, spread < 0 in 27% of reports (s2 54%, s4 6%).
- lbeta 0.90 / 0.82 / 0.83 (+0.017, 0.008).
- P/NAV mean 0.67 / 0.72 / 0.74, min 0.41 / 0.42 / 0.40, max 0.94 / 1.02 / 1.09, < 0.5 0.20 / 0.16 / 0.15 (all
  inside noise vs rw). cash/NAV 0.26 / 0.17 / 0.16.
- div/NI 0.52 / 0.57 / 0.56, bb/NI 0.31 / 0.21 / 0.21, DPS CAGR 5.4% / 2.8% / 2.7%, cuts 1.25 / 0.25 / 0, yield
  3.3% / 3.8% / 3.6% (-0.2pp, 0.1). Buybacks at filed P/B > 1: 0 / 0 / 16 bn (t 2.3; 3 of 4 seeds).
- dividends received / paid on blocks: probe's old formula 0.993 -> 1.000; record-date definition 1.000 in all seeds.
- acquisitions 3.5 / 3.25 / 2.75, all operating industries (tools, building products, ports and terminals,
  industrial machinery); no investment-industry buy (Driftwood gone). Largest: s1 248 bn LBO, 170 bn goodwill (69%).
  Divestitures 0.75 / 0 / 0. Goodwill impairments 1.25 / 0.75 / 0.5 per run.
- y25 fractions unchanged: ALBT 0.83, CBIL 0.80, ALCA 0.82 (floats 0.05 in 3-4 seeds), ERNE 0.69; board min float
  0.05.
- vol BRKW 0.177 / 0.177 / 0.179. Board cap 71.2 / 71.7 / 72.7 $T, deaths 0, defaults 0.75 / 1.25 / 1.25.

Caveats: 4 seeds; board aggregates only (firms do not pair); spread read on trailing health after the report, not
the allocator's own call.

## 2026-10-31 (5): MIN_TRADABLE_FLOAT 0.05 -> 0.10 (arm `rw3`)

Question: does BRKW behave sensibly with the tender floor at 10% float. Harness `BrkwHarnessTest.php`, now also
logging ALBT tick cap-return vol and `tender_tax` (ledger rule min(1, proceeds / carrying) x deferred tax on BRKW's
previous tick-end book; an estimate, not logged for older arms). Seeds 1-4 x 25 years, 360 ticks/year; `rw2` stored.
Table: `brkw_rw3_4s25y.out` (`rw2.py 1-4 rw rw2 rw3` + `rw3x.py 1-4 rw2 rw3`). Means rw2 / rw3, paired rw3 - rw2 (se):
- y25 fractions ALBT 0.827 / 0.780, CBIL 0.800 / 0.750, ALCA 0.823 / 0.777 (-0.05, floor binding in all seeds);
  floats 0.10 / 0.10 / 0.103; board min float 0.10 (was 0.05). KSTL, BIRD, ERNE unchanged (ERNE s2 at 0.10).
- tenders 68 / 86 (+17.8, 1.4), 205 / 265 bn (+60, 9); tender tax (rw3) 31 bn, 11.7% of proceeds.
- cash/NAVsop mean 0.159 / 0.170 (+0.011, 0.006), y25 0.175 / 0.219 (+0.044, 0.010); cash y25 603 / 742 bn.
- div/NI 0.556 / 0.557, bb/NI 0.212 / 0.220, yield 3.6% / 3.6%; P/NAV mean 0.735 / 0.750, < 0.5 0.147 / 0.135.
- EVA negative share 0.56 / 0.58 (+0.02, 0.02); lbeta 0.833 / 0.827; stake dtax y25 305 / 291 bn (-14, 14).
- recv/paid on blocks 1.000 in all seeds.
- vol (tick cap-return): CBIL 0.177 / 0.174, ALCA 0.212 / 0.204, ALBT (rw3) 0.378; quarterly cap-return ALBT
  0.395 / 0.388, CBIL 0.206 / 0.200, ALCA 0.235 / 0.225. ALBT at ~2x its siblings is pre-existing, not the change.
- board cap 72.7 / 71.7 $T, deaths 0 / 0.25 (STRK, s1, not held), defaults 1.25 / 1.0.

Caveats: 4 seeds; board does not pair; tender tax an estimate from tick-end books.

## 2026-10-31 (6): held firms return surplus by pro-rata redemption (arm `rw4`)

Question: with the six BRKW holdings (ALBT, CBIL, ALCA, KSTL, BIRD, ERNE) redeeming pro rata instead of buying back,
do the fractions hold, is the redemption cash booked exactly, and what happens to BRKW's cash and the held firms'
per-share paths. Harness `BrkwHarnessTest.php`, now also logging `hrep` (held-firm report: ni, sbc, div, bb, eq, cash,
shares), `red` (each redemption: cash, per-share fact, shares of record), `redtick` (BRKW carrying and deferred-tax fall
on a redemption tick, same-tick subscriptions backed out) and end-row `red_*` totals. Seeds 1-4 x 25 years, 360
ticks/year; `rw3` stored. Table `brkw_rw4_4s25y.out` (`rw2.py 1-4 rw2 rw3 rw4` + `rw3x.py 1-4 rw3 rw4` + `rw4x.py 1-4
rw3 rw4`). Means rw3 / rw4, paired rw4 - rw3 (se):
- stakes: every fraction at its opening value (ALBT 0.35, CBIL 0.50, ALCA 0.50, others 0.35) at y5 and y25 in every
  seed, floats unchanged from the first quarter, tenders 86 / 0, board min float 0.15 (PLZA, not held).
- redemption received / fraction x cash paid 1.000 (worst tick 1e-10), 421 bn over 25 years; tax on it 33.7 bn (7.6%).
  Redemption cash = per-share fact x shares of record 1.000; held-firm equity roll (ni + sbc - div - bb) residual
  median 3e-6 of the payout.
- BRKW cash/NAVsop mean 0.170 / 0.204 (+0.034, 0.004), y10 0.157 / 0.195, y25 0.219 / 0.254 (+0.035, 0.015), max
  0.267 / 0.309; cash y10/y20/y25 327 / 526 / 662 bn. Rising in both arms at a similar slope, rw4 ~3.5pp higher.
- div/NI 0.557 / 0.534 (-0.02, 0.05), bb/NI 0.220 / 0.314 (+0.10, 0.06), DPS CAGR 2.8% / 2.5% (-0.3pp, 0.5), DPS cuts
  0 / 1.5 per run (worst -2.6%), yield 3.6% / 3.95%; P/NAV mean 0.750 / 0.733, < 0.5 share 0.135 / 0.135; EVA negative
  share 0.58 / 0.49 (-0.09, 0.09); lbeta mean 0.827 / 0.802 (-0.025, 0.002); stake dtax y25 291 / 157 bn (-134, 21);
  NAV y25 3.25 / 2.47 T$; NI 85.7 / 86.4 bn/y (+0.7, 10); dividends recv/paid on blocks 1.000.
- held firms (mean of 6, split-adjusted): redemption 0.68% of pre-ex price per quarter (max 2.5%); EPS CAGR 6.3% /
  2.9% (-3.3pp, 0.8), DPS CAGR 5.2% / 2.0% (-3.2pp, 0.4); cash/equity 0.083 / 0.083; total-return vol 0.223 / 0.223;
  price y10/p0 2.04 / 1.38, y25/p0 6.3 / 2.5; TSR 9.75% / 9.39% a year (-0.36pp, 0.21), ALBT 12.4% / 10.9% (-1.6pp, 0.4).
- board cap 71.7 / 71.1 T$ (-0.6, 0.4), deaths 0.25 / 0, defaults 1.0 / 1.75 (+0.75, 0.85).

Caveats: 4 seeds; board does not pair; held-firm treasury fall checked in code (CapitalAllocationEngine executeBuybacks
subtracts totalCashSpent on both branches), not measured. Harness fix after this run: a held firm's split on a
redemption tick was read as a subscription (2 ticks in 8 runs, dropped in rw4x.py); BrkwHarnessTest now skips splits.

## 2026-11-01: 11-01 rw5, the trust's NAV at the market's prices (arm `rw5` vs `rw4` = e35cbe03)

Question: with subsidiaries repriced by `equityRepricing(beta)` in NAV, no second repricing in FairValue and the
structural discount on surplus cash, does BRKW trade at a discount to NAV, and what happens to cash and payouts.
`bin/harness-batch var/harness/brkw/run.sh 1-4 rw5`, seeds 1-4 x 25 years, 360 ticks/year, rw4 stored. Table
`brkw_rw5_4s25y.out` (`rw2.py 1-4 rw3 rw4 rw5` + `rw3x.py`/`rw4x.py 1-4 rw4 rw5` + new `rw5x.py 1-4 rw4 rw5`:
q-row P/NAVsop quantiles, P/mbv, pfv/NAV, px/pfv, cash/NAV path, BRKW TSR). Means rw4 / rw5, paired rw5 - rw4 (se):
- P/NAVsop mean 0.733 / 0.755 (+0.022, 0.027), p10 0.523 / 0.599 (+0.08, 0.05), p90 0.951 / 0.877 (-0.074, 0.013),
  share > 1 0.035 / 0.013 (-0.02, 0.01), share < 0.5 0.135 / 0.018 (-0.12, 0.06).
- P/mbv (same definition both arms) mean 0.805 / 0.792 (-0.013, 0.003), p10 0.632 / 0.610, p90 0.998 / 0.955 (-0.043,
  0.007), share > 1 0.090 / 0.048 (-0.043, 0.010). NAVsop/mbv 1.13 / 1.05 (-0.07, 0.05).
- pfv/NAVsop 0.754 / 0.782 (+0.03, 0.035): rw4 was already at ~0.75, not 1.00, in this harness; pfv/mbv 0.835 / 0.823
  (-0.012, 0.014). px/pfv 0.973 / 0.968 (-0.005, 0.018).
- cash/NAVsop mean 0.204 / 0.264 (+0.060, 0.017), y10 0.195 / 0.289 (+0.09, 0.07; seed 2 0.52), y25 0.254 / 0.271
  (+0.02, 0.03), max 0.309 / 0.434 (+0.13, 0.05); slope y20-25 +0.004 / +0.002 per year (two seeds falling). Cash y25
  662 / 734 bn (+72, 128): the ratio rise is mostly the smaller NAVsop denominator.
- bb/NI 0.314 / 0.302 (-0.01, 0.05), firing share 0.66 / 0.56 (-0.10, 0.035), div/NI 0.534 / 0.534, DPS CAGR 2.45% /
  2.6%, DPS cuts 1.5 / 0.25 per run (-1.25, 0.95), yield 3.95% / 3.94%.
- lbeta 0.802 / 0.782 (-0.020, 0.006), TSR 9.9% / 9.5% a year (-0.4pp, 0.4), NI 86.4 / 84.4 bn/y (-2, 9.5).
- board (does not pair): cap y25 71.1 / 71.4 T$, deaths 0 / 0.25, defaults 1.75 / 0.75.

Caveats: 4 seeds; nav_sop changes definition between arms, so P/mbv is the like-for-like price measure.
