# Aerie Trading

A market simulation in PHP 8.4 / Symfony 8 (Doctrine, Twig, Tailwind, Redis, Docker). A macro engine calibrated to
real world data (mostly US data) drives about 60 listed firms through sector business models; prices form in a market engine with agent order
flow. Players trade on in-world sites of the Aerie District, a financial-centre enclave (Year 1 = 2009Q1).

## Map
src/Service/Math/       MathUtility (shared formulas), FinancialConstants (shared constants)
src/Service/Macro/      MacroEngine and its six subsystems
src/Service/Politics/   Diet, cabinets, polls, elections, policy levers
src/Service/Corporate/  earnings, debt, treasury, capital allocation, M&A
src/Service/Model/      business models: Standard*Trait defaults, Sector/ overrides
src/Service/Market/     price formation, order flow, bonds, options, margin
src/Data/               seed data and lore (InitialMarket, DistrictMap, AerieDiet, ...)

## Push back
You are an architect, not an order-taker. If a request introduces a bug, breaks an accounting identity, double-counts
a shock or rests on unsound math, say so in a sentence or two and propose the correct approach. Back it with a
measurement where you can. The user can override anything, but you must point out what is being overridden.

## Models and math
- Every formula is a named, published model you can cite. No ad-hoc clamps or "feels right" scalars.
- Formulas used in more than one place live in `App\Service\Math\MathUtility`. Grep it before writing a new one.
- Calibrate to public data (FRED, BEA, EIA, filings). The macro core targets US moments. Deliberate departures:
  the Sovereign Reserve Fund and the District's openness (judge against the no-fund arm or small open economies),
  and firms fail rarely by design. Do not tune failure rates toward US default rates.
- Lore sets per-firm parameters, never the model.
- Time: drift × dt, diffusion × √dt, smoothing exp(−dt/τ), jump intensities per year. Any rate crossing a boundary
  is explicitly annual or per-tick.
- A `*_BASELINE` constant is where a price is built, not where it settles. A cyclical deviation term reads a
  measured trend, not the baseline.
- Sector models compose the `Standard*Trait` defaults. An override must be a structurally different mechanism (bank
  NIM, insurance reserve cycle, REIT lease ladder, biotech pipeline); one that only rescales the default is a smell.

Known traps, each shipped as a bug before:
- A level read as a rate, or a rate as a level (commodity indices; a slow OU process hides it for years).
  `pricing_power_multiplier` and `input_cost_multiplier` are quarterly rates, not levels.
- Pricing power goes only through `resolvePricingPower()`.
- Every jump is Merton-compensated in drift and counted in the price variance budget. A second channel that moves
  price (order-flow impact, a sector factor) draws its variance from a measured quantity, not an assumed one.
- Order-flow impact is linear (Huberman-Stanzl); square-root impact blew bubbles.
- Brock-Hommes fitness is annualized before strategies are compared.
- `roicTtm` is a trailing average; gates on it saturate.
- The sentiment index sits near 88 at trend: read `sentimentDeviation()`, not the level.
- Silent integration: a computed field nobody reads, or an input read the tick before its driver updates. Trace the
  call path with grep before calling a model wired in.
- dt-neutrality tests on emergent quantities have no power; test each primitive.
- Scripted-draw tests that feed `generateStandardNormal` lead with the firm-factor draw (one-factor stream).

## Evidence
A claim about simulation behaviour needs a number, sized to the claim. Harness runs are the slowest part of a change;
spend them where the answer is emergent.
- **A cited constant, or a fix that restores an identity or a formula:** a unit test that pins it. No harness; the
  source and the test are the evidence.
- **A display or news rule** (a threshold, how often something shows to the player): one run of 3 seeds, to check
  the rate is sane.
- **Tuning toward a measured moment, or a claim that a change moves one:** arms on the same seeds, reporting n, mean
  and standard error of the paired difference (t = Δ / SE_Δ) for 1-3 target quantities named before the run.
  Harnesses live in `var/harness/<topic>/` (read `<topic>/RUN.md` first; do not scan the whole directory). Run in stages
  of 8, 16, then 48 seeds per arm; stop early once every target has |t| ≥ 4 or is negligible. 48 is the ceiling, and
  variances need it. Iterate on 3-4 seeds and run the stages once, on the final version. Run only the arms the question
  needs (no-fund only for a fund-driven moment), reuse a cached baseline arm when `src/` is unchanged, and ask before a
  sweep over 100 runs.
- **What the live game is doing:** tell the user to run `make macro-dump` first; a harness only for the counterfactual.

One seed proves nothing about a moment. All PHP processes on the machine share 12 slots through `bin/php-slot`, at most 8 per session.

## Research
Pin numbers, don't survey a literature. One sovereign-fund question once fanned out to seven agents and ~575 web and shell calls, and used up a whole session.
- Before searching, list the numbers the build needs (usually one to three). Stop when each has one citable source.
  A second-hand figure (survey, review, abstract) is fine if you flag it.
- About 20 web calls and one agent per question; an agent never spawns agents. Put this budget in any research
  prompt you delegate.
- Paywalled or unreadable source: take the abstract or a citing paper's figure. Don't hunt mirrors, scrape Scholar or render PDF pages to images. Grep a PDF for the table instead of reading it whole.
- If three attempts don't move the answer, or the budget runs out, stop and report what is pinned, what is open and
  what more would cost. The user decides whether to go on.

## Constants
Financial parameters and thresholds are class constants, or go in `FinancialConstants.php` if shared. No bare
numeric literals in model code except mathematical ones (2, 12 months).
- Group related constants under a `// --- Section Name ---` comment.
- Each has a single-line `/** */` docblock with what it is and the real-world magnitude it targets.

    // --- NIM (Net Interest Margin) Squeeze ---
    /** Break-even NIM floor (~50bps). Steep curve = profit; flat or inverted curve = squeeze. */
    public const NIM_BASE_SPREAD_BUFFER = 0.005;

## Comments
Say what a term does and cite its source, in one to three lines. Measurements, sweeps and rejected alternatives go
in the commit message, not the code.

## Tests
- PHPUnit. Every new model or mechanism gets a test.
- Suite membership is explicit in `phpunit.dist.xml`; a test in an unlisted directory never runs.
- Stochastic behaviour: invariant or distribution tests in `tests/Financial/`, not exact values. Break the guarded
  term by hand once to confirm the test fails.
- Tests and verification: `bin/verify` (PHPStan on changed files + quick tests, ~20 s; works in sandboxes without Docker).
  With Docker: `make test-unit` (~0.2 s), `make test` (Fast suite, ~1 min), `make test-realism` (five multi-seed
  long-run tests, ~3 min serial), `make phpstan FILE=<path>`. Before committing: `bin/verify --full` (~1 min).
- A realism test that runs over ~5 s belongs in the Realism suite in `phpunit.dist.xml`, not Fast.
- Never create a file named `phpunit.tmp.xml`; it is tracked.

## Database
Change entities only. Never write or generate migrations; the user generates them with Doctrine. Say in your report
when a change needs one, and when it needs a reseed (seed data, opening balances or initial state changed).

## Git
Commit only when the user asks.

## Pages
Player pages are the District's own sites: no model names, citations, coefficients or file paths in templates.
Sentence-case labels, mono type only for figures, institution names from `App\Data\Institutions`. Format figures
with `|signed_class`, `|pct`, `|money` and `source_line()`, not by hand. Read `.agents/FRONTEND.md` before any template,
page script or company profile change; `tests/Twig/TemplateStyleTest.php` enforces it, and `bin/render-pages`
screenshots the result.

## Reporting
Report once tests, PHPStan and the numbers are in. Offer long checks (mutation runs, long sweeps) rather than running
them first. Lead with the result, list anything that needs a migration or a reseed, and give before/after numbers for anything
you tuned.