# Reported-vol harness

Question family: does a name's reported volatility (`current_volatility`) match its realised daily vol, and what a
change does to spreads, ADV, earnings surprises and the option desk's skew. Paired base (HEAD) vs treat (working tree).

- Harness: `var/harness/vol_report/FullMarketHarnessTest.php`, a fork of `var/harness/FullMarketHarnessTest.php` (same
  env, see `var/harness/full/RUN.md`). Adds `VR_OUT=<json>`: per tick and name [price, reported vol, spread_bps,
  adv_shares, volume, market_cap]; per report [ticker, tick, reported EPS, consensus EPS]; each quarter the 30-day
  `OptionPricingEngine::surfaceFor` skewness and ATM vol; the dead list.
- Run script: `var/harness/vol_report/run.sh base|treat <seed>`, live macro with `POLITICS=1` (no replay: the Diet
  is ignored on replay, so the arms' macro paths diverge on the same seed). YEARS (4), TPY (360).
  `treat` runs the main tree with BASE=main (cache `var/harness/cache-aerie-trading`, delete after a constructor
  change); `base` needs `BASE_TREE=<git archive HEAD tree with a vendor symlink>`.
- Build the base tree: `mkdir <scratch>/vr-base && git archive HEAD | tar -x -C <scratch>/vr-base &&
  ln -s <main>/vendor <scratch>/vr-base/vendor`. Remove it afterwards.
- Batch: `BASE_TREE=<tree> bin/harness-batch var/harness/vol_report/run.sh 1-8 base treat &&
  python3 var/harness/vol_report/analyse.py 1-8 > var/harness/vol_report/<name>.out`
- Analysis: `analyse.py <seeds> [warmup_ticks=90] [tpy=360]`. Ratio = RMS reported vol / realised std of daily log
  returns x sqrt(tpy), names alive the whole run. Active decile = top 10% by traded value in base (same set in treat).
  Board index = cap-weighted simple return. |surprise| = |EPS - consensus| / |consensus|, capped at 1. Earnings-day
  return = |log return| on the report tick (`ELAG=1` checks the next tick; it is smaller).
- Runtime: ~7 s per simulated year with the Diet; 8 seeds x 2 arms x 4 years in ~1 min wall.
