# Joint sanity harness (whole board on a live macro with the Diet)

Question family: does the whole simulation behave sensibly after several merges, measured live (not replayed).

- Harness: `var/harness/joint/JointHarnessTest.php`, a fork of `var/harness/FullMarketHarnessTest.php` (same env, see
  `var/harness/full/RUN.md`). Additions:
  - `MACRO_OUT` rows also carry `y10` (yield10y), `hh` (householdDebtToIncome), `gq` (realGdpQuarterlyGrowth).
  - `TICK_CSV=<csv>`: price and shares of every name every tick; `ANOM_OUT=<jsonl>`: first non-finite macro field
    and first non-positive live price per name.
  - `TRACE=<ticker> TRACE_OUT=<jsonl>`: that name's tracker update row each tick plus its impact variance EMA (`iv`),
    the pricing context's scalars (`c_*`) and the engine's own `fundamentalStateFor` result (`f_fv`, `f_policy`,
    `f_targets`). At TPY=3600 eight names over two years write ~110 MB; delete it after. Draw-free; the
    seed-1 path was byte-identical with and without it.
  - The inherited `FVX` probe is stale (calculateStructuralRoic now takes 6 args); use `TRACE` instead.
- `aerie-joint` is a symlink to the checkout, passed as `BASE`, so the container cache is
  `var/harness/cache-aerie-joint` (fresh) rather than the stale `cache/` or `cache-aerie-trading`. Delete it after a
  constructor or service change in `src/`.
- Run script: `var/harness/joint/run.sh live <seed>`; POLITICS=1, HEALTH=1; YEARS (20), TPY (360).
  Outputs `runs/live-<seed>.{jsonl,macro.jsonl,ticks.csv,anom.jsonl,log}`. ~110 s per seed-20y.
- Batch: `bin/harness-batch var/harness/joint/run.sh 1-4 live && python3 var/harness/joint/analyse.py 1-4 > var/harness/joint/<name>.out`
- Analysis: `analyse.py <seeds> [arm] [tpy]`: per-seed columns and mean (sd), then notes (failures, Ch11, names
  that went x20 or /50 split-adjusted, anomalies, rates outside -2..20%).
  - Index and per-name returns are split-adjusted (a tick where shares move >20% is priced through the cap). Without
    that, ~58 names' splits read as crashes (CW index 1.9%/y instead of 5.9%).
  - Index is price return only; board dividend yield is ~2.3-2.5% cap-weighted.
  - "beat share" is revenue above the Street's expected revenue, not EPS.
  - "vol excess vs configured" is realised 21-tick vol minus InitialMarket `volatility`; "vs cvol" uses the model's
    current volatility.

- Tree arms: `pre` = `tree-d89691cc` (`git archive d89691cc | tar -x -C var/harness/joint/tree-d89691cc`, vendor
  symlink); `rev-<n>` = `tree-rev-<n>` (archive HEAD, then `git apply -R --directory=var/harness/joint/tree-rev-<n> <pr>.diff`).
  `bin/harness-batch var/harness/joint/run.sh 1-4 pre live rev-48 rev-50`, then `python3 var/harness/joint/path_an.py 1-4 <arms>`
  (pinned-price counts; event ticks come from the `event_ticks` field the fork writes).

- Worktree arm: `wt` = `tree-wt`, a copy of a worktree without vendor/ plus a vendor symlink to the main checkout's
  (a copied vendor redeclares the Composer autoloader and the run dies at bootstrap). Rebuild it after editing the worktree.
- Delete every `tree-*` copy when the comparison is done: they are full source trees, and var/harness's .gitignore
  tracks `.php`, so a left-over tree shows up as thousands of untracked files.
- `tr-<name>` arms run the checkout like `live` (for TRACE runs). `TRACE` takes a comma list of tickers.
- `CREDIT=1` adds lending standards (`sloos`, `sloos_raw`), the bond premium and its trend, the bank capital
  requirement, buffer and built capital, `infl_ema`/`bei`/`supercore`, the default-rate EMAs (`corp_dr`, `retail_dr`),
  housing `starts`, `fci`, commercial property `cre`, `freight` and the recession probability `recp` to each
  `MACRO_OUT` row.
- Report rows carry the trade balances and lease (`recv`, `inv`, `pay`, `lease`) and the quarter's inventory
  write-down and receivable provision (`invwd`, `recprov`). `wc_an.py <seeds> <armA> <armB>`: those as shares of
  revenue plus ND/EBITDA, equity/assets and end rating for the 13 firms whose cycle and leases are declared by stream.
- Report rows also carry Street and consensus EPS (`reps`, `ceps`). `beat_an.py <seeds> <arm>`: EPS beat share and
  each firm's surprise sd on revenue, by business model (needs `runs/ticker_models.json`, see model_an.py below).
- `model_an.py <seeds> <armA> <armB> [Model,...]`: per business model, revenue over the Street's expected (the
  standing beat) and revenue growth, A against B with the seed-paired SE. Map tickers first:
  `php -r 'require "vendor/autoload.php"; $o=[]; foreach (App\Data\Company\InitialMarket::STOCKS as $k=>$r) $o[$r["ticker"]??$k]=(new ReflectionClass(App\Data\Company\Sectors::strategyFor($r["industry"])))->getShortName(); file_put_contents("var/harness/joint/runs/ticker_models.json", json_encode($o));'`

- `model_moments.py <seeds> [arm]`: per business model, revenue growth vs nominal GDP, revenue beta to y/y real GDP,
  EBIT margin mean/sd/loss share, ROIC or ROE (first/last 2y), EPS beat share, revenue surprise, ND/EBITDA, capex/dep,
  payout, distress, seasonality, plus flags against guide ranges. Also needs `runs/ticker_fin.json`: the same one-liner
  with `...->isFinancial()` in place of the short name.
- Batches: `bin/harness-batch var/harness/joint/run.sh 1-4 live` (one call, run_in_background), not `&` loops.

- Batch A arms: `baseA` = tree-baseA (git archive a202316c), `fixA` = tree-fixA (same + batchA.patch); run.sh maps both
  to `tree-<arm>`. MACRO_OUT rows carry `scp` (supplyChainPressureIndexEma) and `vol_ema` (marketVolatilityEma);
  `ema_an.py <seeds> <arms>` tabulates them, `ins_margin.py <seeds> <arms>` gives insurer margin quantiles.

- `fixAB` = tree-fixAB (a202316c + batchAB.patch + new-files.tar). Apply with `git apply --directory=var/harness/joint/tree-<arm>`
  from the repo root; `git -C <tree> apply` inside the checkout skips every path silently.

Evidence: `joint_evidence.md`.
