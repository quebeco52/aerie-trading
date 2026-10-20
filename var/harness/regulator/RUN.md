# Regulator harness

All runs drive `../politics/gate.php` (macro only, no fund, no equity cap). Arm `step` raises the bank capital
requirement by STEP_SIZE (default 1pp) at STEP_AT (default year 10), phased in linearly over STEP_PHASE years.
Evidence: `regulator_evidence.md`.

## Impulse response (irf.sh, irf_an.py)
`irf.sh <label> [phase] [seeds] [years]` is the old `&`/`wait` loop (8 procs per arm, step and off); prefer chan.sh.
`python3 irf_an.py runs/<label>` prints the paired step - off path by quarter.

## Channel split (chan.sh, chan_an.py; 2026-10-20)
1. `var/harness/regulator/chan_setup.sh`: three detached worktrees of HEAD (src must be clean) with their own vendor
   and the working tree's gate.php, one shortfall channel zeroed in each (`wt_noBridges`, `wt_noPremium`, `wt_noSLOOS`).
   If src is dirty, patch the setup to copy the diff in first. Check the sed patches matched (`git -C wt_X diff --stat`).
2. `bin/harness-batch var/harness/regulator/chan.sh 1-8 off step step4 noBridges noPremium noSLOOS offBridges offPremium offSLOOS`
   (STAGE=chan_8 default, YEARS 20, TPY 180; ~10 s wall for 72 runs). `step` phases in over 1 year, `step4` over 4;
   `off<X>` is tree X with nothing raised.
3. `python3 var/harness/regulator/chan_an.py var/harness/regulator/runs/chan_8`: paired step - off, step4 - off and each
   channel as (step - off) - (noX - offX) and as step - noX; log real GDP peak / years 0-4 / 5-8, gap, hdti at year 4,
   SLOOS years 0-2; flags pairs whose post-step crisis dates differ.
4. `var/harness/regulator/chan_setup.sh remove`.
