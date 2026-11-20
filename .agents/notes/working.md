# Standing decisions: working & code structure

What past work settled: the user's decisions, approaches tried and rejected, choices that look like bugs but are
intended. Reopening a rejected approach is allowed, but say that you are doing it and why. Where the code now disagrees
with a line here, the code wins; tell the user so the line can be fixed. Status and open work do not belong here.

## Working with the user

- Explain a choice in plain words: what is wrong, why it matters, and what each option does to the game a player sees
  (bigger or smaller firm, steadier or lumpier earnings, needs a reseed). Say plainly when an option keeps something
  known to be wrong. Model internals in a question get answered without being understood.
- A live macro dump is a normal run of the model, never an outlier or a tail draw. Start from its anatomy (what drove
  each slump, what held it there). If a harness disagrees, that is a question about the harness or the model.
- Core loop over features: weigh work by how often a player sees its effect. Earnings, prices, cycles and gluts come
  every quarter; rare events (market entry, ~0.2 a year board-wide) do not earn long calibration rounds.
- All the way or not: a partial overlay on a mechanism (per-line drifts on 7 models) was reverted. A redesign comes
  back whole or not at all.
- Stop tuning to a target (roll size, a moment) and fix defects by rule.
- A plan file in `docs/plans/` written for a handoff is deleted once its work is verified; the commit message and
  RUN.md keep the record.
- A diagnosis that drives a mechanism change, or a handoff plan that hits its stop rules, gets an independent second
  opinion (symptoms, not the diagnosis) before the next attempt.

## Code structure

- One refactor step per commit, proved byte-identical on seeded whole-board runs (mask the timing field); page
  refactors by diffing page arrays or renders.
- Kept large on purpose: MacroStateDTO, the bank and insurance models, the ticker's constructor (each dependency a
  phase), the macro-subsystem formulas sector models read.
- Page assembly lives in `*PageBuilder` classes; one chart factory; '—' for a missing figure.
- A Redis store that rebuilds records from a fixed field list drops new fields silently: give a new field a round-trip
  test.
