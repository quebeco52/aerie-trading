# Segments evidence

## 2026-10-26: segment rework phase 2 (blend, tilts, ledger gap) against HEAD be99c551

Question: does the board still behave, and do segments do what they claim (mix leans toward the leading segment's
outlook, identities hold, unsegmented firms untouched, ledger plant grows by the blend)?

Harness: `SegmentsHarnessTest.php` (idle harness + read-only segment probe, checked: `seg` OUT == `segplain` OUT on
seed 1, `secs` dropped). Arms `head` (git archive be99c551), `seg` (working tree), `gapfix` (working tree +
`ovr_gap/` RedisIndustryShareStore that reads `segment_outlook_gap` back). Seeds 1-16 x 20 years, 360 ticks/year,
macro replayed from `../idle/macro/path-<seed>-oc.gz`, outlook salt 1000+SEED. Stage 8 had borderline items (TERN
distress 0 vs 2 seeds, SKYL share t 2.0), so stage 16 was run; stopped there.

    BASE=<scratchpad>/basetree-be99c551 bin/harness-batch var/harness/segments/run.sh 1-16 head seg
    bin/harness-batch var/harness/segments/run.sh 1-16 gapfix
    python3 var/harness/segments/analyse.py 1-16 head seg > phase2_n16.out
    python3 var/harness/segments/mix_response.py 1-16 head seg > mix_response_n16.out

Board (seg - head, n 16): cap y20 49.84 -> 49.68 tn (-0.16, se 0.58); median P/E y10-20 16.89 -> 16.81 (-0.08, se
0.07); median split-adjusted vol 0.2173 -> 0.2190 (+0.0018, se 0.0010); defaults+deaths 0.56 -> 0.75 (+0.19, se
0.21); B-or-worse 1.07% -> 0.95% (-0.12 pt, se 0.08); ND/EBITDA y20 1.375 -> 1.372. All inside noise.

Mix response (mix_response_n16.out): share_seg - share_head on the same report against the log tilt filed the report
before: corr +0.30 to +0.60 for every segment (se 0.05-0.08), pooled slope close to share x (1 - share), i.e. near
full pass-through (ERNE about half). The analyse.py lagged corr(d share, rel outlook) is small (+0.0 to +0.08): the
weight resolver smooths the move, so the change test has little power; the level test above is the one to read.
Shares at y20 (seg - head): SKYL internet +0.053 (se 0.033), ERNE devices -0.020 (se 0.007, from 0.090), OWLS/TRIV
+0.011 to +0.014 toward consumer; others within 0.011.

Identities: segment shares sum to 1 (2e-16); segment revenue sums to report revenue (7e-12); 112,505 unsegmented
reports carry no `carry:segment_*` key. Blend inside [min, max] of segment beliefs in 11,520 of 11,520 reports.

Defect: `RedisIndustryShareStore::readIndustry` (src/Service/Corporate/Industry/RedisIndustryShareStore.php lines
46-57) rebuilds each record from a fixed field list and drops `segment_outlook_gap`. In the harness (and live) the
ledger reads 0 back for all nine, so trend plant and trend demand grow by the own industry's belief only; the blend
reaches only the market sizing (`ctx->industryOutlook`). The leak check on the ledger is vacuous for the same reason.
With the gap read back (`gapfix`), records carry it (SKYL -0.38..+0.96, WEAV/JDAW up to +0.79, TERN -0.03..+0.10);
the BIRD, RAVN, OWLS, TRIV records never get one (no ledger record written for them). Board gapfix - head: all |t| <
1.6.

Nine companies (seg - head, n 16): TERN revenue y20 -6.7% (se 2.4%, t -2.8), op margin -0.37 pt (t -2.6), distress
seeds head {10, 13}, seg {4, 7, 10}, gapfix {4, 11, 13, 16}; the revenue gap is -1.4% at year 2 while blend - main is
0.000, so it is not the outlook blend; cargo share moves only -0.003. Unexplained. SKYL +6.7% (t 1.1); others |t| <
2. OWLS/TRIV growth unchanged (+0.050/+0.061 per year both arms); their yoy revenue does not follow the blend in either
arm (corr -0.05..-0.11).

Tilts: 0.234-1.925; 2.0% of filed tilts outside 0.6-1.6 (ERNE 320, JDAW 81, WEAV 66, SKYL 50). ERNE's Computer Hardware
belief sits about -0.29 below its blend in years 10-20 (mean |log tilt| 0.32).

Caveat: arms diverge from the second report (segmented revenue) and unsegmented prices within days, so pairing buys
little; the TERN result rests on 16 near-unpaired seeds and two tilt arms that agree (-6.7%, -5.9%).

## 2026-10-26: segment rework phase 3 (plant per segment) against HEAD 858bc0f1

Question: with plant kept by segment for TERN, ERNE, WEAV, JDAW, BIRD, RAVN, OWLS, TRIV, does the board still behave, do
the identities hold, do segments act on their own markets, and what happens to the segment industries' peers?

Harness: `SegmentsHarnessTest.php` with the phase-3 probe (kappa, targets, segment outlooks, every segment ledger
record, gap/spare, KPIs, saturation penalty, firm addressable share, end-of-run `TICKER/seg` scan); `analyse3.py`.
Arms `head` (git archive 858bc0f1), `seg` (working tree). 20 years, 360 ticks/year, macro replayed from
`../idle/macro/path-<seed>-oc.gz`. Stages 8 and 16 left eight-firm items at |t| 2-4 (WEAV, OWLS, RAVN P/E), so the
last stage ran seeds 17-32 (all recorded paths); n = 32.

    BASE=<scratchpad>/basetree-858bc0f1 bin/harness-batch var/harness/segments/run.sh 1-8 head seg   # then 9-16, 17-32
    python3 var/harness/segments/analyse3.py 1-32 head seg > var/harness/segments/phase3_n32.out

Board (seg - head, n 32): cap y20 50.26 -> 49.79 tn (-0.47, se 0.34); median P/E 17.34 -> 17.29 (se 0.05); vol
0.2192 -> 0.2187 (se 0.0010); defaults+deaths 1.03 -> 0.91 (se 0.20); B-or-worse 1.03% -> 1.11% (se 0.07 pt);
ND/EBITDA 1.330 -> 1.324; real revenue growth 2.282% -> 2.231%/yr (-0.05 pt, se 0.02, t -2.8: TRIV, OWLS, KTWK, RAVN
revenue below).

Identities (20,224 open-book reports): sum kappa - 1 <= 2e-16; sum segment-record revenue = 4 x report revenue
(5e-15); no kappa <= 0; no `carry:segment_tilt:*` after opening; 320 `TICKER/seg` records, all owned by the eight
(HEAD 0); 225,030 unsegmented reports carry no `carry:segment_*`. Record capacity split = previous report's kappa in
98.2% of reports; 367 reports (WEAV 261, TERN 43, ERNE 35, JDAW 28) differ by up to 0.019, main share higher, which
matches `AcquisitionEngine` line 609 moving kappa between reports (the only other writer; the run log does not record
deals, so unconfirmed). BIRD, OWLS, RAVN, TRIV main records hold capacity 0 (main industry clears no market), so the
sum of record capacity is not the firm's structural capacity for those four.

Opening: a_s TERN cargo 0.024, ERNE devices 0.042, WEAV ads 0.093, JDAW ads 0.033, BIRD merchant 0.38, OWLS 0.051 /
0.052, TRIV 0.099 / 0.064; RAVN terminals 0.9999 in all 32 seeds (capped: terminals revenue 45.5 bn against KTWK, the
only anchored port, at 10.9 bn). RAVN size penalty 0 -> 0.040; all others 0 before and after. Firm addressable share
(CorporateMetrics::calculateAddressableShare) falls at opening, e.g. TERN 0.369 -> 0.153, JDAW 0.323 -> 0.124, TRIV
0.282 -> 0.090 (now share of the sum of segment markets); it feeds StockTracker market_share on pages, DivestitureEngine
scaleRatio, OperatingCapacityEngine ctx->addressableShare.

Own markets: kappa moves slowly (WEAV ads 0.249 -> 0.180 by y20, ERNE devices 0.100 -> 0.085, RAVN terminals 0.295 ->
0.252, TERN cargo 0.100 -> 0.096). corr(d4 log kappa_s, d4 own belief) +0.06..+0.26, vs main belief -0.03..-0.12.
Glut: d log(kappa_s/kappa_main) per report is lower when the segment industry's peer price < 1 for all ten segments
(e.g. ERNE devices -5.2e-3 vs -0.3e-3, WEAV ads -9.1e-3 vs -1.9e-3).

Peers: KTWK price level median 1.002 -> 0.972 (t -31), p5 0.992 -> 0.942, revenue y20 -24% (t -10.6), no distress.
Specialty Industrial Machinery and Household peers p5 price -0.1 to -0.4 pt (t -4 to -7), medians unchanged.

The eight (n 32): TRIV revenue y20 -40% (t -15.7), OWLS -14% (t -6.2), RAVN -10% (t -3.6), margin -0.62 pt (t -8.7),
P/E -0.7 (t -3.5); WEAV -9% (t -2.0), JDAW -5% (t -2.2), BIRD margin +0.32 pt (t 2.6); TERN distress seeds 7 -> 5.
At n 8, TRIV industrial grew 5.6%/yr in HEAD against its industry peers' 3.5%; in seg 3.4% (consumer 6.4% -> 4.4%
against LARK 4.7%): the four whose main industry clears no market had no price or gate before.

Caveat: arms diverge from the second report; pairing buys little on the firm rows. Phase-2 context: 11 of 88 untouched
firms had |t| > 2 at n 16, so |t| 2-2.6 here (WEAV, JDAW, BIRD) is within chance.

## 2026-10-26: phase 3 with the entry-sizing fix (seg2: a_s = min(company share, rev_s / M_peers))

Arm `seg2` = working tree with SegmentBooks::entryMarketShare(); `head` and first-round `seg` runs reused. Seeds 1-16,
then 17-32 (RAVN rows at |t| 2.0-2.6 at n 16). 20 years, 360 ticks/year.

    bin/harness-batch var/harness/segments/run.sh 1-32 seg2
    python3 var/harness/segments/analyse3.py 1-32 head seg2 > seg2_vs_head_n32.out
    python3 var/harness/segments/analyse3.py 1-32 seg seg2 > seg2_vs_seg_n32.out

Board seg2 - head (n 32): cap -0.29 tn (se 0.32), P/E -0.06 (0.04), vol +0.0002 (0.0010), defaults+deaths -0.06 (0.14),
B-or-worse -0.03 pt (0.06), ND/EBITDA -0.023 (0.019), real revenue growth -0.04 pt/yr (0.02, t -1.8). seg2 - seg: all
|t| < 1 except B-or-worse -0.11 pt (t -2.2).

Opening a_s: RAVN terminals 0.148 (= RAVN's share; was 0.9999), size penalty 0 -> 0. BIRD merchant 0.28 (was 0.38,
now capped at BIRD's share). Others unchanged (TERN cargo 0.024, ERNE 0.042, WEAV 0.093, JDAW 0.033, OWLS 0.051/0.052,
TRIV 0.099/0.064).

KTWK seg2 vs head: median price 1.0018 -> 1.0017, p5 0.9924 -> 0.9913 (t -5.7, 0.1 pt), revenue y20 +1.0% (se 1.6%),
no distress. vs seg: median +3.0 pt, p5 +4.9 pt, revenue +25%.
RAVN seg2 vs head: revenue -1.9% (se 3.2%), margin +0.11 pt (se 0.08), P/E 0.0, ND/EBITDA -0.14 (t -1.9). vs seg:
revenue +8.6% (t 3.8), margin +0.73 pt (t 10.6), P/E +0.70 (t 3.7).
TRIV/OWLS seg2 vs seg: revenue +0.3% / +0.2% (se 0.7-0.8%); vs head still -40% / -14%.
Identities as before: sum kappa 2e-16, revenue 5e-15, no kappa <= 0, no tilt left, 320 TICKER/seg records all owned by
the eight; capacity split off by up to 0.015 in the same acquisition-shaped cases.

## 2026-10-26: phase 4, deals by segment (p4 working tree) against 803d2ade (p3)

Probe adds `onEvent`: every " sold its " / " executed a " event of the nine, with kappa after, in seg.json `deals`;
`deals.py` classifies whole-segment sales, slices and acquisitions by segment and checks sold records. Arms `p3`
(BASE = git archive 803d2ade), `p4` (working tree). Seeds 1-16, then 17-32 (eight-firm rows at |t| 2.2-2.9 at n 16).
20 years, 360 ticks/year.

    BASE=<scratchpad>/basetree-803d2ade bin/harness-batch var/harness/segments/run.sh 1-32 p3 p4
    python3 var/harness/segments/analyse3.py 1-32 p3 p4 > p4_vs_p3_n32.out   # kappa paths of p3: ... p4 p3 > p3_paths_n32.out
    python3 var/harness/segments/deals.py 1-32 p3 p4 > p4_deals_n32.out

Board p4 - p3 (n 32): cap +0.45 tn (se 0.27), P/E +0.01 (0.05), vol -0.0002 (0.0009), defaults+deaths +0.13 (0.19),
B-or-worse +0.11 pt (0.06, t 1.8), ND/EBITDA +0.026 (0.021), real revenue growth +0.02 pt/yr (0.02).

Deals (32 seeds): whole-segment sales only ERNE consumer devices, 3 seeds (y10.3 kappa 0.079, y7.8 0.094, y7.2
0.093; premium range). Slices p3/p4: JDAW 9/11, WEAV 6/8, TERN 3/4, others 0-2. Acquisitions p3 -> p4: TRIV 36 -> 276
(industrial 158, consumer 82, main 36), WEAV 334 -> 322 (ads 88), RAVN 77 -> 91 (terminals 25), OWLS 57 -> 64, BIRD
42 -> 39, ERNE 57 -> 67, JDAW 64 -> 80, TERN 66 -> 59. TRIV's cumulative deal value per seed rose from 0.04 to 0.48 of
annual revenue (seeds 1-16): its main market (no ledger market) passed about one deal per seed; segments pass the
gate.

Identities: sum kappa 2e-16; record revenue = 4 x revenue (5e-15); sold records capacity 0 and tick frozen in 139 of
139 later reads; no tilt keys. Capacity split vs previous kappa: every mismatch (p3 340, p4 578 of ~19,900) has a deal
between the two reports, none without, so the phase-3 mismatch is the deal moving kappa between reports.

kappa medians y1/y10/y20 p3 -> p4: WEAV ads 0.249/0.215/0.191 -> 0.249/0.280/0.299; ERNE devices 0.100/0.092/0.081 ->
0.100/0.091/0.081 (sold in 3 seeds); RAVN terminals 0.300/0.299/0.291 -> 0.300/0.301/0.307; OWLS industrial 0.449/
0.428/0.408 -> 0.449/0.429/0.412, consumer 0.351/0.358/0.357 -> 0.351/0.362/0.373; TRIV industrial 0.599/0.580/0.568 ->
0.599/0.607/0.578, consumer 0.301/0.314/0.323 -> 0.301/0.309/0.322.

Deals do not avoid gluts: share of acquisitions into a segment while its industry's peer median price < 1 vs share of
quarters at P < 1: WEAV ads 41% vs 48%, TRIV industrial 55% vs 62%, TRIV consumer 51% vs 44%, RAVN terminals 52% vs
44%. Organic build does avoid them (phase 3 glut test), so acquisitions reverse WEAV ads' drift.

The eight p4 - p3: TRIV revenue +28% (t 8.9), margin -0.56 pt (t -7.3); WEAV +6.9% (t 2.0), margin -0.50 pt (t -2.1);
OWLS +3.8% (t 1.9); others |t| < 1.5. TERN distress seeds 7 -> 6. No distress for the others.

## 2026-10-26: long game, phase 4 working tree, 8 seeds x 40 years (one arm, live macro)

The recorded macro paths hold 20 years (7,200 ticks), so arm `p4live` runs the live macro engine (no MACRO_REPLAY); no
pairing. Outlook salt 1000+SEED as before.

    YEARS=40 bin/harness-batch var/harness/segments/run.sh 1-8 p4live
    python3 var/harness/segments/long.py 1-8 p4live 40 > var/harness/segments/long40_n8.out

kappa median [p10, p90] y1 -> y40: WEAV ads 0.249 -> 0.160 [0.000, 0.498] (sold in 2 seeds, y12.5 at 0.293 and y31.8
at 0.170); ERNE devices 0.100 -> 0.070 [0.000, 0.083] (sold s3 y21.2 at 0.067); JDAW ads 0.200 -> 0.162 [0.092,
0.281]; OWLS industrial 0.449 -> 0.346, consumer 0.351 -> 0.405; TRIV industrial 0.599 -> 0.508, consumer 0.300 ->
0.402; RAVN terminals 0.300 -> 0.289; BIRD merchant 0.201 -> 0.189; TERN cargo 0.103 -> 0.101. No live segment below
0.03; ERNE and WEAV become all main (kappa 1) after a whole sale.
a_s y40: WEAV main 0.346 -> 0.636 [0.310, 0.884] (> 0.5 in 6 of 8 seeds); WEAV ads up to 0.906 (s2 y36.6, kappa
0.143); JDAW main p90 0.925 (> 0.5 in 3 seeds); all others < 0.5 (BIRD main p90 0.465).
Prices: P_s and L_s are not filed. Firm price level median / p5: WEAV 1.024 / 0.919, JDAW 1.024 / 0.934, ERNE 1.008 /
0.933, others 0.98-1.03 / >= 0.946; idle-plant quarters WEAV 3%, JDAW 6%, others 0. Segment record revenue/capacity
medians 0.94-1.12.
Growth: segment record revenue growth tracks its industry's listed peers within about 1 pt/yr per decade after
y10 (e.g. OWLS industrial 3.5-3.9% vs 3.4-3.6%, RAVN terminals 4.5-5.4% vs 4.4-4.6%); y0-10 TRIV industrial 5.3% vs
3.3%, TERN cargo 2.2% vs 4.8%.
Deals: whole sales 3 in 40 years x 8 seeds; acquisitions per decade TRIV 49/13/15/13, WEAV main 27/25/26/26.

DEFECT (measured): after a whole-segment sale the sold segment's streams keep producing revenue at the old share
(WEAV s1: ads streams 24-29% of revenue for 27 years after the y12.5 sale; ERNE s3 5-8%; WEAV s7 14-18%) while its
kappa is 0 and no ledger record books it: sum of live record revenue misses 4 x report revenue by up to 29% at y40.
The phase-4 n=32 revenue identity silently skipped these reports (the frozen sold record failed the one-tick filter:
19,981 of 20,120 checked = the 139 post-sale reads), so "exact" there was wrong for post-sale reports.
Cause (measured on WEAV s1): `carry:segment_target:ads_and_cloud` stays at its pre-sale 0.319 for the rest of the game
while main's target is 1; SegmentBooks::splitToTargets (called from StreamContext.php:351) gives the sold lines
0.319 / 1.319 = 24% of revenue. Kappa-0 segments are skipped when targets are filed, so the stale key is merged back.
Fix: drop the sold segment's target (and market/outlook keys) at the sale, or skip kappa-0 segments in splitToTargets.

## 2026-10-26: segment economics + eight review fixes (econ) against b77f19c5 (p4c); econ 40 years

Probe adds per report `smar`/`stur` (carry:segment_margin/turnover) and the stock's stable margin `fmar` and asset
turnover `ftur`; `econ.py` reads them. Arms `p4c` (BASE = git archive b77f19c5) and `econ` (working tree), seeds 1-16
then 17-32 (TERN distress 2 -> 6 seeds at n 16); `econlive` 8 seeds x 40 years on the live macro.

    BASE=<scratchpad>/basetree-b77f19c5 bin/harness-batch var/harness/segments/run.sh 1-32 p4c econ
    python3 var/harness/segments/analyse3.py 1-32 p4c econ > econ_vs_p4c_n32.out
    python3 var/harness/segments/econ.py 1-32 p4c econ > econ_econ_n32.out
    python3 var/harness/segments/deals.py 1-32 p4c econ > econ_deals_n32.out
    YEARS=40 bin/harness-batch var/harness/segments/run.sh 1-8 econlive && python3 var/harness/segments/long.py 1-8 econlive 40 > econ_long40_n8.out

Board econ - p4c (n 32): cap -0.14 tn (se 0.30), P/E 0.00 (0.05), vol +0.0003 (0.0013), defaults+deaths 0.00 (0.21),
B-or-worse -0.12 pt (0.08), ND/EBITDA -0.023 (0.020), real revenue growth 0.00 (0.02).

Economics (econ, medians y1/y10/y20): WEAV ads margin 0.240/0.176/0.147, main 0.040/0.043/0.046; firm stable margin
0.090/0.089/0.087 (p4c 0.090/0.087/0.085; y20 +0.0037, t 2.1). Every change in WEAV's ads margin falls on a report
after an acquisition into ads (seed 9, no ads deal, stays 0.240): AcquisitionEngine.php:408 sets the target's margin
from the acquirer's company margin x U, and SegmentBooks::acquireIntoBooks blends it into the segment, so ads and main
margins converge and the firm margin does not rise. Other segment margins hold (JDAW ads 0.273 vs main 0.182; RAVN
terminals 0.224 vs 0.090; BIRD merchant 0.091 vs 0.227; ERNE devices 0.051 vs 0.128; TERN cargo 0.073 vs 0.092).
Realised op margin econ - p4c: WEAV +0.83 pt (t 3.1), JDAW +0.53 pt (t 3.3), ERNE +0.21 pt (t 1.8).

kappa y1/y5/y10/y20 (econ): WEAV ads 0.400/0.455/0.519/0.520 (p4c 0.250 -> 0.336); JDAW ads 0.333 -> 0.318; RAVN
terminals 0.632 flat; ERNE devices 0.069 -> 0.055; BIRD merchant 0.123 -> 0.117 (kappa re-split at opening by 1 /
turnover).

Identities: sum of all record revenue = 4 x revenue in every report including post-sale, 4.7e-15 (p4c 9.4e-2); no live
record stale. |segment revenue share - filed target| median 0.0111, p95 0.041 (p4c 0.017, 0.072). Record rev/cap over
the company's within 0.92-1.01 medians, p10-p90 0.74-1.12. Sold record: revenue 0, capacity kept, tick frozen (deals.py
"violations" count capacity != 0 and is now by design).

Whole-segment sales: econ 1 (ERNE devices s12 y5.2, kappa 0.064, $4.1bn = 12.3x segment EBIT of $0.33bn); p4c 3 (ERNE
devices, 10.8-21.7x EBIT at the firm's margin).

The eight econ - p4c: revenue ERNE -7.4% (t -2.5), others |t| < 1; TERN distress seeds 6 -> 10 (seeds 1, 3, 7, 13 new,
none lost); phase-3/4 runs had 5-7.

40 years (econlive, 8 seeds): kappa y40 WEAV ads 0.543 [0.216, 0.656], ERNE devices 0.040 [0.014, 0.059] (< 0.03 in 2
seeds, no sale), JDAW ads 0.291, RAVN terminals 0.642, BIRD merchant 0.111; WEAV main a_s 0.532 [0.419, 0.971] (> 0.5 in
6 seeds), WEAV ads a_s p90 0.598, JDAW main a_s p90 0.828; no whole sales in 40 years; identities exact at y40 (9e-16);
TERN 4 defaults/reorgs, WEAV 1; WEAV firm margin 0.090 -> 0.085 by y20.

## 2026-10-26: econ2 (segment-margin targets, conglomerate float fix, OWLS reseed) vs b77f19c5 (p4c); TERN trace

Arms `p4c` (BASE = git archive b77f19c5), `econ2` (working tree + `ovr_pl/` SegmentBooks copy that only logs the
segment prices/loads/running of each aggregate() call into the probe's `pl`; checked: econ2 OUT == segplain OUT on seed
1, 2 years), `econ2nj` (`ovr_nj/`: same copy with avoidableCostRatio returning the company ratio for every segment,
undoing review fix 8). Seeds 1-16, 20 years, 360 ticks/year. Stopped at 16: the TERN question is answered exactly
(below) and the board is inside noise; OWLS differs by its reseed, so its rows are not a paired effect.

    BASE=<scratchpad>/basetree-b77f19c5 bin/harness-batch var/harness/segments/run.sh 1-16 p4c econ2 econ2nj
    python3 var/harness/segments/analyse3.py 1-16 p4c econ2 > econ2_vs_p4c_n16.out
    python3 var/harness/segments/econ.py 1-16 p4c econ2 > econ2_econ_n16.out
    python3 var/harness/segments/round.py 1-16 p4c econ2 econ2nj > round_n16.out

Board econ2 - p4c: cap +0.10 tn (se 0.45), P/E +0.06 (0.06), vol +0.0025 (0.0016), defaults+deaths 0.00 (0.27),
B-or-worse -0.10 pt (0.07), ND/EBITDA -0.013 (0.023), real revenue growth -0.02 pt/yr (0.03).

OWLS (median, p4c -> econ2): opening revenue 43.0 -> 63.7 bn, EBIT margin 0.400 -> 0.271, interest income 1.4 -> 1.7
bn, NI 15.0 -> 15.4 bn, ROE 0.171 -> 0.141; y20 revenue 102 -> 146 bn, margin 0.399 -> 0.258, interest income 0.1 ->
0.1 bn, NI 31.8 -> 29.0 bn, ROE 0.306 -> 0.241, P/E 17.2 -> 18.2, cap 546 -> 523 bn; no distress. Segments
industrial/consumer kappa 0.487/0.513 -> 0.436/0.564, margins 0.296 / 0.237; main holds no kappa key.
TRIV: opening 62.1 bn, margin 0.156 (unchanged); y20 revenue 157.7 -> 150.6 bn, margin 0.141 -> 0.142, NI 15.1 ->
14.0, ROE 0.167 -> 0.161, P/E 16.9 -> 18.7, cap 249 -> 241; kappa industrial 0.708 -> 0.609, consumer 0.292 -> 0.391;
margins 0.165/0.161 and 0.149/0.142. Interest income on both is ~0 at y20 in both arms.

WEAV (econ2): company margin 0.090/0.098/0.112 at y1/y10/y20 (p4c 0.090/0.087/0.085; y20 +0.0235, t 7.6); ads margin
0.240/0.229/0.209, main 0.040/0.039/0.039; ads turnover 1.84/1.25/0.93 and company turnover 1.64 vs 2.07 at y20 (t
-4.3): bought ads capital carries the segment margin at a lower return, so ads ROIC (margin x turnover) falls 0.44 ->
0.19.

TERN trace (measured): econ2 and econ2nj are byte-identical in all 16 seeds (OUT equal with `secs` dropped). No segment
of any firm had a load below 1 in 10,112 logged reports, so the avoidable-cost ratio never enters a segment's clearing
and fix 8 cannot act. TERN distress seeds: p4c {4, 10}, econ2 {1, 4, 10, 13}, econ2nj the same. In the year before
the first default the passenger price was 0.948-0.998 (non-failing seeds 1.008-1.036), op margin -0.048 to +0.014
(others 0.085-0.111), ND/EBITDA 5.7-56 (others ~2); cargo price 0.967-1.006, loads 1.000, cargo revenue share
0.094-0.119 (others ~0.10). The same windows in p4c are weak too (s10 ND/EBITDA 35.6, s4 margin 0.001): the extra
seeds are passenger-market downturns on divergent paths, not cargo (inferred).

Identities (econ2): sum kappa 2e-16; sum of all record revenue = 4 x revenue on every report including post-sale
(5e-15; p4c 4.3e-2). Record capacity now splits on revenue targets, not kappa: |cap share - previous report's target|
median 0.007, p95 0.031 (vs kappa 0.060 / 0.30), so analyse3's kappa-split check is obsolete for this tree.
Whole-segment sales econ2: ERNE devices twice (s2 y3.8 kappa 0.064, 16.4x segment EBIT; s7 y16.7 0.060, 14.8x).

## 2026-10-26: econ3 (takeover assets at revenue / the joined segment's turnover) vs econ2, 8 seeds x 20 years

Arm `econ3` = working tree (no ovr); econ2 runs reused. Probe adds `gw` (stock goodwill) and `tax` (effective rate,
median 0.170). n = 8 is a sense check.

    bin/harness-batch var/harness/segments/run.sh 1-8 econ3
    python3 var/harness/segments/analyse3.py 1-8 econ2 econ3 > econ3_vs_econ2_n8.out
    python3 var/harness/segments/econ.py 1-8 econ2 econ3 > econ3_econ_n8.out

WEAV ads (median over seeds, y1/y10/y20): turnover econ2 1.841/1.104/0.970 -> econ3 1.841/1.841/1.841; margin
0.240/0.223/0.210 -> 0.240/0.229/0.215; ROIC (1 - t) m T 0.367/0.204/0.169 -> 0.367/0.350/0.329. WEAV company y20:
margin 0.106 -> 0.100 (-0.005, se 0.005), turnover 1.78 -> 2.68 (+0.93, t 8.0), goodwill 94 bn in econ3 (not recorded
for econ2). Board econ3 - econ2: cap +0.58 tn (se 0.65), P/E -0.04 (0.08), vol -0.0014 (0.0019), defaults+deaths
-0.13 (0.30), B-or-worse +0.30 pt (0.09, t 3.3: ALBT, FALC, WING, unrelated to segments), ND/EBITDA 0.00, real growth
+0.06 pt/yr (0.04). Eight: JDAW op margin +0.91 pt (t 4.7), WEAV -0.73 pt (t -2.0), others |t| < 1.5.

## 2026-10-26: SKYL plant by segment (skyl, working tree) vs 642a842c (head), 4 seeds x 20 years (sanity)

Probe adds `sky` for SKYL (planned share from SpaceSystemsBusinessModel::resolveSegmentPath, ledger
resolveSegmentCapacityGap for satellite_internet) and record `path`/`apath`. `sky.py` reads them.

    BASE=<scratchpad>/basetree-642a842c bin/harness-batch var/harness/segments/run.sh 1-4 head skyl
    python3 var/harness/segments/sky.py 1-4 head skyl > skyl_n4.out

Internet enters at y4.36 in all seeds. kappa / planned share: y5 0.030/0.035, y8 0.227/0.233, y10 0.364/0.368, y15
0.483/0.504, y20 0.503/0.542. Capacity gap after entry median +0.066 (p5 -0.063, p95 +0.160, min -0.160); one entry
spike in seed 3 (+0.75 at y4.6, +0.30 by y5.4). Internet revenue share head/skyl y10 0.283/0.313, y20 0.565/0.517.
SKYL head -> skyl: revenue y20 90.6 -> 84.0 bn; real growth y1-10 10.0% -> 8.2%, y10-20 8.0% -> 8.4%; op margin
0.175 -> 0.193; P/E y10-20 14.4 -> 13.6; cap y20 220 -> 212 bn; ND/EBITDA y20 0.54 -> 0.65; worst rating B/BB, no
default, no whole sale. Telecom: LOON/SKUA price y12-20 about 0.01-0.03 lower in skyl; LOON revenue y20 196 -> 170 bn,
SKUA 75 -> 84; internet record 14% of telecom record revenue by y15-20. Identities: sum kappa 2e-16; main + internet
record revenue = 4 x revenue (3e-14); path/anchor_path on every post-entry report; no internet kappa key before
entry. Board (n 4): cap -0.97 tn (se 1.53), defaults+deaths 0, B-or-worse -0.17 pt (se 0.08), P/E +0.11 (0.07).

## 2026-10-26: wave 2 of segments (8 more companies) vs d29edb90, 8 seeds x 20 years

Arms `head` (BASE d29edb90), `wave2` (working tree), `wave2pl` (wave2 + refreshed `ovr_pl/`; OUT equal to wave2 on
all 8 seeds). run.sh writes the outlook log (OUTLOOK_OUT).

    BASE=<scratchpad>/basetree-d29edb90 bin/harness-batch var/harness/segments/run.sh 1-8 head wave2 wave2pl
    python3 var/harness/segments/wave2.py 1-8 head wave2 wave2pl > wave2_n8.out

Books open y0.31-0.39 for all eight. kappa y1 -> y20: GDWT technology 0.552 -> 0.615, TSTN 0.101 -> 0.125, AVCT 0.302
-> 0.350, HUMM cloud 0.488 -> 0.437, STAR learning software 0.144 -> 0.153, FULM agro 0.246 -> 0.255 / specialty
0.323 -> 0.304, KSTL transit 0.500 -> 0.509, GULL property 0.886 -> 0.910. None below half its opening; no whole
sales, defaults or reorganisations. Real growth y1-10 / y10-20 head -> wave2: GDWT 3.8/3.2 -> 4.2/3.7%, STAR 3.6/3.2 ->
3.9/2.7%, AVCT 2.9/3.1 -> 3.7/3.7%, HUMM 6.5/1.9 -> 5.4/2.9%, GULL 5.8/4.0 -> 4.2/3.3%. Cap y20 paired log ratio:
GDWT +0.22 (se 0.12), GULL -0.10 (0.10; median 478 -> 356 bn), others |t| < 1. GULL op margin 0.246 both arms.
Outlooks: all seven new industries open at quarter 1 (y0.25) in every seed; existing industries' belief step at q1
differs from head's by at most 0.0013 (the q1 step itself is 0.065-0.137 in both arms, above the usual p95 0.06).
Identities over 10,744 reports of 17 companies: sum kappa 2e-16, record revenue = 4 x revenue 4e-14, no foreign
TICKER/seg owners. Board (n 8): cap +0.85 tn (se 0.67), defaults+deaths +0.25 (0.25), B-or-worse -0.06 pt (0.12),
P/E 0.00 (0.07), vol +0.0018 (0.0023); wave-1 caps |t| <= 1.6. IT services segment price (three consultancies):
1.00-1.036 by year, p5 0.96, loads always 1. HUMM main price drifts to 0.93 by y20 (p5 0.88).

## 2026-10-26: phase 5a, capital follows return (p5a) vs 526ab166, 8 seeds x 20 years

Probe: EarningsReportedEvent listener (`cap` rows: gate = CapitalBudgetEngine::returnOnNewPlant < growthHurdle read
at report end, allocation organic_capex, capex split, revenue, net PP&E, style) and `builds` per segment.

    BASE=<scratchpad>/basetree-526ab166 bin/harness-batch var/harness/segments/run.sh 1-8 head p5a
    python3 var/harness/segments/p5a.py 1-8 head p5a > p5a_n8.out

Leak (one-plant, non-financial): gate-failing reports with treasury organic capex > 0: head 7 of 3,715 (0.1 bn per
seed), p5a 0 of 3,761. Growth capex / revenue 0.0245 -> 0.0248 (+0.0003, se 0.0003).
Harvest: segments with builds = 0 most often: ERNE devices 57%, TERN main+cargo 32%, WEAV main 33%, TRIV industrial
31%, HUMM cloud 23%, WEAV ads 20%, BIRD merchant 19%, SKYL main 19%; 13 segments never fail. Kappa drift after a
failing report median -0.9%/yr (mean -1.3%, n 1,880). Largest mix shifts (head -> p5a): TRIV industrial y20 0.614 ->
0.650, WEAV ads y20 0.433 -> 0.464 (both empire builder / steward firms). Whole sale: ERNE devices once (s7 y10.6).
Segmented empire builders: AVCT, ERNE, HUMM, RAVN, STAR, TRIV.
Gluts (price level < 0.97): 10.94% -> 10.98% of firm-quarters, median spell 3 quarters both, p90 22.
Board (n 8): cap -0.09 tn (se 0.32), defaults+deaths +0.13 (0.35), B-or-worse -0.04 pt (0.07), P/E +0.00 (0.04),
vol -0.0003 (0.0021), real growth +0.03 pt/yr (0.06), net PP&E growth -0.03 pt/yr (0.07), capex/revenue +0.0023
(0.0026). Identities: 10,744 reports, sum kappa 2e-16, record revenue 4e-14.

## 2026-10-26: one-plant harvest (p5b1) vs e2e9fb3d, 8 seeds x 20 years

    BASE=<scratchpad>/basetree-e2e9fb3d bin/harness-batch var/harness/segments/run.sh 1-8 head p5b1
    python3 var/harness/segments/p5b.py 1-8 head p5b1 > p5b1_n8.out

Bites in 5.1% of 38,400 one-plant reports; plantReplaced median 0.905 when < 1 (p10 0.761, min 0.528). Top
industries: farm & heavy machinery 33%, nuclear equipment 27%, autos 24%, semiconductors 14%, building products 12%,
telecom 10%; tickers BUZT 49%, FIRE 27%, EIDR 26%, FALC 22%. By style: fortress 9.5%, empire builder 8.3%, steward
4.5%, operator 3.6%. Net PP&E log growth over the 4 reports after a harvested report: head +1.26%, p5b1 +0.58%
(other reports +4.4% both). Output gap < 0: 5.45% harvested, >= 0: 4.61% (gap < -2%: 6.17%); higher in negative
gaps in 6 of 8 seeds. Gluts unchanged: 10.98% -> 10.94% of firm-quarters (se 0.07 pt), median spell 3, p90 22,
mean glut price 0.9455 -> 0.9460. Board: all |t| <= 1.5 (defaults+deaths -0.25, se 0.16; maintenance capex/revenue
-0.0003, se 0.0003). Reports 7754 vs 7760 per seed (one firm death differs), 97 companies both; identities exact;
8/8 PHPUnit runs pass in each arm.

## 2026-10-26: div1 (5b-1 entry infrastructure) vs f64f5afa, 4 seeds x 20 years: output-identical

    BASE=<scratchpad>/basetree-f64f5afa bin/harness-batch var/harness/segments/run.sh 1-4 head div1
    python3 var/harness/segments/same.py 1-4 head div1 > div1_n4.out

All 4 seeds byte-identical: run output (secs dropped), quarter log, listener rows (capex, maintenance capex, net
PP&E, price level, plant load), segment rows (kappa, targets, records), idle rows, deals, defaults, reorganisations,
y20 caps. 7,760 reports, 97 companies, 1,343 segmented reports per seed in both arms. No venture momentum key and no
TICKER/x: record in div1.

## 2026-10-26: 5b-2 market entry (div2) vs ae6f0c4f, 8 seeds x 20 years

    BASE=<scratchpad>/basetree-ae6f0c4f bin/harness-batch var/harness/segments/run.sh 1-8 head div2
    python3 var/harness/segments/div2.py 1-8 head div2 > div2_n8.out

Entries 239 (25-32 per seed, 1.49 per year), 60 distinct companies, 216 firm-seeds; closeness 0.25: 116, 0.5: 110,
0.8: 13; operator 108, empire builder 93, steward 27, fortress 11; top targets IT services 25, internet retail 16,
software - application 14, industrial distribution 14. Capex/cap median 0.0093 (max 0.065).
Ventures stay small: kappa / revenue share median +1 y 0.020/0.024, +2 0.024/0.033, +4 0.023/0.035, +8 0.021/0.031;
end kappa median 0.020 (p10 0.014, p90 0.027), 3 of 239 below 0.005. Learning drag at +1 y 0.0094 of revenue.
Learning gap below 1 after a median 4.8 years (202 of 239). Builds at +4 y: 184 yes / 25 no; +8 y: 145 / 28 (66 too
late to tell). Incumbent price change (q4-12 after vs 4 q before) -0.0064; difference vs head's same windows
-0.0022 median (183 entries with listed incumbents); venture price q4-12 1.011.
Board div2 - head: real revenue growth +0.13 pt/yr (se 0.05, t 2.5); B-or-worse +0.19 pt (se 0.12, t 1.5); cap +0.23
tn (0.80), defaults+deaths +0.13 (0.23), P/E +0.01, vol 0.0000, net PP&E growth 0.00, capex/revenue +0.0014 (0.0020).
Identities: 10,436 venture rows, sum streams = revenue 4e-16, sum kappa 2e-16, pending <= CIP always, no firm with
venture keys and no entry; 62,080 reports and 97 companies per arm.

## 2026-10-26: 5b-2 after the architect's fixes (div3) vs ae6f0c4f, 8 seeds x 20 years

    BASE=<scratchpad>/basetree-ae6f0c4f bin/harness-batch var/harness/segments/run.sh 1-8 head div3
    python3 var/harness/segments/div2.py 1-8 head div3 > div3_n8.out
    python3 var/harness/segments/front.py 1-8 div3 >> div3_n8.out

div2 -> div3: entries 239 -> 227 (1.49 -> 1.42 per year; per seed 20-36), companies 60 -> 59; closeness 0.25/0.5/0.8
116/110/13 -> 108/109/10; operator 108 -> 116, empire builder 93 -> 75, steward 27 -> 29, fortress 11 -> 7; capex/cap
median 0.0093 -> 0.0085. Top targets div3: IT services 27, software - application 17, internet retail 14, wineries
14, internet content 12. Venture kappa/share +1 0.021/0.021, +2 0.024/0.027, +4 0.023/0.026, +8 0.021/0.024 (div2
0.020-0.024 / 0.024-0.035); drag +1 y 0.0107 (0.0094); learning < 1 after 4.8 y (183 of 227); builds +4 166 yes / 26
no, +8 134 / 14. Incumbent price change -0.0016, vs head -0.0022 (158 entries). Front-running: entrant excess log
return over its sector, reports 0-8: div3 -0.0067 (se 0.030, n 208), placebo -0.0001 (0.030, n 214); div2 -0.019 /
+0.012. Board div3 - head: real growth +0.09 pt/yr (se 0.04, t 2.0), B-or-worse +0.22 pt (se 0.11, t 1.9), others
|t| <= 1.1. Identities: 9,515 venture rows, streams = revenue 3e-16, sum kappa 3e-16, pending <= CIP, no venture
keys without entry; 62,080 reports and 97 companies per arm.

## 2026-10-26: 5b-2 with venture growth, outcomes and abandonment (div4) vs ae6f0c4f, 8 seeds x 20 years

    BASE=<scratchpad>/basetree-ae6f0c4f bin/harness-batch var/harness/segments/run.sh 1-8 head div4
    python3 var/harness/segments/div2.py 1-8 head div4 > div4_n8.out; front.py 1-8 div4 >> ...; div4.py 1-8 head div4 >> ...

Entries 141 (0.88/yr; div3 1.42), per seed 14-25, 52 companies; closeness 0.8/0.5/0.25 6/85/50; operator 69, empire
builder 53, steward 12, fortress 7; top targets IT services 14, software - application 13, wineries 11, tools 10.
Abandoned within 4/6/10 y: 0%/13%/17% (0.5: 0/15/21%, 0.25: 0/12/13%, 0.8: 0/0/0%); 19 of 141, median 5.2 y after entry.
Successful (not abandoned, building at +8 y): 58 of 92; kappa median/p90/max +4 0.042/0.056/0.138, +8 0.067/0.114/
0.393, +12 0.097/0.168/0.282; revenue share +8 0.055/0.172/0.454, +12 0.084/0.202/0.481. Abandoned kappa 0.034 at exit,
0.020 at +4 y, 0.015 at +8 y. Biggest pivots: CANV freight -> internet retail 0.60 of revenue at y19.8 (only venture
above 50%), DIPR IPP -> electrical equipment 0.48, LOON telecom -> communication equipment 0.46, BREW restaurants ->
wineries 0.45, WATCH security -> IT services 0.33. Successful ventures' price change vs year 1: -0.005 at +4, +0.005
at +8; incumbents vs head -0.003 / -0.005 (mean -0.008 at +8). Front-running: entrant excess +0.050 (se 0.030, n 125)
vs placebo +0.014 (se 0.029), difference +0.036 (se 0.042). Board: real growth +0.11 pt/yr (se 0.04, t 2.6),
B-or-worse +0.19 pt (t 1.6), others |t| <= 1.0. Identities exact; abandoned kappa rises in 33 reports by at most
0.2% relative (share renormalisation); no entry while a non-zero pending or a live learning gap >= 1 existed (8
entries followed a zero leftover pending key).

## 2026-10-26: div5 (distance shortfall in venture outcomes, zero pending keys cleared) vs ae6f0c4f, 8 seeds x 20 years

    BASE=<scratchpad>/basetree-ae6f0c4f bin/harness-batch var/harness/segments/run.sh 1-8 head div5
    div2.py 1-8 head div5 > div5_n8.out; front.py 1-8 div5 >> ...; div4.py 1-8 head div5 >> ...

Entries 143 (0.89/yr; div4 141), 50 companies; closeness 0.8/0.5/0.25 7/83/53. Abandoned within 6 / 10 y: 0.25 22% of
40 / 31% of 32; 0.5 22% of 58 / 30% of 44; 0.8 0% of 6 / 0% of 4; all 21% / 29% (div4 13% / 17%; 0.25 12/13%, 0.5
15/21%). Successful 49 of 90: kappa +8 0.069/0.098/0.196, +12 0.105/0.163/0.257; revenue share +8 0.060/0.157/0.239,
+12 0.084/0.250/0.404 (median/p90/max). Top pivots: LOON telecom -> communication equipment 0.42, TICK exchanges ->
IT services 0.42, CANV freight -> internet retail 0.41 (s4); none above 50%. Board div5 - head: real growth +0.10 pt/yr
(se 0.04, t 2.6), B-or-worse +0.22 pt (t 1.7), others |t| <= 1.2. Front-running: entrant -0.002 (se 0.034, n 129) vs
placebo +0.042 (0.035), difference -0.043 (0.049). Identities exact; zero pending keys 0 of 5,933 venture rows (div4
1,434 of 6,013); no entry with a pending venture or live gap >= 1.

## 2026-10-26: 5b-3 buy entry (buy1) vs 3f1aa807, 8 seeds x 20 years

    BASE=<scratchpad>/basetree-3f1aa807 bin/harness-batch var/harness/segments/run.sh 1-8 head buy1
    python3 var/harness/segments/div6.py 1-8 head buy1 > buy1_n8.out; div2.py 1-8 head buy1 > buy1_div2_n8.out

Buy entries 52 (0.33/yr; per seed 3-11) by 23 companies; build entries 130 (0.81/yr; div5 0.89). Closeness of buys
(from the buyer's home industry): 0.5 26, 0.25 15, unresolved 11 (pair off a held segment), 0.8 none; builds 0.8 8,
0.5 71, 0.25 51: distant share 37% buy vs 39% build. Buyers: empire builder 36, operator 9, fortress 6, steward 1;
conglomerate expansion 34, LBO 14, strategic 4; 5 of 52 segmented. Trigger (no own segment building) held at 55 of
1,517 deals; 52 of them became entries (3.4% of deals). Deals 9.5/yr both arms.
At entry: kappa median 0.043 (p90 0.087, max 0.114); share of parent revenue 0.063 (p90 0.24, max 0.65); share of
its industry 0.020 (p90 0.13, max 0.36); price / parent cap 0.056 (p90 0.14, max 0.32); goodwill 52% of price.
Industries: agricultural inputs 7, communication equipment 5, electrical equipment 5. Kappa +4/+8/+12 y median 0.044/
0.042/0.032; gate fails 37% of bought-segment reports; no whole sales within 10 y; 4 bolt-ons. Announcement move:
entry deals -0.84% (se 0.15, n 52), own-market -0.44% (se 0.02). Incumbent price across the buy +0.0020 (head
+0.0017); no entry_offset on bought records; every x: segment has a record after its first report; identities exact.
Oddities: BRKW (Investment Companies) buys $151 bn of household products (16% of the market), $121 bn of industrial
distribution (36%), $85 bn building products; SILC makes 5 buy entries in seed 1 (6 x: segments by y20) while its cap
falls 1,041 -> 294-596 bn. Board buy1 - head: cap +1.18 tn (se 0.37, t 3.2), capex/revenue +0.0065 (se 0.0023, t 2.9),
real growth +0.08 pt/yr (se 0.03, t 2.3), B-or-worse -0.19 pt (t -1.3), others |t| < 0.5.

## 2026-10-26: 5b-3 buy entry after the fixes (buy2) vs 3f1aa807 and vs buy1, 8 seeds x 20 years

    BASE=<scratchpad>/basetree-3f1aa807 bin/harness-batch var/harness/segments/run.sh 1-8 head buy2
    div6.py 1-8 head buy2 > buy2_n8.out; buy2x.py 1-8 buy2 >> buy2_n8.out; div2.py 1-8 head buy2 / buy1 buy2

Buy entries 15 (0.09/yr; buy1 52) by 6 companies, no repeats; builds 135 (0.84/yr). Closeness (any held industry)
buy 0.5: 4, 0.25: 11 (73% distant) vs build 0.8 7, 0.5 79, 0.25 49 (36%). Per firm-year: empire builder 0.0055,
fortress 0.0005, operator 0.0001, steward 0. Deals with no segment paying 18 of 1,530 (1.2%), 15 became entries;
firm-reports with none paying 2.4%: BRKW 402 (every report), TERN 312, SKUA 149, FALC 84. At entry: kappa 0.018
(max 0.10), share of parent revenue 0.064 (max 0.38), market share median 0.006, p90 0.118, max 0.168 (CANV internet
retail 0.168, BRKW industrial distribution 0.118: above the 10% cap), price/cap 0.063 (max 0.50), goodwill 48%.
Kappa +4/+8/+12 y median 0.013/0.010/0.010; gate fails 20%; first two reports builds 0 in 4 of 29, pays 0 in 2; no
sales, no bolt-ons, no deal into an x: segment before its first report. Announcement: entry -2.42% (se 0.93, n 15),
own -0.50%. Incumbent price across the buy +0.0062 vs head +0.0057. Identities exact. BRKW buys 4 of 15 (largest $105
bn, 8.5% of household products); SILC makes none. Board vs head: net PP&E growth -0.04 pt/yr (se 0.01, t -2.7), cap
+0.93 tn (t 1.5), capex/revenue +0.0031 (t 1.7), real growth -0.01 pt (t -0.2). vs buy1: real growth -0.08 pt (t
-2.5), capex/revenue -0.0035 (t -2.0), cap -0.25 tn (t -0.5).

## 2026-10-26: buy2 traces (no new runs)

Over-cap buys. CANV s1 (deal y19.761, $77.9 bn, goodwill $60.1 bn, kappa 0.0614): first report y19.844 record as
0.1683, capacity 55.3 bn, revenue 62.9 bn (implied market 329 bn), segment line 15.7 of 108.6 bn a quarter (0.145 =
filed target). BRKW s3 (deal y2.433, $46.3 bn, goodwill $12.3 bn, kappa 0.0354): record as 0.1175 / 0.1083 / 0.1068
at the next three reports, capacity 70.0-71.8 bn, implied market 596 -> 663 bn; segment line 0.38 of revenue. The
record as written at the deal is not probed, so deal-booked vs first-report re-strike cannot be separated from these
runs; at the first report bought capacity is 17% and 12% of the implied market.
Net PP&E growth -0.04 pt/yr: per-firm contributions sum to -0.037 pt; builders (firms with a build entry, which build
in head too) -0.027, untouched -0.015, buyers +0.006. Largest: SILC -0.016 (builder, no buy in buy2), SWFT -0.011,
HARR -0.005 (untouched), CANV -0.005 (buyer), TICK, GANN, RAVN -0.004 to -0.005; positive FALC +0.006 and SKUA +0.006
(both buyers).

## 2026-10-26: buy3 (outlook filed at entry; holding companies look outside) vs 3f1aa807 and vs buy2, 8 seeds x 20 y

    BASE=<scratchpad>/basetree-3f1aa807 bin/harness-batch var/harness/segments/run.sh 1-8 head buy3
    drift.py 1-8 buy3 head; div6.py 1-8 head buy3; buy2x.py 1-8 buy3; div2.py 1-8 head buy3 / buy2 buy3

Bought share: at the deal median 0.0096, max 0.1000 (capacity / entry market = 0.100 exactly for the capped ones); at
the first report median 0.0107, max 0.1052; ratio first/deal median 0.997 (p90 1.061, max 1.474 on a small one).
Build ventures' record share entry -> first report with revenue: ratio median 0.320 (head 0.265), max share 0.034
(head 0.037). Buy entries 30 (0.19/yr) by 8 companies, 21 by holding companies (BRKW, OWLS, TRIV, HARR), no repeats
in a seed; builds 135 (0.84/yr). Closeness (any held industry) buy 0.8 1, 0.5 13, 0.25 16 vs build 9/73/53. Per
firm-year: empire builder 0.0092, fortress 0.0028, operator 0.0006, steward 0.0002. Bought kappa median 0.029 at
entry, 0.023/0.026/0.026 at +4/+8/+12 y; gate fails 14%; no whole sales; 3 bolt-ons (TRIV bought into its new
industrial distribution 0.03 y after entering, before its first report, $2.2 bn). Announcement entry -0.70% (se 0.17)
vs own -0.46%. Incumbent price across the buy +0.0011 (head +0.0015). Identities exact.
Board vs head: capex/revenue +0.0057 (se 0.0024, t 2.4), P/E -0.07 (t -1.2), defaults+deaths +0.25 (t 1.0), others
|t| <= 0.4. vs buy2: cap -0.93 tn (se 0.44, t -2.1), P/E -0.07 (se 0.02, t -2.8), defaults +0.38 (t 1.4).
