# disclosure: Securities Commission disclosure enforcement (profit warnings, earnings management)

Question family: how `MacroStateDTO::$profitWarningThreshold` (EarningsEngine::evaluatePreAnnouncement) and
`$earningsManagementScale` (EarningsEngine::manageReportedEarnings) shape warnings, report-day jumps, the accrual bank
and the beat spike.

Harness: `DisclosureHarnessTest.php`, a fork of `var/harness/FullMarketHarnessTest.php` (live macro, no Diet), with its
own `bootstrap.php` copy so the kernel cache is `cache/` here (delete it after a constructor/service change in `src/`).
- `PWT=<threshold>` / `EMS=<scale>` force the two fields on the DTO the board reads, every tick, after the macro
  (and Diet, if on) has run (`forceDisclosure`, a field copy of the readonly DTO).
- `DISC_OUT=<json>`: per run, `w` = warnings [t, ticker, log move from the GUIDANCE event, economic shortfall
  (0 = reversal-only)], `rj` = EARNINGS event log moves (totalShockPct), `r` = reports [t, ticker, accrual bank / TA,
  reported - consensus EPS, price], `ret` = board price return per tick, `cap` = board cap at each year end.
  With DISC_OUT set, `captureReport` skips the usual quarter rows (smaller, faster).

Arms (`run.sh <arm> <seed>`, env YEARS (30), TPY (360)): `light` 0.30/1.5, `founding` 0.20/1.0, `strict` 0.10/0.5.
~2 s per sim-year per run at 360 tpy.

Run:
    bin/harness-batch var/harness/disclosure/run.sh 1-4 light founding strict && \
      python3 var/harness/disclosure/analyse.py > var/harness/disclosure/stage4_y30.out && cat var/harness/disclosure/stage4_y30.out

`analyse.py` (env ARMS) prints per arm mean [min..max] over seeds and the paired difference vs founding (se).
`DIAG=1` adds `diag` rows [ratio, reversal part, squeeze part, drag change] per pre-announcement evaluation.
Outputs: `runs/<arm>-<seed>.{json,log}` (untracked). Results: `disclosure_evidence.md`.
