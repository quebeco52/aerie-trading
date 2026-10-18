#!/bin/bash
# inv2_run.sh <arm> <seed>: InventoryHarnessTest.php on the accelerator step-2 arms (trees from inv2_setup.sh).
#   A s1tree (step 1); B s2tree (working tree); C s2tree + ovrC; D s2tree + ovrD; E s2tree + ovrE.
# Env: YEARS (33), TPY (360), POLITICS (1), SNAP (dir from inv2_setup.sh). Outputs runs/J<arm>-<seed>.*
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir from inv2_setup.sh}
mkdir -p "$H/runs"
case "$1" in
  A) OVRV="" ; TREE=s1tree ;;
  B) OVRV="" ; TREE=s2tree ;;
  *) OVRV="$SNAP/ovr$1" ; TREE=s2tree ;;
esac
rm -f "$H/runs/J$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/$TREE" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-33}" TPY="${TPY:-360}" \
  OUT="$H/runs/J$1-$2.jsonl" MACRO_OUT="$H/runs/J$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/InventoryHarnessTest.php" > "$H/runs/J$1-$2.log" 2>&1
