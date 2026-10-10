#!/bin/bash
# fund_run.sh <arm> <seed>: SovereignFundDynamicsTest's macro-only simulate() with a debt decomposition (fund_debt.php),
# on the frozen snapshot tree $SNAP/cbtree.
#   A1 / A0  CB_SMOOTHING_SPEED 4.0, fund on / off
#   B1 / B0  0.65 (OVR copy in $SNAP/ovrB), fund on / off
# Output: runs/fund-<arm>-<seed>.json
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/fa6eb337-3fae-4393-a0c0-312404e10f42/scratchpad}
mkdir -p "$H/runs"
case "$1" in
  A1|A0) OVRV="" ;;
  B1|B0) OVRV="$SNAP/ovrB" ;;
  *) echo "usage: fund_run.sh A1|A0|B1|B0 <seed>" >&2; exit 2 ;;
esac
FUND=${1:1:1}
rm -f "$H/runs/fund-$1-$2.json"
cd "$PROJ" && env BASE="$SNAP/cbtree" OVR="$OVRV" \
  bin/php-slot php -d memory_limit=1G "$H/fund_debt.php" "$2" "$FUND" "$H/runs/fund-$1-$2.json"
