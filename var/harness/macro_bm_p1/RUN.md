# Macro -> business-model wiring, Part 1 (HEAD vs working tree)

Question family: does Part 1 (REIT rents on commercialMarketRent, landed import costs, insurer claims inflation,
level-dependent deposit beta, utility allowed ROE on the 30y, PENG GSCPI shortage, housing-starts weight) move the
firm-level relationships it targets.

Harness: `var/harness/macro_bm_p1/MacroBmP1HarnessTest.php`, a fork of `erne_wing/ErneWingHarnessTest.php`. Quarter rows
add `opc` (operating costs), `kpi` (all numeric reported KPIs), `str` (revenue by stream), and the macro readings
`y10 be y30 cg sc pr dbeta fxg ipd` (ipd = importPriceLevel - importPriceLevelTarget(exchangeRateTrend), computed in
the harness so it also exists on HEAD). The record adds `macro_q`: housing starts, policy rate, 10y, breakeven, GSCPI
once a quarter.

LIVE macro in both arms, no replay: Part 1 adds macro fields (`commercialMarketRent`) and changes ones firms read
(`systemDepositBetaEma`, housing starts), so a replayed path would either flatten B's REIT rents or feed A the new
deposit beta. Arms therefore run their own macro on the same seed and drift apart (sd of differences ~ unpaired).

Run script: `var/harness/macro_bm_p1/run.sh a|b <seed>` (YEARS 20, TPY 360, POLITICS off, fund on).
- `a`: HEAD tree at `/tmp/claude-1000/headtree` (git archive + COPIED vendor; override with `TREE_A=`). Rebuild:
  `rm -rf <dir> var/harness/cache-headtree && mkdir <dir> && git archive <rev> | tar -x -C <dir> && cp -a vendor <dir>/`
  and check `php -r 'require "vendor/autoload.php"; echo (new ReflectionClass(App\Service\Macro\MacroEngine::class))->getFileName();'` from inside it points into it.
- `b`: working tree via the `treeB_p1` symlink (cache `var/harness/cache-treeB_p1`; delete after a constructor change).

Batch (about 100 s per seed-run at 20 y):

    bin/harness-batch var/harness/macro_bm_p1/run.sh 1-8 a b \
      && python3 -I var/harness/macro_bm_p1/analyse.py 1-8 > var/harness/macro_bm_p1/<name>.out && cat var/harness/macro_bm_p1/<name>.out

`analyse.py <lo-hi> [a b]`: quarters after year 2, per-seed statistic per arm, paired b - a (mean, se, t, n).
1. REITs (SHOR PLZA ELDE KITE): corr of quarterly change in KPI `in_place_rent_index` (and dlog lease stream) with
   the change in real 10y (yield10yEma - tipsBreakevenEma), firm-demeaned and pooled within seed.
2. DOVE, SAFE: OLS slope of KPI `loss_ratio` on excess = 0.40 coreGoodsInflationEma + 0.60 supercoreInflationEma - 0.02,
   lags 0-4 quarters; mean loss ratio, variable cost ratio, EBIT margin.
3. LAKE RIVR PLVR: NIM = 4 (net_interest_income stream - interest expense) / earning assets (no NIM KPI exists);
   slope on policyRateEma; mean NIM, ROE = 4 NI / equity.
Also: importers' mean ipd and cost-ratio (opc/rev) slope on exchangeRateGap(); utilities' EBIT margin slope on
yield30yEma; PENG shortage rate by GSCPI bin (shortage = NarrativeEngine fab-shortage lore regex); sd of yoy dlog
housing starts; B - A mean EBIT margin per non-financial firm.
