# Joint sanity harness (whole board on a live macro with the Diet)

Question family: does the whole simulation behave sensibly after several merges, measured live (not replayed).

- Harness: `var/harness/joint/JointHarnessTest.php`, a fork of `var/harness/FullMarketHarnessTest.php` (same env, see
  `var/harness/full/RUN.md`). Additions:
  - `MACRO_OUT` rows also carry `y10` (yield10y), `hh` (householdDebtToIncome), `gq` (realGdpQuarterlyGrowth).
  - `TICK_CSV=<csv>`: price and shares of every name every tick; `ANOM_OUT=<jsonl>`: first non-finite macro field
    and first non-positive live price per name.
  - `TRACE=<ticker> TRACE_OUT=<jsonl>`: that name's tracker update row each tick plus the pricing context's scalars
    (`c_*`) and the engine's own `fundamentalStateFor` result (`f_fv`, `f_policy`, `f_targets`). Draw-free; the
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

Evidence: `joint_evidence.md`.
