# Segments: does the segment rework behave, and do segments do what they claim?

Question family: segmented companies (src/Data/Company/Segments.php: SKYL, TERN, ERNE, WEAV, JDAW, BIRD, RAVN, OWLS,
TRIV) file segment shares, a blended outlook and per-line tilts; does the mix lean toward the leading segment, do the
identities hold, and does the board move against the committed tree?

- Harness: `SegmentsHarnessTest.php` = a copy of `../idle/IdleHarnessTest.php` (same IDLE_OUT, split-adjusted realised
  vol in `rv`, OUTLOOK_SALT) plus a read-only `segmentProbe()` behind `SEG_OUT`: per report for the nine tickers
  `{t, tk, rev, om, shares (filed carry:segment_share), tilts (carry:segment_tilt), blend (carry:industry_outlook),
  beliefs (each segment industry's IndustryOutlook::belief from the store, main = own industry), segrev (report streams
  grouped by Segments::segmentOf), lgap (ledger segment_outlook_gap)}`, and for every other firm counts of
  `carry:segment_*` keys and ledger `segment_outlook_gap` (`leaks`). Works on a tree without the phase-2 methods.
- Run script: `run.sh <arm> <seed>`; YEARS (20), TPY (360); macro replayed from `../idle/macro/path-<seed>-oc.gz`
  (seeds 1-32 exist). Outputs `runs/<arm>-<seed>.{jsonl,log,idle.json,seg.json}`.
  - `head`: `BASE=<tree>` = `git archive <commit>` + `vendor` symlink, kept in the scratchpad (outside the repo);
    delete it and `var/harness/cache-<basename>` afterwards.
  - `seg`: working tree. `segplain`: working tree without the probe (read-only check: OUT equal to `seg`, `secs`
    dropped).
  - `gapfix`: working tree + `ovr_gap/` (src RedisIndustryShareStore copy that reads `segment_outlook_gap` back;
    drop it once src does).
- Batch (stage 1):

      BASE=<scratchpad>/basetree-<sha> bin/harness-batch var/harness/segments/run.sh 1-8 head seg \
        && bin/harness-batch var/harness/segments/run.sh 1 segplain \
        && python3 var/harness/segments/analyse.py 1-8 head seg > var/harness/segments/phase2.out

- Analysis: `analyse.py <seeds a-b or list> [A] [B]`: board (cap y20, median P/E y10-20, median split-adjusted
  realised vol, defaults+deaths, B-or-worse share, median ND/EBITDA y20) paired; per segment corr(d share, lagged
  relative outlook) in both arms (HEAD = no-tilt control, relative outlook computed the same way); share at y20;
  identities; tilt ranges; SKYL internet share path; nine companies' revenue, margin, P/E, events.
- `mix_response.py <a-b> [A] [B]`: share_B - share_A per report against B's lagged log tilt (the level test of
  whether tilts move the mix; the d-share corr in analyse.py is weak because the weight resolver smooths).
- Gotchas: OUT appends; a reused run name keeps old lines, and analyse.py reads the last one. after a constructor or service change in `src/`, delete `var/harness/cache/test`. Evidence: `segments_evidence.md`.
