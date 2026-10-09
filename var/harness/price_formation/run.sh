#!/bin/bash
# run.sh <arm> <seed>: price-formation replay (PriceFormationHarnessTest.php, PF_OUT).
#   rec     AFTER tree, free macro, records the macro path for <seed>
#   after   AFTER tree (this worktree), replays the recorded path
#   c       alias of after (round 2: 2a+2b+2c = worktree src)
#   cx      as c, plus PF_X=1: per-name quarterly px, fv, eps, CoE, CAPM, floor, beta (decomposition round)
#   cg      as cx, plus PF_G=1: assumed growth legs (recomputed from MarketPricingContext), revenue, per-report flows
#   cgchk   as cg, plus PF_FVCHK=1: re-strikes evaluateFundamentalState (reflection) to check the inputs
#   cb      as cg, plus PF_B=1: equity bridge (forwarding proxies on EarningsEngine, TreasuryEngine, M&A engine), levels, flows
#   2a|2b   /tmp/claude-1000/t2a|t2b (worktree copy with src = var/arms/<arm>-src), replay
#   before  BEFORE tree (BEFORE=<tree>, default /tmp/claude-1000/b1), replays the same path
# Env: YEARS (20), TPY (360), RUNS (default <this dir>/runs). Outputs $RUNS/<arm>-<seed>.{json,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
AFTER=$(cd "$H/../../.." && pwd)
BEFORE=${BEFORE:-/tmp/claude-1000/b1}
RUNS=${RUNS:-$H/runs}
mkdir -p "$RUNS" "$H/macro"
GZ=$H/macro/path-${TPY:-360}-$2.gz
case "$1" in
  rec)    MAC="MACRO_RECORD=$GZ"; TREE=$AFTER ;;
  after)  MAC="MACRO_REPLAY=$GZ"; TREE=$AFTER ;;
  before) MAC="MACRO_REPLAY=$GZ"; TREE=$BEFORE ;;
  c)      MAC="MACRO_REPLAY=$GZ"; TREE=$AFTER ;;
  cx)     MAC="MACRO_REPLAY=$GZ"; TREE=$AFTER; export PF_X=1 ;;
  cg)     MAC="MACRO_REPLAY=$GZ"; TREE=$AFTER; export PF_X=1 PF_G=1 ;;
  cb)     MAC="MACRO_REPLAY=$GZ"; TREE=$AFTER; export PF_X=1 PF_G=1 PF_B=1 ;;
  cgchk)  MAC="MACRO_REPLAY=$GZ"; TREE=$AFTER; export PF_X=1 PF_G=1 PF_FVCHK=1 ;;
  2a|2b|2c) MAC="MACRO_REPLAY=$GZ"; TREE=/tmp/claude-1000/t$1 ;;
  *) echo "usage: run.sh rec|after|before|c|2a|2b <seed>" >&2; exit 2 ;;
esac
cd "$TREE" && env "$MAC" BASE="$TREE" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" PF_OUT="$RUNS/$1-$2.json" \
  "$AFTER/bin/php-slot" php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$H/bootstrap.php" --no-configuration \
  "$H/PriceFormationHarnessTest.php" > "$RUNS/$1-$2.log" 2>&1
