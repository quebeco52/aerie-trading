#!/bin/bash
# pers_run.sh <arm> <seed>: CycleAmpHarnessTest.php on the frozen snapshot $SNAP/pstree (gap persistence question).
#   A  tree as is; B, C: OVR copies in $SNAP/ovrB, $SNAP/ovrC (src-relative paths).
# Env: YEARS (33), TPY (360), POLITICS (1), SNAP (dir holding pstree/ and ovr<arm>/).
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir holding pstree/}
mkdir -p "$H/runs"
case "$1" in
  A) OVRV="" ;;
  *) OVRV="$SNAP/ovr$1" ;;
esac
rm -f "$H/runs/P$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/pstree" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-33}" TPY="${TPY:-360}" \
  OUT="$H/runs/P$1-$2.jsonl" MACRO_OUT="$H/runs/P$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/PersistenceHarnessTest.php" > "$H/runs/P$1-$2.log" 2>&1
