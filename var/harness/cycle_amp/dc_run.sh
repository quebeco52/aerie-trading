#!/bin/bash
# dc_run.sh <arm> <seed>: DragCompHarnessTest.php on $SNAP/dctree (from dc_setup.sh).
#   A compensators zeroed (OVR $SNAP/ovrA); B working tree as is.
# Env: YEARS (33), TPY (360), POLITICS (1), SNAP. Outputs runs/D<arm>-<seed>.*
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir from dc_setup.sh}
mkdir -p "$H/runs"
case "$1" in
  A) OVRV="$SNAP/ovrA" ;;
  B) OVRV="" ;;
  *) echo "unknown arm $1"; exit 2 ;;
esac
rm -f "$H/runs/D$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/dctree" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-33}" TPY="${TPY:-360}" \
  OUT="$H/runs/D$1-$2.jsonl" MACRO_OUT="$H/runs/D$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/DragCompHarnessTest.php" > "$H/runs/D$1-$2.log" 2>&1
