# Macro -> business-model wiring, Part 2 (HEAD 1479ce7 vs working tree)

Question: does Part 2 (rest-of-world gap in globalDemandGap, metals clearing on world demand, capital-goods prices by
industry in replacement capex and capacity per book dollar) move LOON capex/EBIT margin, non-financial ROIC creep,
and metals producers' revenue co-movement with world demand.

Harness: `var/harness/macro_bm_p2/MacroBmP2HarnessTest.php`, a fork of `macro_bm_p1`. Quarter rows add `roic` (report,
the engine's truePostTaxReturn), `croic` (stock currentRoic), `ic` (invested capital), `gdg` (globalDemandGapEma),
`ppr` (stream-map `state:ppe_relative_price`, B only). `final[t]` adds `industry` and `finsec` (Sectors strategy
isFinancial).

LIVE macro in both arms (Part 2 changes macro fields), same seeds, drift apart: size n as unpaired.

Run script: `var/harness/macro_bm_p2/run.sh a|b <seed>` (YEARS 30, TPY 360, POLITICS off, fund on).
- `a`: HEAD tree at `/tmp/claude-1000/headtree` (git archive + COPIED vendor; `TREE_A=` overrides). Check it has no
  `src/Data/Macro/CapitalGoodsPrices.php`, and that `php -r 'require "vendor/autoload.php"; echo (new
  ReflectionClass(App\Service\Macro\MacroEngine::class))->getFileName();'` run inside it points into it.
- `b`: working tree via the `treeB_p2` symlink (cache `var/harness/cache-treeB_p2`; delete after a constructor change).
- The symlink is created by the first run; if a batch races on it, a stray `aerie-trading` link appears in the project
  root: delete it and create `treeB_p2` once before batching.

Batch (about 150 s per seed-run at 30 y):

    bin/harness-batch var/harness/macro_bm_p2/run.sh 1-8 a b \
      && python3 -I var/harness/macro_bm_p2/analyse.py 1-8 > var/harness/macro_bm_p2/<name>.out && cat var/harness/macro_bm_p2/<name>.out

`analyse.py <lo-hi> [a b]`: paired b - a (mean, se, t, n) for
1. LOON capex/revenue and EBIT margin, years 20-30 (window sums).
2. Median over non-financial firms of mean ROIC y25-30 minus y3-6 (dead firms dropped); per-firm creep for the
   industries with |capital-goods drift| > 0.5%/yr.
3. CNDR, WING: corr(yoy dlog revenue, yoy change in gdg) and sd of yoy dlog revenue, after year 2.
Also capex/revenue y2-30 for the drift firms, deaths/default episodes/reorgs per seed, B's final ppr for LOON and
SHOR, and B-A EBIT margin y2-30 per non-financial firm (`*` where |B-A| > 1pp).

`oil_firms.py <lo-hi> [A B]`: energy-exposed firms (by `industry`), paired EBIT margin y2-30 and corr(yoy dlog rev,
yoy d gdg). Arm `n` in runs/ = cached pre-oil-demand working tree (2026-10-14).
