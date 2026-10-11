#!/bin/bash
# crisis_run.sh <arm> <seed>: CbSmoothHarnessTest.php (live macro, whole board, politics) on a frozen snapshot tree.
#   K0  baseline: $SNAP/crtree as is (the tree before the recession-regime cut speed)
#   K1  the recession-regime cut speed: OVR copies in $SNAP/ovrK (src-relative paths)
#   K<n> any other OVR set in $SNAP/ovr<n>
# Env: YEARS (21), TPY (360), POLITICS (1), SNAP (dir holding crtree/ ovrK/).
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir holding crtree/ and ovrK/}
mkdir -p "$H/runs"
case "$1" in
  K0) OVRV="" ;;
  K1) OVRV="$SNAP/ovrK" ;;
  K*) OVRV="$SNAP/ovr${1#K}" ;;
  *) echo "usage: crisis_run.sh K0|K1|K<n> <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/crtree" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-21}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/CbSmoothHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
