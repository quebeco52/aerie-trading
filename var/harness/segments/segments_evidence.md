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
