#!/bin/bash
# y10_run.sh <arm> <seed>: CbSmoothHarnessTest.php with the curve decomposition (DECOMP=1) on a frozen snapshot tree.
#   Y0  baseline: $SNAP/y10tree as is (git archive of the tree under test)
#   Y1  the long-end + breakeven change: OVR copies in $SNAP/ovr1
# Env: YEARS (21), TPY (360), POLITICS (1), SNAP (dir holding y10tree/ ovr1/).
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir holding y10tree/ and ovr1/}
mkdir -p "$H/runs"
case "$1" in
  Y0) OVRV="" ;;
  Y*) OVRV="$SNAP/ovr${1#Y}" ;;
  *) echo "usage: y10_run.sh Y0|Y1|Y<n> <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/y10tree" OVR="$OVRV" DECOMP=1 POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-21}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/CbSmoothHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
