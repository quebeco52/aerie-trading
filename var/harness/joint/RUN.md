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

- Batch C arms: `baseC` = tree-baseC (git archive 29782cb5), `fixC` = same + batchC.patch. Report rows now carry `crev`
  (published consensus revenue; `exp` is the model's pre-shock revenue, NOT consensus). `c_an.py <seeds> <armA> <armB>`:
  per model n, EPS beat (se over seeds), rev/crev-1 median and within-firm sd, revenue beat, flags outside 60-85%,
  then report-tick r^2 profile, report-window move sd and announcement share of tick variance (env WIN, default 0,1)
  from ticks.csv, and wall s/run. `disclosure/run.sh baseC|fixC` runs those trees there (nothing forced).

Evidence: `joint_evidence.md`.

- Batch D1 arms: `baseD1` = tree-baseD1 (git archive 4bc145f4), `fixD1` = same + batchD1.patch. Report rows now carry
  `nco`, `prov`, `allow`, `ii` (interest income) and `ttc` (strategy TTC loss rate, annual). `lender_an.py <seeds> <armA> <armB>`:
  per lender wholesale debt y0/5/10/20, trailing ROE mean/sd, equity CAGR, EPS beat, NCO/(TTC x gross book at quarter
  open), ROE-policy correlation, interest-income share. `lender_open.py`: year-1 ROE, revenue, interest income.

- Batch D2 arms: `baseD2` = tree-baseD2 (git archive 8e4c1454), `fixD2` = same + batchD2.patch. Report rows carry `hm`
  (hard-market regime quarters), `erl`, `ilsp`, `catb`, `lr`/`xr` (loss/expense ratio KPIs), `evt` (sector event from
  stream details), `cdep`, sleeve `sl_v`/`sl_er`/`sl_pi`, `tax`, `bpi`/`btri`. `ins_an.py <seeds> <armA> <armB>`: DOVE/SAFE
  CR, onsets, attachments, ROE, float, ILS recovery; CROW/SWAN/TIER/VULT ROE, interest-income sd, sleeve mark.
- `fixD2b` = tree-fixD2b (8e4c1454 + revised batchD2.patch). `ins_an.py` takes any number of arms; `ins_path.py <seeds> <arms...>`:
  DOVE/SAFE ROE at y2/5/10/20, float/revenue at y0/5/10/20, and a per-seed TIER ROE/icr/ND-E/rating path with default flags.
- `fixD2c` = tree-fixD2c (8e4c1454 + third batchD2.patch: life reserves in bonds, insurer solvency lockout, NAIC ceiling
  with realised sleeve gains). Rows now carry `tgain` (carry:equity_sleeve:trailing_gains). `ins_bridge.py <seeds> <arms...>`:
  DOVE/SAFE equity vs premium (rev - ii) growth, payout by 5y block, erl and sleeve/eq at y0/5/10/20, and the equity
  bridge by block (residual split into sleeve mark, issuance, rest; corr(rest, d y10)).
- `capE` = tree-capE (git archive 2f618cbc + /tmp/claude-1000/c/capE.patch: insurer stock comp at industry rates,
  extraordinary capital-target repurchase past the NAIC ceiling); fixD2c is the HEAD arm. `ins_capE.py <seeds> <arms...>`:
  ins_bridge with premium = `rev` (ii is separate), ROE as NI / average equity per year, rev(4q)/eq at y0/5/10/20 and the
  split-adjusted share-count change. The bridge's `issuance` is net share growth on net-issuing quarters only (a lower
  bound; it also catches opportunistic secondaries, which the event filter does not log).
- `capE2` = tree-capE2 (2f618cbc + /tmp/claude-1000/c/capE2.patch: capE plus no premium-valuation raise while
  eq/ta >= getTargetCapitalRatio). `raise_an.py <seeds> <arms...>`: per capital-target firm (banks, SAFE, DOVE) the
  quarters with shares +2-20%, split above/below target (prior eq/ta; insurers use their y0 eq/ta as proxy) and
  after a loss, plus NI/avg-equity ROE, 20y payout/NI and split-adjusted share change.
- Batch E arms: `baseE` = tree-baseE (git archive 27fe1065; seed 1 byte-identical to capE2), `fixE` = same + batchE.patch.
  Run with `CREDIT=1` for housing `starts`. `e_an.py <arm> [seeds]`: share raises (rescue/non-rescue, after loss), SINK/FNCH
  dividend borrowing, SINK dividend and buyback by crude regime, IB/SAFE/DOVE TTM ROE, Heavy/Specialty per-firm revenue
  beta, Heavy write-down share, BIRD/DIPR margin sd, WADE end state, corr(starts_t, gap_t+k), starts rel sd/AR1/troughs.
  `fixE2` = HEAD + batchE2.patch (walk-down 0.60 sd, oil producers do not hold dividends). `fragile_an.py <seeds>
  <tk,...> <arm>...`: per firm and arm the default seeds, worst rating, peak ND/E, share rises >2%, end rating.
- Batch F arm: `fixF` = tree-fixF (HEAD a0786363 + batchF patch: payout bias on the odds, dividend held only while
  trailing EPS covers it, HF bonus centred, IB gamma friction two-sided); base is `fixE2` (HEAD reproduces it). Run
  `CREDIT=1 bin/harness-batch var/harness/joint/run.sh 1-8 fixF` (~4.5 min for 8 seeds). `f_an.py <seeds> <arms...>`:
  dividend-borrowing events/$B by firm (top 15), per payout firm div/NI y1/20y, equity y0/5/10/20, ND/EBITDA y1/y20,
  end ratings, defaults, >10% cuts, div $/q y1 vs y20; board payout and CW dividend yield; firms with equity down >40%;
  SWAN/PERE margin, TTM ROE, EPS beat beside lore baseline_roe. Board sanity from analyse.py, beat_an.py, fragile_an.py
  (f_an.out holds all four for fixE2 vs fixF).
- Batch G arm: `fixG` = tree-fixG (HEAD 4a81a6af + capex gate: a non-financial firm in covenant breach funds plant from
  internal cash; scheduled first, maintenance floored at 20% of plan); base is `fixF` (HEAD reproduces it, runs on disk).
  `CREDIT=1 bin/harness-batch var/harness/joint/run.sh 1-8 fixG` (~4 min). `g_an.py <seeds> <armA> <armB>`: penalty-
  borrow / missed-maturity / shut-out / revolver-cover events by firm, constrained-quarter share (head False or icr < 2,
  Reit 1.05) and capex/dep constrained vs not, board and per-model revenue CAGR, named-firm rev/PP&E y0/10/20, SINK
  bust capex and revolver, WADE capex/dep after a grid failure. g_an.out also holds analyse/fragile/beat for both arms.
