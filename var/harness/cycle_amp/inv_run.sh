#!/bin/bash
# inv_run.sh <arm> <seed>: InventoryHarnessTest.php; A on $SNAP/pstree (HEAD), others on $SNAP/invtree (working-tree src).
#   A  HEAD tree as is; B-E: working-tree src (invtree) + OVR $SNAP/ovr<arm> (INVENTORY_COST_SHOCK_SD per arm).
# Env: YEARS (33), TPY (360), POLITICS (1), SNAP (dir holding pstree/ and ovr<arm>/).
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir holding pstree/}
mkdir -p "$H/runs"
case "$1" in
  A) OVRV="" ; TREE=pstree ;;
  *) OVRV="$SNAP/ovr$1" ; TREE=invtree ;;
esac
rm -f "$H/runs/I$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/$TREE" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-33}" TPY="${TPY:-360}" \
  OUT="$H/runs/I$1-$2.jsonl" MACRO_OUT="$H/runs/I$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/InventoryHarnessTest.php" > "$H/runs/I$1-$2.log" 2>&1
