# Security-family firms (NCKR, WALL, NJAR, WATCH, OSPR)

Question family: do the security firms behave sensibly on the live board (NCKR, NJAR, WATCH, OSPR on the seven-line
SecurityProtectionBusinessModel; WALL on CorrectionalServicesBusinessModel), and what does the revenue-mix overlap
rule in `IndustryShareLedger::mixOverlap()` change.

- Harness: `SecurityFirmsHarnessTest.php` (extends `lib/BoardRun.php`; read-only probe). Writes
  `runs/<arm>-s<seed>.json` (`SECFIRMS_OUT`): board opening (px, eps, bvps, configured sigma and beta, industry),
  every quarter of the five firms (rev, om, ni, eps, px, bvps, rating, KPIs, streams, `ddg`, `ib`, gold, unemployment
  gap `ugap`, government spending index `gsi`), every firm's quarters as `all[t] = [t, rev, om, rival_share_drain]`,
  a daily split-free cap path with a cap-weighted index, realised-vol accumulators for the board, shock counts from
  the earnings lore (`SHOCKS`: cash_heist, security_breach, geopolitical_conflict, client_file_leak,
  prison_disturbance), `reorgs`, `defaults`, the five firms' distress lines, board-wide strict distress lines
  (`distress_all`), and `ledger_file` (which IndustryShareLedger was loaded: checks the arm).
- Run script: `run.sh <arm> <seed>` (env YEARS 30, TPY 360; live macro, no Diet).
  - `live` / `overlap`: the working tree.
  - `full`: loads `ovr_full/Service/Corporate/Industry/IndustryShareLedger.php` through the bootstrap's `OVR`
    autoloader: a copy of the working-tree ledger with `mixOverlap()` returning 1.0 (the rule before the overlap).
    Re-copy it from `src/` and re-apply the one-line patch if the ledger changes.
- Batch (both tasks in one, ~6 min per 8 seeds x 2 arms):
  `bin/harness-batch var/harness/security_firms/run.sh 1-8 overlap full`
- Analysis:
  - `python3 var/harness/security_firms/analyse.py <arm> [n seeds]`: behaviour table per firm (per seed and mean),
    board median realised/configured vol, board deaths/defaults/reorgs/distress, NJAR conflicts, WALL occupancy
    and concession, NCKR cash share and indices, WATCH mix at years 1/10/20/30.
  - `python3 var/harness/security_firms/compare.py [full] [overlap]`: paired (a) five firms' revenue growth, mean
    margin, mean |rival drain|; (b) every firm in a multi-firm industry; (c) board counts. Uses `lib/stats.py`.
- After adding a model class or changing `config/`, delete `var/harness/cache/test`.
- Shock counts match NarrativeEngine phrases: update `SHOCKS` if that lore changes.
- Outputs: `live_4seed_30y.out` (pre-rework), `live_4seed_30y_v2.out` (Task A 10-27, overlap arm seeds 1-4),
  `overlap_8seed_30y.out`, `overlap_16seed_30y.out`. Evidence: `security_firms_evidence.md`.
