# The District Almanac

Each file here is one Almanac entry, published at `/almanac/<file name>`. Name the file by its slug: lower-case
words joined by hyphens (`harbour-ledger-affair.md`). Files starting with `_` are drafts and are not published;
copy `_template.md` to start a new entry.

## Front matter

Every entry opens with this block:

```yaml
---
title: The Harbour Ledger affair            # as the Almanac heads it
summary: One sentence, shown under the title and in lists.
category: Incident                          # Institution, History, Incident, Place or Person
sequence: 10                                # orders entries in time, smaller is earlier; never shown
about: [security-bureau]                    # institutions (ids below) or tickers it concerns
---
```

`about` decides where the entry is listed: an entry about `security-bureau` appears on the Security Bureau's page.
The ids are those of `App\Data\District\Institutions::PUBLISHERS` (`security-bureau`, `exchequer`,
`monetary-authority`, `aerie-exchange`, ...); a listed company goes in by its ticker (`WATCH`).

## Body

Plain Markdown: `##` section headings, paragraphs, lists, **bold**. Link another entry with `[[slug]]`, which prints
its title, or `[[slug|your own words]]`. A site page links with an ordinary Markdown link: `[the Bureau](/security-bureau)`.

## House rules

`tests/Service/Lore/AlmanacTest.php` checks these:

- Everything happens before the District's records begin, so nothing the game has played since can be contradicted,
  and no entry claims a market move, a price or a figure the simulation does not have.
- No dates. The Almanac places events against each other ("after the Harbour Ledger affair", "within the year",
  "when her term ran out"), never against the game's calendar: no "Year 1", "Year 12", "before Year 1". `sequence`
  orders entries and is never shown; leave gaps (10, 20, 30) so new entries fit between.
- Write as the Almanac: plain, factual, British spelling. The US is "the mainland"; no real places, agencies, countries,
  exchanges or funds (no FBI, SEC, Fed, NYSE).
- Name past figures freely. A Council-appointed head or a chief executive in post when the records begin can be
  named for what they did before then, never described as in post now.
- Every `[[link]]` must point at an entry that exists, and every `about` id must be a real institution or ticker.
- No marketing words or em dashes (the company profiles' list, `StockInfoTest::AI_TELLS`).
