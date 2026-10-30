#!/bin/bash
# run.sh <arm> <seed>: valuation predictability (ValuationHarnessTest.php), live macro, no politics, price_vol probe on.
#   head   working tree (HEAD when clean)
# Env: YEARS (20), TPY (360). Outputs runs/<arm>-<seed>.{jsonl,log,val.json}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
case "$1" in
  head) BASEV="" ;;  # working tree
  base) BASEV=${BASE:?set BASE=<tree>} ;;  # BASE tree (git archive + vendor symlink)
  d1) BASEV=${BASE:-} ;;  # fix D1: working tree when it was uncommitted; from a8207c15 the BASE tree (pass BASE=<tree>)
  d2) BASEV="" ;;  # working tree with fix D2 (fair-value revision passed into the price)
  c1) BASEV=${BASE:?set BASE=<tree>} ;;  # a8207c15 + the cycle fix alone (patched BASE tree)
  f1) BASEV="" ;;  # working tree: cycle fix + F1 (durable demand responds to the change in the lagged gap)
  d2b) BASEV="" ;;  # working tree: D2 + no cycle term in perpetual growth + revision variance on its own quarter-window EMA
  crash|b1|a1) BASEV=""  # crash: 98b7d96a; b1: + fix B (equity's riskless rate off the expected path); a1: + fix A (market moves reprice fair value, fading). Working tree with the quarterly macro rows (MACRO_OUT, BOARD_NI) for the crash anatomy
    rm -f "$H/runs/$1-$2.macro.jsonl"; export MACRO_OUT="$H/runs/$1-$2.macro.jsonl" BOARD_NI=1 ;;
  mbase) BASEV=${BASE:?set BASE=<tree>} ;;  # momentum: frozen tree of b29b723f (git archive + vendor symlink)
  mom1|mom2) BASEV="" ;;  # momentum: working tree at the time, franchise in margin points (mom1) / as a log-odds power (mom2)
  mbase2) BASEV=${BASE:?set BASE=<tree>} ;;  # mbase rerun with agent_rows / franchise_rows logging
  mom3|chk) BASEV="" ;;  # working tree: leverage-elastic franchise news (one-constant arms were OVR overlays of MarketEngine)
  fbase) BASEV=${BASE:?set BASE=<tree>} ;;  # franchise fix: frozen tree of 2b345931 (git archive + vendor symlink)
  ffix) BASEV="" ;;  # working tree: power steps on the elasticity at the current power, news share capped by vol 0.35
  ffix50) BASEV=""; : "${OVR:?set OVR=<dir>}" ;;  # ffix with FRANCHISE_POWER_VOLATILITY 0.50 (OVR copy of MarketEngine)
  fnow) BASEV="" ;;  # franchise life (10-30): working tree at 625e9ca8, 10-year half-life, power vol 0.35
  fhl3) BASEV="" ;;  # working tree: 3-year half-life, power vol 0.20
  fx0|fx1) [ "$1" = fx0 ] && BASEV=${BASE:?set BASE=<tree>} || BASEV=""  # mainland fade (10-30): fx0 = BASE tree of 17923936, fx1 = working tree (the foreign index gives its market-factor move back at the home half-life); quarterly macro rows
    rm -f "$H/runs/$1-$2.macro.jsonl"; export MACRO_OUT="$H/runs/$1-$2.macro.jsonl" BOARD_NI=1 ;;
  mv1) BASEV="" ;;  # working tree on ac0ae3ae: the board's multiple carries the market factor's repricing (base fhl3)
  rn0|dr1|dr2) [ "$1" = rn0 ] && BASEV=${BASE:?set BASE=<tree>} || BASEV=""  # recession pass-through (10-30): rn0 = frozen working tree with the revenue-noise fix, dr1 = + the market-wide discount-rate move priced at once; quarterly macro rows
    rm -f "$H/runs/$1-$2.macro.jsonl"; export MACRO_OUT="$H/runs/$1-$2.macro.jsonl" BOARD_NI=1 ;;
  fc0|fc1) [ "$1" = fc0 ] && BASEV=${BASE:?set BASE=<tree>} || BASEV=""  # factor on economic news (10-30): fc0 = BASE tree of 66379eba + the mainland surprise accruing tick by tick (same macro draws as fc1), fc1 = working tree (one premium, the factor loads on demand and mainland news); quarterly macro rows
    rm -f "$H/runs/$1-$2.macro.jsonl"; export MACRO_OUT="$H/runs/$1-$2.macro.jsonl" BOARD_NI=1 ;;
  fc1r8) BASEV=""; : "${OVR:?set OVR=<dir>}"  # fc1 with MARKET_FACTOR_NEWS_LOADING 0.8 (OVR copy of AssetMarketSubsystem)
    rm -f "$H/runs/$1-$2.macro.jsonl"; export MACRO_OUT="$H/runs/$1-$2.macro.jsonl" BOARD_NI=1 ;;
  fc8|ev1) [ "$1" = fc8 ] && BASEV=${BASE:?set BASE=<tree>} || BASEV=""  # economy's events (10-30 late): fc8 = frozen tree of the fc1 build at loading 0.8, ev1 = working tree (news weight on demand's own pull, disasters/crashes/crises priced as events over ~a week, sector price shock on sector demand news); quarterly macro rows
    rm -f "$H/runs/$1-$2.macro.jsonl"; export MACRO_OUT="$H/runs/$1-$2.macro.jsonl" BOARD_NI=1 ;;
  *) echo "usage: run.sh head|base|d1|d2|d2b|c1|f1|crash|b1|a1|mbase|mbase2|mom1|mom2|mom3|chk|fbase|ffix|ffix50|fnow|fhl3|mv1|fx0|fx1|rn0|dr1|dr2|fc0|fc1|fc1r8|fc8|ev1 <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env BASE="$BASEV" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" VAL_OUT="$H/runs/$1-$2.val.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/ValuationHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
