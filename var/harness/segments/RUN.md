# Segments: does the segment rework behave, and do segments do what they claim?

Question family: segmented companies (src/Data/Company/Segments.php: SKYL, TERN, ERNE, WEAV, JDAW, BIRD, RAVN, OWLS,
TRIV). Phase 2: filed segment shares, blended outlook, per-line tilts. Phase 3: plant per segment (SegmentBooks) for
the eight (all but SKYL): kappa, per-segment ledger records `TICKER/<seg>`, per-segment price/load/capex.

- Harness: `SegmentsHarnessTest.php` = a copy of `../idle/IdleHarnessTest.php` (same IDLE_OUT rows, which carry every
  firm's `industry_price_level` KPI; split-adjusted realised vol in `rv`; OUTLOOK_SALT) plus a read-only
  `segmentProbe()` behind `SEG_OUT`. Per report for the nine: `{t, tick, tk, rev, om, shares, tilts, blend, beliefs,
  segrev, lgap}` (phase 2) and `{kappa (carry:segment_plant), targets, sego (carry:segment_outlook), recs (each
  segment's ledger record: cap, rev, acs, as, tick; main = ticker key), truths (each segment industry's outlook x),
  gap, spare, ipl, idle (KPIs), pen (CorporateMetrics saturation penalty), addr (firm addressable share)}`. At the
  end, `slash`: every `TICKER/seg` ledger key in any industry. Works on trees without the phase-2/3 classes.
- Run script: `run.sh <arm> <seed>`; YEARS (20), TPY (360); macro replayed from `../idle/macro/path-<seed>-oc.gz`
  (seeds 1-32 only). Outputs `runs/<arm>-<seed>.{jsonl,log,idle.json,seg.json}`.
  - `head`: `BASE=<tree>` = `git archive <commit>` + `vendor` symlink, kept in the scratchpad (outside the repo);
    delete it and `var/harness/cache-<basename>` afterwards.
  - `p4c` (BASE b77f19c5) vs `econ` (working tree, segment economics); `econlive` = econ on the live macro.
  - `econ2`: working tree + `ovr_pl/` (SegmentBooks copy logging aggregate()'s segment prices/loads into the probe
    `pl`; behaviour-neutral, checked). `econ2nj`: `ovr_nj/` = same copy, avoidableCostRatio = company ratio.
    Refresh both copies from src before reuse (they are copies of the working tree of 2026-10-26).
  - `p3`: BASE tree (phase 3 committed, 803d2ade). `p4`: working tree (phase 4, deals by segment).
  - `seg`: working tree (phase-3 first round). `seg2`: working tree after the entry-sizing fix (same as seg in
    run.sh; a separate name keeps the seg runs). `segplain`: working tree without the probe. `gapfix`: phase-2 store override (obsolete).
- Batch (phase 3; stages 1-8, 9-16, 17-32):

      BASE=<scratchpad>/basetree-<sha> bin/harness-batch var/harness/segments/run.sh 1-8 head seg \
        && python3 var/harness/segments/analyse3.py 1-8 head seg > var/harness/segments/phase3_n8.out
      BASE=<scratchpad>/basetree-803d2ade bin/harness-batch var/harness/segments/run.sh 1-16 p3 p4 \
        && python3 var/harness/segments/deals.py 1-16 p3 p4 > var/harness/segments/p4_deals_n16.out
      bin/harness-batch var/harness/segments/run.sh 1-16 seg2 \
        && python3 var/harness/segments/analyse3.py 1-16 seg seg2 > var/harness/segments/seg2_vs_seg_n16.out

- Analysis:
  - `analyse3.py <seeds> [A] [B]` (phase 3): board paired (cap y20, P/E y10-20, split-adj vol, defaults+deaths,
    B-or-worse, ND/EBITDA y20, real revenue growth); identities (sum kappa, sum record revenue = 4 x report revenue,
    record capacity split = previous kappa over segments with plant on the ledger, `TICKER/seg` owners, tilt keys
    after opening, kappa <= 0); kappa paths and corr of d4 log kappa / d4 log record revenue with own vs main
    industry outlook; glut test (d log kappa ratio when the segment industry's peer price < 1); opening a_s,
    addressable share and penalty before/after; nine segment-industry peers; the eight. `YEARS=2` for short runs.
  - `long.py <seeds> [arm] [years]` (one arm, long game; `p4live` = live macro for YEARS > 20): kappa and a_s by
    decade, firm KPIs and segment sales/plant, segment vs peer growth, deals per decade, identities in the last year.
  - `econ.py <seeds> A B` (segment economics): firm stable margin/turnover, segment margins/turnovers, revenue
    identity on every report, revenue-share lag behind targets, rev/cap per segment, sale price vs segment EBIT.
  - `econ3`: working tree after the takeover-asset fix (probe adds `gw` goodwill, `tax` effective rate).
  - `skyl` (working tree, SKYL plant by segment) vs `head` = BASE 642a842c: `sky.py <seeds> head skyl` (internet
    kappa vs planned share, capacity gap, SKYL books, telecom peers LOON/SKUA, identities, board).
  - `wave2` (working tree, 8 more segmented companies) and `wave2pl` (+ `ovr_pl/`, refreshed from src; neutral,
    checked OUT equal on 8 seeds) vs `head` = BASE d29edb90: `wave2.py <seeds> head wave2 wave2pl` (new companies,
    outlooks opened from `runs/<arm>-<seed>.outlook.json`, identities over all segmented, board, wave-1 caps,
    segment prices). run.sh now always writes OUTLOOK_OUT. Never edit run.sh while a batch is running (bash reads
    it as it goes; a mid-run edit failed every run).
  - `p5a` (working tree, capital follows return) vs `head` = BASE 526ab166: `p5a.py <seeds> head p5a`. Probe adds
    `builds` (carry:segment_builds) and `cap` rows from an EarningsReportedEvent listener (one-plant gate, treasury
    organic capex, operating/maintenance/scheduled capex, revenue, net PP&E, management style).
  - `round.py <seeds> A B [C]`: OWLS/TRIV books (revenue, EBIT margin, interest income, NI, ROE, P/E, cap) and the
    TERN distress trace (year before first default: segment prices/loads, margin, ND/EBITDA, cargo share).
  - `deals.py <seeds> A B` (phase 4; its sold-record 'violation' counts capacity != 0, which econ keeps by design): from seg.json `deals` (probe `onEvent`: " sold its " / " executed a " events
    with kappa after): whole-segment sales, slices, acquisitions by segment, sold-record checks, capacity mismatch
    split by whether a deal fell between reports.
  - `analyse.py` (phase 2), `mix_response.py` (phase 2 tilt pass-through).
- Gotchas: OUT appends; a reused run name keeps old lines, and the scripts read the last one. After a constructor or
  service change in `src/` or a probe change, delete `var/harness/cache/test`. BIRD, OWLS, RAVN, TRIV main
  industries clear no market: their main record carries revenue but capacity 0. Evidence: `segments_evidence.md`.
