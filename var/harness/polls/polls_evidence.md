# Opinion polls: evidence (2026-10-05)

Data downloaded by a research subagent (it was cut off before writing this file; the summary is Claude's, from the
outputs below). Fits on real data only.

| Number | Meaning | Source | How read |
|---|---|---|---|
| 440 | Effective sample of a single poll's error on the truth (z2 = e = 22.7) | Jennings & Wlezien 2018 replication data (Dataverse doi:10.7910/DVN/8421DX), PR legislative, single-poll days; `fit_campaign.py` | own fit |
| 3.37x | Final-3-day single polls (median n 1,000): MSE over pure sampling variance | same | own fit |
| 0.94x | ... sampling SD after removing each election x party mean: the excess is an error all polls of an election share | same, `jw_poll_errors.out` s.3 | own fit (agent) |
| 20 days (30 nearly as good) | Window in which a poll's error on the result falls fastest (z2 = e + b min(d,W)/W + c d) | same, `fit_campaign.out` | own fit |
| b = 52 units | What moves in that window, in (poll-vote)^2/p(1-p) x 1e4; the vote's short-term swing explains ~15 | same | own fit |
| ~210 | Error plateau 1,000+ days out (PR legislative, long cycles) | `jw_poll_errors.out` s.2 | own fit (agent) |
| 28 months | Half-life of a single OU fitted to German monthly poll averages 1998-2026; no fast component of any size | wahlrecht.de, five parties, `de_poll_dynamics.out` Q2a | own fit (agent) |
| 9.5 months (7.3-13.3) | Half-life of the governing parties' fall from their result over the term; straight line chi2 23 vs 5.2 | wahlrecht.de, 8 terms, `fit_cabinet_path.py` | own fit |
| beta(1..3 m) = +0.05..+0.09 (se 0.06-0.09) | Post-vote polls keep the campaign's change: no fade faster than ~3 months | wahlrecht.de, 40 party-elections, `fit_campaign.out` s.2 | own fit |
| ~1.0-1.4x | A single German poll's SD around the cross-institute monthly mean over sampling SD (n ~2,000) | `de_poll_dynamics.out` Q2b | own fit (agent) |
| 12-18 / month | Poll releases in Germany mid-term, ~32 in the election month; median n 2,000 | `de_poll_dynamics.out` Q3 | own count (agent) |

## The game against these (`poll_profile.php`, 20 seeds x 400 years, economy on trend)

- Poll error on the result by months out: 42 (1), 49 (3), 63 (6), 72 (9), 172 (36), 226 (47). J&W: ~78 at a month,
  ~112 at six, ~136 at nine, ~210 three years out. The term's total matches; in the final year the game's polls are
  too accurate, because real campaigns move ~52 units in their final weeks and the vote's short-term swing is ~15.
  Moving more variance into the campaign would take it from mid-term (German variogram) or raise the term total
  (election fit), so it is left.
- Cabinet's polls against its result: 39% of the month-36 fall by month 12 (Germany ~88%). Only the cost of ruling
  and the residual run on the front-loaded clock; the parties' lasting leads run off evenly, as the election fit has it.

## Vote moments unchanged (formation_run.php, 24 seeds x 200 years, HEAD vs polls)

Pedersen 12.92 vs 12.83 (se 0.13); incumbent swing -3.06/6.91 vs -3.01/6.83; minority 0.721 vs 0.730;
single-party 0.431 vs 0.437; party shares and swing SDs within noise.

## Not pinned

- Persistence of the shared (industry-wide) poll error across months: polls are drawn i.i.d. at the total error.
- The fade of the vote's short-term swing beyond the >= 3-month bound: set to the cabinet path's 9.5-month half-life.
- A midterm recovery: Germany shows none (-1.35, se 2.03).

# Phase 2: the market prices the election (2026-10-05)

## Forecast (`ElectionForecast`)
- Poll average: per-party Kalman filter (Jackman 2005), drift = the lasting OU's monthly variance, error = EFFECTIVE_SAMPLE_SIZE.
- 32 antithetic vote outcomes to election day through OpinionPolls' own functions, D'Hondt, exact logit cabinet odds
  (each talks attempt draws from it, success independent of the draw; ElectionForecastTest checks 1,200 simulated talks).
- Calibration (`forecast_calibration.php`, 4 seeds x 200y, 196 terms): chance 0.15/0.35/0.55/0.75/0.96 against led
  0.13/0.35/0.58/0.79/0.96. Brier (sum over parties) 0.30 three years out, 0.21 at 6 months, 0.18 at 1 month.
- Cost after `CoalitionFormation::utilities()` (distances and Council median struck once): ~85 ms a forecast, monthly.

## Long-run laws (`lever_means.php` + inline pooling, 16 seeds x 200y, full economy, quarterly after year 4)
- Corporate tax shift: mean +0.0047 (se 0.0023), sd 0.027, 16-quarter autocorrelation 0.662 (sd across seeds 0.14).
- Bank levy: mean 0.00118 (se 0.00006), sd 0.00085, 16-quarter autocorrelation 0.660 (0.14).

## Pricing (`pricing_probe.php`, 3 seeds x 120y, representative firm k-g 4%, bank with levy base 5x value, k-g 5%)
| News | firm sd / p95 / max | bank sd / p95 / max |
|---|---|---|
| Monthly poll | 0.18% / 0.36% / 1.2% | 0.72% / 1.5% / 5.8% |
| The vote | 0.63% / 1.6% / 2.6% | 2.7% / 7.1% / 11.7% |
| Government takes office | 0.33% / 0.58% / 1.8% | 1.4% / 2.5% / 6.5% |
Level against no policy pricing: firm -1.35%, bank -4.7% (the long-run levy expected).
Benchmarks: Snowberg, Wolfers & Zitzewitz (2007) partisan equity effect ~2-3%; Knight (2006) platform capitalization.
A first version without the sitting government's coming budget moved banks 10.6% on a poll month (the gap after a
government took office and before its first budget); fixed with the sitting segment.

# Phase 3: the extraction rules and the stamp duty priced (2026-10-05)

## Long-run laws (`lever_means.php` now records all nine levers; `runs9/`, 16 seeds x 200y, full economy, quarterly after year 4)
Each measured as the earnings feel it. Tax and levy reproduce the phase-2 series bit for bit (same seeds).
- Extraction rules, unit-cost factor 1/(1 - 0.048 s): mean 1.00347 (se 0.00064), sd 0.0079, term persistence 0.567 (sd across seeds 0.17).
- Stamp duty, turnover factor exp(-52.68 x 2 (duty - 0.0005)): mean 0.9492 (se 0.0036), sd 0.056, term persistence 0.360 (0.13).
  The duty is no revenue the Council guards, so governments undo it more freely than the tax and the levy (0.66).

## Firm bases (the models' own channels, `StandardOperatingPhysicsTrait`)
- Miners and producers keep, each report, the share of revenue the extraction factor scales ((vm + input drag) / realized
  price relative, plus the committed base before the factor since the fix below); base = SAAR revenue x share. Tests
  check the strictest rules add base x (F(1) - 1) to the quarter's cost.
- Brokers keep the trading revenue at the founding duty times (1 - variable cost ratio) over revenue. Tests check a duty
  takes base x (D(duty) - 1) off revenue less variable cost.

## The rules' cost on the whole operation (fixed 2026-10-05)
The models applied Greenstone, List & Syverson's 4.8% TFP loss to the variable cost ratio only, though a Hicks-neutral
loss raises every input per unit; fixed costs are 75-80% of SINK's and CNDR's costs. Now the committed base carries the
factor too (`getFixedCostFactor()`, applied where EarningsEngine strikes fixed costs); capital is left out (it would need
capex per unit of capacity to rise).
`ExtractionMarginTest.php` (the board seeded as production seeds it, the real EarningsEngine, 8 calm quarters, strictest
rules against founding, 30 seeds; `extraction_margin.out`):
- CNDR: margin 0.293, strictest rules -2.52 pp (se 0.49), EPS -8.8% (se 2.2); the evidence on its cash costs (0.589 of
  revenue) implies -2.97 pp. Before the fix the hit was about -0.7 pp (variable costs 0.14 of revenue).
- SINK: margin 0.308, -2.58 pp (se 0.72), EPS -10.0% (se 4.3); evidence -2.57 pp. Before: about -0.9 pp.
- The market's base / revenue equals the cash costs (0.59, 0.52). Long-run factor 1.0035, so the average margin cost
  in a running game is about -0.2 pp.
- The real MarketEngine on the seeded board, the law certain from the next budget against no forecast: CNDR -5.9%,
  SINK -4.8% (strictest rules); ROOK -11.6% (the big state's 0.2% duty). Bases over fair value 0.29 / 0.22 / 0.25, the
  engine's k - g 9.7% / 8.5% / 10.6%.

## A cabinet that falls between votes (fixed 2026-10-05)
The probe found a broker +10% on a poll month: a cabinet fell before its first budget, the coming budget read became
"no change until the next vote's government", and a month later a similar cabinet formed and read the same duty again.
Now, while the parties talk between votes, the coming budget is the talks' government's, each cabinet weighed by its odds
(the fallen one excluded as `CoalitionFormation::talks()` excludes it), due the round after the talks' expected end
(`ElectionForecast::sittingBudget()`, `sittingTakesEffect()`); ~2.3 ms a tick while talks run.

## Pricing (`pricing_probe.php`, 3 seeds x 120y; miner base 26% of fair value at k - g 9%, broker 25% at 10.6%, as above)
Own law alone (tax part removed), |log move| p95 / max:
| News | miner (extraction) | broker (stamp duty) | bank (levy, for scale) |
|---|---|---|---|
| Monthly poll | 0.15% / 1.0% | 1.4% / 7.6% | 1.1% / 4.3% |
| The vote | 0.7% / 1.7% | 7.2% / 12.0% | 5.3% / 8.8% |
| Government takes office | 0.4% / 1.0% | 2.5% / 6.4% | 1.7% / 4.6% |
Level against no pricing of the own law: miner -0.1%, broker -1.2% (sd 7%), bank -3.5%.
The largest broker poll month left (-7.6%) is a minority cabinet collapsing a month after the vote, its likely successors
big-state: news, not an artifact.

## Pricing at 16 seeds (2026-10-05, `pricing_probe_seeds.php` + `pricing_probe_agg.php`; `pricing_probe_seeds.out`)
Question: do the 3-seed |log move| figures above (broker poll p95 1.4%, max 7.6%) hold with more seeds?
Harness: `pricing_probe_seeds.php` is `pricing_probe.php` with one seed a process and the raw moves written to JSON
(`runs_pricing/seed<N>.json`); `pricing_probe_agg.php runs_pricing 3` pools them. Seeds 1-16 x 120y, one tick a month,
moves after year 4, ~137 s a seed (8 at once: two batches, ~4.6 min). Seeds 1-3 reproduce `pricing_probe.out` exactly.
Own law alone, percent. "3-seed sd" = sd of the pooled p95 over 400 bootstrap draws of 3 seeds; "se" the same at 16.
| News, firm | p95 3 seeds | p95 16 seeds (se) | per-seed p95 sd | 3-seed sd | max 3 / 16 seeds | per-seed max median (range) |
|---|---|---|---|---|---|---|
| Poll, broker | 1.36 | 1.40 (0.03) | 0.09 | 0.06 | 7.6 / 8.5 | 3.8 (2.8-8.5) |
| Poll, miner | 0.15 | 0.19 (0.02) | 0.06 | 0.04 | 0.96 / 1.68 | 0.74 (0.41-1.68) |
| Vote, broker | 7.2 | 6.7 (0.6) | 1.9 | 1.2 | 12.0 / 12.0 | 7.9 (3.9-12.0) |
| Vote, miner | 0.72 | 0.70 (0.05) | 0.24 | 0.12 | 1.7 / 2.1 | 1.1 (0.55-2.1) |
- Broker poll p95 holds; 3 seeds were enough for it. The miner poll p95 was low by about one 3-seed sd (per-seed p95
  0.10-0.33). Vote p95s carry +-1.2 pp (broker) at 3 seeds; per-seed vote p95 is the 2nd largest of ~29 votes.
- The max does not settle: broker poll months over 3% are 34 in 20,851 (1.6 per 1,000); over 5% only 2, both over 7%
  (seed 1 -7.6%, seed 6 -8.5%), none in 5-7%. Both are a cabinet falling between votes, its likely successors reading a
  heavier duty: seed 6, t 8.75, a one-party cabinet falls 5 months after forming, the coming budget read 0.064% -> 0.176%
  from 9.00 (TRACE line in `pricing_probe_seeds.php`). News, about one per 900 game-years; quote it as such, not as a max.
Caveats: economy held on trend (`pricing_probe.php`'s representative firms, bases as measured above), 12 ticks a year.
