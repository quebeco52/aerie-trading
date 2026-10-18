#!/bin/bash
# wr_run.sh <arm> <seed>: WageRigidityHarnessTest.php on $SNAP/wrtree (from wr_setup.sh) with OVR $SNAP/ovr<arm>.
#   A factor 0.30 (as is), B 0.60, C 1.00.
# Env: YEARS (33), TPY (360), POLITICS (1), SNAP. Outputs runs/W<arm>-<seed>.*
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir from wr_setup.sh}
mkdir -p "$H/runs"
case "$1" in
  A|B|C) OVRV="$SNAP/ovr$1" ;;
  *) echo "unknown arm $1"; exit 2 ;;
esac
rm -f "$H/runs/W$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/wrtree" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-33}" TPY="${TPY:-360}" \
  OUT="$H/runs/W$1-$2.jsonl" MACRO_OUT="$H/runs/W$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/WageRigidityHarnessTest.php" > "$H/runs/W$1-$2.log" 2>&1
