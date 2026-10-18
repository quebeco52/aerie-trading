#!/bin/bash
# run.sh <arm> <seed>: ComoveHarnessTest.php on the common-demand-factor arms (trees from setup.sh).
#   A cotree0 (HEAD); B cotree1 + ovrB (loading 0.80); C ovrC (0.90); D cotree1 as is (0.95); E ovrE (D + TFP 1.0).
# Env: YEARS (100), TPY (360), POLITICS (1), SNAP (dir from setup.sh). Outputs runs/<arm>-<seed>.*
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir from setup.sh}
mkdir -p "$H/runs"
case "$1" in
  A) TREE=cotree0; OVRV="" ;;
  D) TREE=cotree1; OVRV="" ;;
  *) TREE=cotree1; OVRV="$SNAP/ovr$1" ;;
esac
rm -f "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/$TREE" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-100}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/ComoveHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
