# Standing decisions: market, valuation, pages & measurement

## Market, valuation and pages

- One equity premium; the market factor loads on economic news. Pricing discount-rate news into price at once was tried
  twice and reverted (fair value capitalised a short-lived premium as permanent).
- Stored `fairEquityValue` excludes market repricing; a board-wide price/fair ratio away from 1 is the discount-rate
  cycle, not mispricing. Fair value must not price the output gap as perpetual growth.
- The analyst consensus anchor rolls on the market quarter, not the public quarter (that double-counted firm news).
  Analyst targets near 1.2x price match real sell-side optimism.
- `SVENSSON_SLOPE_LAMBDA` 0.30 is calibrated to the empirical 10y beta; it is not a yield-curve bug.
- Rivalry is an exact industry match, the rival drain weighted by revenue-mix overlap; line weights are the plan and do
  not adapt to realised shares. The endpoint the user wants is fully separate line streams, built whole.
- Goodwill impairment values the unit at max(model value, quoted price).
- Pages: the rivals chart compares at least 2 same-industry rivals, else the firm's own 3y average (an "industry incl.
  unlisted" line was rejected); "what moved the shares" is a weekly regression on market and peers; key stats show
  measured beta. Acquired private targets are named coastal word + industry noun.

## Measurement lessons

- Always split-adjust prices, returns and drawdowns in harnesses (~70 splits a run). Raw prices twice produced a false
  ~1.5x "vol overshoot". Harness DPS is `getLastDividend()`, not total dividends over end shares.
- Arms pair only when the macro draws from its own stream and the outlook salt is fixed (BoardRun does both). Before
  trusting a paired t, check that two short runs of one seed match. Pair on the mainland: its mean drives about half the
  cross-seed variance of the District gap; do not ANCOVA on it (the arm moves it through spillover).
- For board-wide effects, record a seed's macro and replay it into both arms; a neutral all-zero replay is biased for
  convex terms, so average antithetic pairs.
- Back-of-envelope estimates came in at about twice the harness effect: measure, don't extrapolate. Test an attribution
  with its own arm.
- Check the denominator before reading a correlation (buybacks/cap vs the market factor was all cap).
- A 20-year variance ratio has no power; a smooth paired log path proves a fade. The per-tick change in the output gap
  is not news; build news from the true shocks.
- REIT net income is already FFO (the engine adds depreciation back); adding it again in a script double-counts.
  Dividend-roll benchmarks are a share of payers, not of all firms.
- `energyPriceShock` is a level against 100 with a non-zero mean; read log(EMA / decade trend) for a cyclical term. A
  field read against its trend belongs in `MacroDriverAttribution::TREND_FIELDS`.
- Ratings read Merton distance to default on market equity and realised vol, so a vol overshoot can chain CCC ->
  shut-out -> default.
- An "exact" identity check must include reports after a deal or sale.
- PHPUnit names mock classes with `mt_rand`: seed after building mocks, and give each seeded run a fresh `MathUtility`
  (the Box-Muller spare leaks across `mt_srand`). `phpunit.dist.xml` stops on the first defect in a file.
- Delete `var/harness/cache/test` after a config or constructor change. Never leave source-tree copies under
  `var/harness` (its .php files are tracked).
- When changing a rule, grep for the behaviour's tests, not only the method name.
- Profile the prod ticker's TickProfiler window, not the dev env with xdebug; time each PHP variant in its own process.
