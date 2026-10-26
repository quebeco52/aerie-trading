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
