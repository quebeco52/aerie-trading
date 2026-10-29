# Wellspring Life (WELL), Honeyguide Partners (HONY), DOVE's reserve-matched book

Question: do WELL (`LifeInsurerBusinessModel`: protection, annuities and buyouts on the 30y over its trend, savings
fees on equity wealth, a 2.5% guarantee on 40% of reserves) and HONY (`InsuranceBrokerBusinessModel`: commissions on
the underwriters' measured rate level over its trend, benefits, fiduciary interest at the policy rate) open at a
sensible size and behave sensibly on the live board? Does DOVE stay sensible with policy-reserve matching (HTM share
0.90 instead of 0.40)?

- Harness: `LifeBrokerHarnessTest.php` (extends `lib/BoardRun.php`; read-only probe). Writes `runs/<arm>-s<seed>.json`
  (`LIFE_BROKER_OUT`): each firm's opening and every quarter (rev, om, ni, eps, px, bvps, equity, debt, rating,
  streams, float, statutory surplus, securities mark and recognised part, carrying yield, life reserves, annuity
  yield trend) with the macro read (policy and neutral rate, 10y/30y, insurance rate level and trend, wealth dev).
- Run script: `run.sh <arm> <seed>` (env YEARS 20, TPY 360; live macro, no Diet). The arm is only a label.
- Batch: `bin/harness-batch var/harness/life_broker/run.sh 1-4 v1`
- Analysis: `python3 var/harness/life_broker/analyse.py [arm]` (run from this directory).
- After adding a model class or changing `config/`, delete `var/harness/cache/test`.
- Arms run 10-29: `v1` (stream shares recorded after the market terms: HONY's fiduciary line compounded to 19% of
  revenue; WELL's seed 2 loss is a $25B goodwill write-off of in-industry bolt-ons when P/B fell below 1) and `v2`
  (shares recorded before the market terms, the shipped state).
- Arms `fv`/`fv2` (10-30, YEARS=12): the probe also records each firm's price against the engine's fair value before
  the market's repricing (`pf`: t, price, fair value, levered beta, equity valuation shift) and WELL's fv, analyst
  target, roe_ttm, current roe and cost of equity. Question: was WELL's live fall (-86% at the trough) a rich opening?
  No: it opens at its fair value (FV/B 1.54-1.65 on ROE 11-13% against ke 8.3%). The board's price/fair-value gap
  (0.5-1.7 by year 11) is the market's valuation shift times beta; price against the repriced fair value stays within
  about ±0.15 log. Live WELL = that discount phase plus its own earnings trough at a 0.75% policy rate.
