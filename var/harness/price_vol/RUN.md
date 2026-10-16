# Price-vol harness

Question family: which price channel makes a name realise more volatility than its configured sigma, and at what horizon.

- Harness: `var/harness/price_vol/PriceVolHarnessTest.php`, a fork of `var/harness/FullMarketHarnessTest.php` (same env,
  see `var/harness/full/RUN.md`) with three hooks: `PriceVolProbe::install` after the tracker is fetched, the tick
  counter in the loop, `finish` before the summary.
- Probe: `var/harness/price_vol/Probe.php`. No src change. It swaps the tracker's private `marketEngine` and
  `earningsEngine` for subclasses (state copied, parent called) and writes one CSV row per (tick, ticker):
  `tot` = log((P_after_earnings + dividend) / P_tick_start), split into
  `mkt` (beta x market factor), `sec` (sector factor), `idio` (idio diffusion + drift), `pull` (log blend to fair
  value), `brk` (whole remainder when the circuit breaker binds), `jI` own Kou jump, `jS` systemic jump, `ma`, `pol`
  (policy repricing), `impP`/`impT` (impact: permanent share, and the change in the tracker's transient book),
  `warn`, `rep` (earnings engine: pre-announcement, report incl. dividend added back). Components sum to `tot` (1e-6).
  The JSON holds per-ticker sector/sigma and time means of the budget inputs (V_I, floor flag, uncapped systemic and
  own jump variance, impact EMA, announcement EMA, cap, fund EMA, theta, idio variance state).
- Run script: `var/harness/price_vol/run.sh live <seed>`; YEARS (10), TPY (360), SKIP (warm-up ticks, TPY).
  Passes BASE=<this worktree> so the worktree's src and config load (cache in `var/harness/cache-<tree>`).
  Delete that cache after a constructor change in src.
- Batch: `bin/harness-batch var/harness/price_vol/run.sh 1-4 live && python3 var/harness/price_vol/analyse.py 1-4 360 360 > var/harness/price_vol/<name>.out`
- Compare two arms: `compare.py <armA> <armB> [seeds skip tpy]` (vol vs sigma, excess attribution, floored names with feasible sigma, market sanity). Arms `live` (baseline) and `fix` are labels; both run the tree as it stands.
- Analysis: `analyse.py <seeds> <skip> <tpy>` (reads live-*): vol by horizon (1/5/21/63 ticks), variance shares at 1 and 21 ticks,
  budget audit, component variances for the focus names and by sector.
- Runtime: about 5 s per simulated year; CSV about 30 MB per seed-decade.
- The Write/Edit guard keys on the session's project dir; write in the scratchpad and `cp` in.
