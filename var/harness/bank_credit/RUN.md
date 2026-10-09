# bank_credit: how to run

Read this instead of the evidence files, the harness test or the analysis scripts. Open those only for the one part
a question needs, with `grep -n` / `sed -n`.

## What it measures

The six lenders (LAKE, RIVR, PLVR, TALN, STRK, POOL) on a seeded board, whole ticker, one JSONL row per bank per
quarter. Row keys: `t rev ni ebit eq ta ea allow prov nco ttc ev gap rp cdr rdr cre res px sh nim nii int om div bb cash
dep wd rating ib slope_spot pol rwt ugap rvd pdef req ccyb rwd ii streams`. A final per-run record carries `seed years
tpy replay class_files cost_hook dead reorgs defaults secs`.

## Current harness: `runs/p2d/` (copy it for a new round; layout below is p1's, same env and arms)

p2d: `BankCreditHarnessP2dTest.php` = p2c + read-only probe (`iy` resolveInterestYield on the pre-tick book, `ea_pre`,
`iy_post`, `mds`, `vsh` volume shifts; `PROBE=0` off). `run_p2d.sh after|afternp <seed>` (AFTER only; BEFORE/rec are
read from runs/p2c by `analyse_p2d.py`, PB). `decompose_p2d.py` = DSS table vs BEFORE and vs p2c AFTER, income beta
split book/cash/resid, jackknife cross-firm slope.

### p2c

p2c: `BankCreditHarnessP2cTest.php`, `run_p2c.sh after <seed>` (before/rec jsonl copied from runs/p2, macro paths in
scratchpad `p2_macro`, BEFORE tree `wt_p2before`), `analyse_p2c.py` (DSS betas), `diag_p2c.py` (income beta split into
lending vs interest on cash, card NII/EA), `macro_same.php <tpy> <s0> <s1> <years>` (macro-only state hash per seed;
run with and without BASE to prove a macro refactor is bit-identical before reusing a recorded path).

### p1 layout

- Test `runs/p1/BankCreditHarnessP1Test.php`, bootstrap `runs/p1/bootstrap.php`.
  Env: `YEARS TPY SEED OUT=<jsonl> MACRO_RECORD=<gz> | MACRO_REPLAY=<gz> BANKS=LAKE,...` and `BASE=<tree>` for a
  BEFORE arm.
- `runs/p1/run_p1.sh <rec|before|after> <seed>` writes `<arm>-<seed>.jsonl` and `.log`, and echoes `exit <code>`:
  - rec: BEFORE tree, live macro, records the path to `$MACRODIR/path-<seed>.gz` (~20 MB per seed-20y)
  - before: BEFORE tree replaying that path
  - after: working tree replaying that path
- New round: `mkdir runs/<r>`, copy `runs/p1/{BankCreditHarnessP1Test.php,bootstrap.php,run_p1.sh}`, then fix `Q`,
  `BTREE` and `MACRO` in the script. `BTREE` and `MACRO` sit in an old session's scratchpad and may be gone.

## BEFORE tree

```
B=$TMPDIR/wt_before; rm -rf $B; mkdir -p $B
git -C <repo> archive HEAD | tar -x -C $B          # or HEAD with only the changed src files reverted
ln -s <repo>/vendor $B/vendor; cp <repo>/.env $B/.env
```
Both arms use the user's working `.env`. The per-run `class_files` field lists the file and md5 of each touched class;
check that BEFORE loaded the tree and AFTER loaded the working copy.

## Batch (16 seeds x 20 y x 360 tpy ≈ 13 min wall)

Run each line as one Bash call with `run_in_background: true` and wait for its completion notice. Do not poll.
```
seq 1 16 | xargs -P 8 -I{} runs/<r>/run_<r>.sh rec {}
for s in $(seq 1 16); do echo "before $s"; echo "after $s"; done | xargs -P 8 -L 1 runs/<r>/run_<r>.sh
```
At most 8 PHP processes (-P 8). About 120 s per run. Check the logs with `grep -L 'OK (1 test' runs/<r>/*.log`.

## Analysis

`analyse.py` has `load(arm)`. `analyse_qw.lender()` gives ROE, CET1, P/B and payout stops.
`nii_sensitivity.ols_cluster()` gives rate slopes clustered by seed. `analyse_p1.py` combines all three; copy it and
change the arm globs. Print only the final table to `<name>.out`.

## Evidence files (newest first)

phase2d_evidence.md (runs/p2d, betas on TA + decomposition), phase2c_evidence.md (runs/p2c, A+B+C re-measure), phase2_evidence.md (runs/p2, adds `bk` book-yield state, analyse in runs/p2/analyse_p2.py with DSS betas), phase1_evidence.md, quickwins_evidence.md, nii_sensitivity_evidence.md, round2c_evidence.md (2c + 2d),
round2_evidence.md, bank_credit_evidence.md (v1-v4, PLVR). Read only the dated section a question names.
