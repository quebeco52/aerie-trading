# Aerie Trading

A market simulation in PHP 8.4 / Symfony 8 (Doctrine, Twig, Tailwind, Redis, Docker). A macro engine calibrated to
US data drives about 60 listed firms through sector business models; prices form in a market engine with agent order
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

## Evidence
A claim about simulation behaviour needs a number, sized to the claim. Harness runs are the slowest part of a change;
spend them where the answer is emergent.
- **A cited constant, or a fix that restores an identity or a formula:** a unit test that pins it. No harness; the
  source and the test are the evidence.
- **A display or news rule** (a threshold, how often something shows to the player): one run of 3 seeds, to check
  the rate is sane.
- **Tuning toward a measured moment, or a claim that a change moves one:** paired arms on the same seeds, reporting n,
  mean and standard error: 16 seeds for a mean, 48 for a variance. Iterate on 3-4 seeds and run the full count once,
  on the final version. Reuse a cached baseline arm when `src/` is unchanged.
- **What the live game is doing:** `make macro-dump` first; a harness only for the counterfactual.

One seed proves nothing about a moment. All PHP processes on the machine share 8 slots through `bin/php-slot`.

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
- `make test-unit` (~0.2 s), `make test` (Fast suite, ~1 min), `make test-realism` (five multi-seed long-run tests,
  ~3 min serial), `make phpstan FILE=<path>`. Without Docker: `bin/verify` (PHPStan on changed files plus quick
  tests, ~20 s), `bin/verify --full` (both suites side by side, ~1 min on an idle machine) before committing.
- A realism test that runs over ~5 s belongs in the Realism suite in `phpunit.dist.xml`, not Fast.
- Never create a file named `phpunit.tmp.xml`; it is tracked.

## Database
Change entities only. Never write or generate migrations; the user generates them with Doctrine. Say in your report
when a change needs one.

## Pages
Player pages are the District's own sites: no model names, citations, coefficients or file paths in templates.
Sentence-case labels, mono type only for figures, institution names from `App\Data\Institutions`. Format figures
with `|signed_class`, `|pct`, `|money` and `source_line()`, not by hand. Read `.agents/FRONTEND.md` before any template,
page script or company profile change; `tests/Twig/TemplateStyleTest.php` enforces it, and `bin/render-pages`
screenshots the result.

## Reporting
Report once tests, PHPStan and the numbers are in. Offer long checks (mutation runs, long sweeps) rather than running
them first. Lead with the result, list anything that needs a migration, and give before/after numbers for anything
you tuned.