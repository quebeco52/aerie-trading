# Frontend house style

Player pages are the Aerie District's own websites: an exchange, a statistics office, a civic guide. They should
read and look like real ones. This file is the rulebook; `tests/Twig/TemplateStyleTest.php` enforces the mechanical
parts on `templates/` (admin excluded) and `assets/js`, `assets/controllers`.

## Fiction
- Write as the institution that publishes the page. Plain rules and real-world terms a civic or exchange site
  would use (D'Hondt, HHI, CET1, % of GDP, basis points), with a few headline numbers.
- Never in player copy: model or paper names (Merton, Taylor rule, Cournot), coefficients and log-odds, file paths
  or class names, US agencies and markets (the Fed, SEC, Treasury, NYSE, S&P), or real countries and funds used
  as a comparison ("as Singapore does"). Citations live in docblocks, Twig comments and commit messages.
- "Simulation", "simulated", "tick" and the like appear only on the frame pages (landing, sign-in, registration),
  which stand outside the fiction.
- British spelling, as the rest of the site: capitalisation, programme, licence, centre.

## Names
One name per body, from `App\Data\District\Institutions`. Source lines use `{{ source_line('id', ...) }}`, which refuses an id
the glossary does not have.

| Body | What it does |
| --- | --- |
| Aerie Council | Appoints the department heads |
| Council Appointment Board | Puts forward the candidates for each Council seat |
| Diet | The parliament; passes the budget |
| Monetary Authority | Central bank; sets the policy rate (never "Rate Council") |
| Financial Regulator | Bank capital rules |
| Exchequer | Runs the budget and the District's debt; issues its bonds (never "Treasury") |
| Sovereign Reserve Fund | The District's wealth fund ("the Fund" once named) |
| Trade & Migration Office | Tariffs and migration quotas |
| Statistical Office, Credit Registry, Land Registry, Freight Authority, Manufactory Board, Works Ministry | Data publishers and the district map's buildings |
| Aerie Exchange | The securities market this site belongs to (never "Lakebird Exchange") |
| Commodity Exchange | Commodity prices |
| Tickbird Research | Tickbird Data Systems' research desk; writes the long-form company profiles |

The place is "the Aerie Autonomous District" once, then "the District"; the financial district is Glasswater Row;
the US is "the mainland". The District has no army: defence firms sell to the mainland and allies.

## Company profiles
`StockInfo::DESCRIPTIONS`, shown under "About" and, first paragraph only, on the district map. Write them like an
exchange's company profile or an annual report's business section.
- 120-180 words in two or three paragraphs. The lead is one or two sentences (at most ~45 words) saying what the
  company is, sells and to whom, starting with its name.
- Then how it is organised and earns money (segments, named assets and brands), then what moves its earnings,
  stated plainly.
- No marketing or villain adjectives (fortress, titan, ruthless, undisputed, apex, empire), no em dashes, no
  "operates as", "not merely X but Y", "While X, Y" openers or summing-up closers. `StockInfoTest` checks these.
- Lore sets facts, not tone: a firm can hold a concession from a public body; it is never the central bank,
  regulator or Exchequer itself.

### Long-form profiles
`CompanyResearch::ARTICLES`, shown at `/stock/{ticker}/profile` and linked under "About". Tickbird Research writes
them for terminal subscribers: history, people, the firms around it, and what the desk is watching.
- 400-800 words in three to six titled sections, with a headline and a one- or two-sentence standfirst.
- Deadpan, with the joke's point intact. The same banned words and constructions as the short profiles.
- Every figure quoted is pinned to the seed data or `StockModelTuning` in `CompanyResearchTest`. Nothing the model
  does not do: no immunity, monopoly or behaviour the engine lacks.
- Name founders and past figures, never the sitting chief executive, whom the board can replace during play.
- `related` lists every listed company the text names; the page links them.

## Colour
Tokens live in `assets/styles/app.css` (`@theme`); charts read them through `THEME_COLORS`, `SERIES` and
`withAlpha()` in `assets/js/utils/colors.js`. No hex or `rgb()` anywhere else (the district scene art is the one
exception), and no Tailwind palette classes (`text-green-400`).

| Meaning | Token |
| --- | --- |
| Up, gain | `text-secondary` |
| Down, loss | `text-tertiary` |
| Unchanged | `text-on-surface-variant` |
| Unknown ("—") | `text-on-surface-faint` |
| Caution that is not a fall: overbuilt, stressed, covenant breached, curve inverted | `text-warning` |
| Accent, links, selected | `text-primary` |

- Red is only for a fall or a loss. A caution at either extreme is amber.
- Text never fades (`text-on-surface-variant/50` drops under 4.5:1); use `text-on-surface-faint`. Only aria-hidden
  separators may fade.
- Chart series take the `SERIES` slots in legend order, headline first, and never skip a slot.

## Type
- IBM Plex Sans for chrome and prose; `font-mono` only for figures (prices, tickers, table numbers).
- Sentence case everywhere. Capitals only in column headings, which get them from `.table-head`.
- Plain page headers: a title and one line under it. No eyebrow hero cards, icon tiles in a rainbow of hues, glow
  shadows, left accent borders, "Terminal" or "Engine" names, or source badges.

## Numbers
Format through the shared filters, which have live twins in `assets/js/utils/formatters.js` so a figure looks the
same before and after the feed repaints it.

| Figure | Twig | JS |
| --- | --- | --- |
| Up/down colour | `x\|signed_class` | `signedClass(x)` |
| Percentage of a fraction | `x\|pct(2)`, signed `x\|pct(1, true)` | `formatPercent(x, 2, false, true)` |
| Dollars | `x\|money(2)`, signed `x\|money(2, true)` | `formatCurrency(x)` |
| Large dollars | `x\|format_large('$')` | `formatLarge(x, '$')` |
| Event badge text | `x\|lower\|capitalize` | `sentenceCase(x)` |

- Rates and changes arrive as fractions (`PriceChangeFeed` returns 0.0342 for 3.42%); `|pct` scales them.
- A missing figure prints "—", never a false 0.00%. An unchanged figure is neutral and carries no "+".
- The sign goes outside the symbol: "-$12.00", not "$-12.00".

## Components
- `.table-head` on a `<thead>` or header `<tr>`.
- `.badge` plus a tone: `badge-up`, `badge-down`, `badge-warn`, `badge-accent`, `badge-neutral`. Sentence case.
  `EventPresenter` hands out the tone as `badgeClass`.
- `.term` on a label that explains itself on hover, with `ui.tooltip(text)` beside it:
  `{% import 'partials/_ui.html.twig' as ui %}`.
- `.seg-btn` for range and filter buttons; state lives in `aria-pressed`.
- `<p class="source-note">{{ source_line('statistical-office') }}</p>` under a chart. Never name a Twig function
  `source`: it would replace Twig's own, which the web profiler uses.

## Checking a change
- `php vendor/bin/phpunit tests/Twig` runs the style test (0.03 s). Two ratchets cap the percentages and dollar
  figures still formatted by hand; lower them when you move pages over, never raise them.
- `bin/render-pages [page ...]` renders the pages from stubs and a headless politics run into `var/render` and
  screenshots them in headless Firefox. Look at the PNG before reporting a UI change.
