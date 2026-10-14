# GSCPI fit (supply-chain pressure index constants)

Question family: fit and verify the constants of the in-game GSCPI (`MacroTransmission::calculateGscpiComposite`) and the
ISM-style supplier-deliveries series (`CommodityLogisticsSubsystem::calculateSupplierDeliveryTimes`) to the NY Fed
GSCPI's 1998-2019 moments (mean -0.30, sd 0.475, monthly AR(1) 0.79).

- Harness: `gscpi.php <seed> <years> <tpy> <out.jsonl>`, a fork of `cbsmooth/y10_macro.php`: MacroEngine with its six
  subsystems + PoliticsEngine, no board, no fund flows, no equity market cap (the equity-wealth loop is off). One row
  a simulated month: GSCPI inputs (EMAs), CU, shipping tightness, the three candidate demand gaps, raw and EMA index,
  CPI / core goods / PPI inflation, output gap. Delivery fields only when the tree has them (HEAD does not).
- Arms (`run.sh <arm> <seed>`, env YEARS 30, TPY 360):
  - `P` working tree (placeholder constants)
  - `L` OVR `ovrL/`: CommodityLogisticsSubsystem with the fitted DELIVERY_FACTORY_LOADING
  - `F` OVR `ovrF/`: that plus MacroTransmission's fitted GSCPI_* constants (and the purge regressor, if not outputGapEma)
  - `H` HEAD tree at `$HEADTREE`: `git archive HEAD | tar -x -C <dir>; cp -a vendor <dir>/vendor` (copy, not symlink)
- OVR copies: `python3 var/harness/gscpi_fit/make_ovr.py L <loading>`; `make_ovr.py F <loading>` (reads `fit.json`).
  Rebuild them whenever the working-tree src changes; they are full copies of the src files.
- Batch (about 30 s a 30-year run; 32 runs in ~2 min):

      HEADTREE=<dir> bin/harness-batch var/harness/gscpi_fit/run.sh 1-16 P H && python3 var/harness/gscpi_fit/analyse.py step1 P 1-16 8
      bin/harness-batch var/harness/gscpi_fit/run.sh 1-16 L && python3 var/harness/gscpi_fit/analyse.py step2 L 1-16
      python3 var/harness/gscpi_fit/make_ovr.py F <loading> && python3 var/harness/gscpi_fit/make_ovr.py G <loading>
      bin/harness-batch var/harness/gscpi_fit/run.sh 1-16 F G && python3 var/harness/gscpi_fit/analyse.py verify G H 1-16

  Arm `G` = F with the purge's mean shift (beta x mean gap) folded into the GSCPI_*_MEAN constants (hits -0.30).
  `analyse.py rawdiag <arm> <seeds>` puts the fitted purge and weights on the raw (non-EMA) inputs, for the AR(1) question.
  Round 2 (raw inputs, own regressor each): `bin/harness-batch var/harness/gscpi_fit/run.sh 1-16 Q && python3
  var/harness/gscpi_fit/analyse.py step2raw Q 1-16` (writes fit2.json, folded MEANs), `make_ovr.py R 0`, then
  `bin/harness-batch var/harness/gscpi_fit/run.sh 1-16 R && python3 var/harness/gscpi_fit/analyse.py verify2 R H 1-16`.
  numpy is not installed: the analysis is pure Python.

- Analysis `analyse.py` (burn-in 3 years, monthly samples, pooled moments, AR(1) pooled within seed):
  - `step1 <arm> <seeds> <loading>`: sd of ln K(CU)/K(0.785) and ln tightness, the equal-variance loading
    39.49 x sd(ln tight)/sd(ln K), delivery mean/sd/min/max/AR(1), months over 78.8, variance share of the target.
  - `step2 <arm> <seeds>`: input means/sds, OLS slope and R2 of each standardized input on outputGapEma,
    globalDemandGapEma, domesticDemandGapEma, PCA of the purged z's, weighted sd; writes `fit.json`.
  - `verify <armF> <armH> <seeds>`: raw index moments vs targets, EMA sd, tail shares, then per-seed guards paired F - H.
- Outputs: `step1_*.out`, `step2_*.out`, `verify_*.out`; evidence in `gscpi_fit_evidence.md`.
