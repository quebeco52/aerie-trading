# margin_ratchet: removing the plant-modernization margin ratchet

Question: does removing `applyAssetDepreciationDecay`'s stored-margin channel (StandardOperatingPhysicsTrait) stop the
board-wide drift in non-financial operating margins, and does it change firm failures?

Harness: `FullMarketHarnessTest.php`, fork of `earnings_b1/` (live macro, no politics). Adds per-quarter `som`
(stored `Stock::operatingMargin`) and per-firm `om_final`, `ind`, `isfin` in `final`. `bootstrap.php` is the
earnings_b1 bootstrap unchanged (BASE autoloader for snapshot trees; kernel cache in `cache-<snapshot>/`).
YEARS=30, TPY=360, ~2-4 min per run, 8 parallel.

Arms (paired by seed):
- `before` = HEAD 00ead0c snapshot: `git archive HEAD | tar -x -C <snap>`, then `cp -a .env* vendor <snap>/`.
  Default `<snap>` is this session's scratchpad `.../scratchpad/mr-before`; set `BEFORE=<dir>` to point elsewhere.
- `after` = the main checkout working tree (ratchet removed, uncommitted at the time).

Run (H must be absolute; outputs in `$H/runs/<arm>-<seed>.jsonl`):

    H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/margin_ratchet \
      bin/harness-batch var/harness/margin_ratchet/run.sh 1-8 before after && \
      python3 var/harness/margin_ratchet/analyse.py --sectors > var/harness/margin_ratchet/stage8.out

Analysis: `analyse.py [runs dir] [--sectors]`. Targets: (1) ex-fin ex-REIT aggregate EBIT margin (sum EBIT / sum
revenue per year), mean of last 5 years minus years 0-2, pp; (2) median over surviving ex-fin firms of stored
operatingMargin final minus opening, pp; (3) bankruptcies + reorganizations per run. `--sectors` adds a per-industry
table (stored-margin change and own EBIT-margin drift, by arm). Delete `cache*/` after a service/constructor change.
