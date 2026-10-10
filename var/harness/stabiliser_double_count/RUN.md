# Stabiliser double count (demand reads net-tax stance, not net-tax rate)

Question: does demand's discretionary fiscal impulse reading `netTaxStanceRate` (no automatic stabiliser) instead of
`netTaxRate` move the policy-loop fit moments (gap sd, ACF 4-16q, busts, J)?

- Harness: `loopfit_tree.sh <tree> <tag> [seeds=36]` = `var/harness/loopfit.sh` at the tree's own Taylor weights
  (OVR copy is a no-op), CreditMacroProbeTest 120y + RatePathProbeTest +/-0.25 at 360 tpy, all PHP via
  `bin/php-slot` (xargs -P 8 inside the script). Needs `LOOPFIT_DIR`.
- Arms: `new` = main working tree; `old` = HEAD worktree
  (`git worktree add --detach <scratch>/old HEAD`, `cp -a vendor` into it, never a symlink).
- Worktree: also `cp var/harness/policy_fit.json var/harness/asym_fit.json` into it (ignored files; else zero rate path). The script sets BASE=<tree> itself.
- Run (~45 min for both arms, 72 PHP jobs each):

      LOOPFIT_DIR=<abs>/var/harness/stabiliser_double_count/runs/new bash var/harness/stabiliser_double_count/loopfit_tree.sh <main> new 36
      LOOPFIT_DIR=<abs>/var/harness/stabiliser_double_count/runs/old bash var/harness/stabiliser_double_count/loopfit_tree.sh <scratch>/old old 36

- Analysis:
  `python3 var/harness/stabiliser_double_count/paired.py runs/old runs/new` (per-seed paired sd, ACF, busts) and
  `python3 var/harness/loopfit_rank.py <dir holding old.an and new.an>` (J and its parts, rule rho/pi/y).
- Outputs: `runs/` (ignored), `result.out` (the reported table), evidence in `stabiliser_double_count_evidence.md`.
