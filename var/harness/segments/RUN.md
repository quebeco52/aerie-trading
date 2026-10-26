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
  - `seg`: working tree (phase-3 first round). `seg2`: working tree after the entry-sizing fix (same as seg in
    run.sh; a separate name keeps the seg runs). `segplain`: working tree without the probe. `gapfix`: phase-2 store override (obsolete).
- Batch (phase 3; stages 1-8, 9-16, 17-32):

      BASE=<scratchpad>/basetree-<sha> bin/harness-batch var/harness/segments/run.sh 1-8 head seg \
        && python3 var/harness/segments/analyse3.py 1-8 head seg > var/harness/segments/phase3_n8.out
      bin/harness-batch var/harness/segments/run.sh 1-16 seg2 \
        && python3 var/harness/segments/analyse3.py 1-16 seg seg2 > var/harness/segments/seg2_vs_seg_n16.out

- Analysis:
  - `analyse3.py <seeds> [A] [B]` (phase 3): board paired (cap y20, P/E y10-20, split-adj vol, defaults+deaths,
    B-or-worse, ND/EBITDA y20, real revenue growth); identities (sum kappa, sum record revenue = 4 x report revenue,
    record capacity split = previous kappa over segments with plant on the ledger, `TICKER/seg` owners, tilt keys
    after opening, kappa <= 0); kappa paths and corr of d4 log kappa / d4 log record revenue with own vs main
    industry outlook; glut test (d log kappa ratio when the segment industry's peer price < 1); opening a_s,
    addressable share and penalty before/after; nine segment-industry peers; the eight. `YEARS=2` for short runs.
  - `analyse.py` (phase 2), `mix_response.py` (phase 2 tilt pass-through).
- Gotchas: OUT appends; a reused run name keeps old lines, and the scripts read the last one. After a constructor or
  service change in `src/` or a probe change, delete `var/harness/cache/test`. BIRD, OWLS, RAVN, TRIV main
  industries clear no market: their main record carries revenue but capacity 0. Evidence: `segments_evidence.md`.
