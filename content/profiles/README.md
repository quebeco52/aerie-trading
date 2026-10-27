# Tickbird Research company profiles

Each file here is one long-form company profile, published at `/stock/<TICKER>/profile` and linked from the stock
page's "About". Name the file by the company's ticker (`LAKE.md`). Files starting with `_` are drafts and are not
published; copy `_template.md` to start a new profile.

`_drafts/` holds the old exchange descriptions of 73 companies, kept for their stories: each is a profile file with its
front matter stubbed and `related` filled from the names in the text. Rewrite one into sections and move it up here
to publish it. Nothing in `_drafts/` is published or tested.

## Front matter

Every profile opens with this block:

```yaml
---
headline: 'Lakebird Bank: the balance sheet the District was built on'   # quote it when it holds a colon
standfirst: One or two sentences under the headline, shown in lists and the page description.
related: [BRKW, SWAN]                    # every listed company the text names, by ticker
---
```

## Body

Three to six sections, each opening with a `##` heading; the body starts with the first one. Plain Markdown
inside them: paragraphs, lists, **bold**. Link an Almanac entry with `[[slug]]`, which prints its title, or
`[[slug|your own words]]`.

## House rules

`tests/Service/Lore/CompanyProfilesTest.php` checks these; `.agents/FRONTEND.md` has the rest.

- 400-800 words of section text, written by Tickbird Research for terminal subscribers: history, people, the firms
  around the company, and what the desk is watching. Deadpan, British spelling, the District's institution names.
- Every figure the text quotes (a revenue share, a margin, a payout, a beta, a free float) is pinned to the seed data
  or `StockModelTuning` by a row in the test's `figureProvider`. Add the row with the figure.
- Nothing the model does not do: no immunity, monopoly or behaviour the engine lacks.
- Name founders and past figures, never the sitting chief executive, whom the board can replace during play.
- `related` lists exactly the listed companies the text names, and every `[[link]]` points at an Almanac entry.
- No marketing words or em dashes (`StockInfoTest::AI_TELLS`).
