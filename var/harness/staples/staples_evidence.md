# Staples evidence

## 2026-10-16 staples-step1 sanity check (worktree staples-step1, uncommitted patch)

Question: is the ConsumerStaplesBusinessModel rework (squeeze bonus deleted, hedge as buying lag, base pricing rule,
demand = gap x 0.5, recall/fine as special item, CROP grower's windfall at the agri index) sane on the whole board?

Harness: `StaplesHarnessTest.php` via `run.sh` (see RUN.md). Macro path recorded live on the baseline tree (`rec`),
replayed in both arms (`base` = patch reversed, `new` = worktree). Seeds 1-4, 20 years, 360 ticks/year. CROP draws one
fewer stream, so arms share the macro path but not the micro draws: per-arm means, no paired test.

Table: `staples_step1.out`. Headlines (base -> new):
- No failures, reorganisations, NaN or non-positive prices; index total return 9.0% -> 8.9%/y.
- CROP corr(operating margin, agri index) -0.03 -> +0.74.
- SGRB median EPS surprise +11.6% -> +8.3%; beat rate stays ~97%. Every staples name beats 92-99% of quarters in both
  arms with a median +8% surprise: a standing beat that is not the bonus.
- Revenue response, slope of yoy log revenue growth on the yoy change in output_gap_ema: 0.0-0.4 -> 0.5-0.9, se 0.1-0.4.
- Recall/fine report-day move -0.8%/-0.4% -> -0.05%/-0.14% (special item, out of the Street figure).
- Realised vol (tick prices) 0.18-0.35 in both arms vs configured 0.12-0.16; model cvol 0.14-0.18.
- PINT end/start price 1.14 -> 0.68; LARK ~0.5 in both.

Caveats: 4 seeds, sanity level only. Replay drops the board -> macro feedback. Recall/fine counted from the lore line.
