#!/bin/bash
# run.sh <arm> <seed>: world.php (macro loop + politics, no board) for one arm.
#   W = working tree; E<x> = OVR copy with METALS_DEMAND_ELASTICITY = x (make_ovr.sh); O<x>v<s> = oil elasticity and
#   volatility (make_oil_ovr.sh); H = HEAD tree at $HEADTREE.
# Output runs/<arm>-<seed>.jsonl. Env: YEARS (30), TPY (360), HEADTREE.
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
BASEV=""; OVRV=""
case "$1" in
  W) ;;
  E*|O*) OVRV="$H/ovr$1" ;;
  H) BASEV=${HEADTREE:?set HEADTREE to a git archive HEAD tree with vendor copied} ;;
  *) echo "usage: run.sh W|E<x>|H <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env BASE="$BASEV" OVR="$OVRV" bin/php-slot php -d memory_limit=3G "$H/world.php" "$2" "${YEARS:-30}" "${TPY:-360}" \
  "$H/runs/$1-$2.jsonl"
