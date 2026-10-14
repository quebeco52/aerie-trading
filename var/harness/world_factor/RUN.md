# World activity factor (rest-of-world gap, metals on world demand)

Question: does a rest-of-world output gap, weighted into world demand at GDP shares, make commodities cyclical the way
real ones are? Targets (1993-2019, own calc from FRED/World Bank): corr(metals yoy, world activity yoy) 0.58, metals yoy
log sd 0.231, corr(oil yoy, world activity yoy) 0.55; world gap sd 1.8%, AR(1) ~0.85, corr with mainland 0.51.

- Harness: `world.php <seed> <years> <tpy> <out.jsonl>`, a fork of `gscpi_fit/gscpi.php` (macro loop + politics, no
  board, no fund flows). One row a simulated month: gaps, metals, oil, freight, GSCPI, CPI, PPI.
- Arms (`run.sh <arm> <seed>`, env YEARS 30, TPY 360): `W` working tree; `E<x>[s<sigma>]` OVR copy with
  METALS_DEMAND_ELASTICITY = x and METALS_SHORT_TERM_SIGMA = sigma (`make_ovr.sh <x> [sigma]`); `H` HEAD tree at
  `$HEADTREE` (`git archive HEAD | tar -x -C <dir>; cp -a vendor <dir>/vendor`, copy, not symlink). H has no world gap,
  so its "world" columns fall back to globalDemandGapEma.
- Batch: `HEADTREE=<dir> bin/harness-batch var/harness/world_factor/run.sh 1-16 W H && python3
  var/harness/world_factor/analyse.py W 1-16 H` (about 2 min).
- Analysis `analyse.py <arm> <seeds> [arm2]`: quarterly samples, 3-year burn-in, per-seed moments, mean/se, paired t.
- The GSCPI constants were refit on this tree with `gscpi_fit` (`run.sh Q`, `analyse.py step2raw Q 1-16`):
  `gscpi_fit/step2raw_Q_world*.out`.
- Outputs: `verify_W_H_16.out`.
