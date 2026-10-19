#!/bin/bash
# run.sh <arm> <seed>: SlumpLengthHarnessTest.php on the slump-length arms (tree from setup.sh).
#   A sltree as is; B..H sltree + ovr<arm>.
# Env: YEARS (52), TPY (360), POLITICS (1), SNAP (dir from setup.sh). Outputs runs/<arm>-<seed>.*
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir from setup.sh}
mkdir -p "$H/runs"
case "$1" in
  A) OVRV="" ;;
  B|C|D|E|F|G|H) OVRV="$SNAP/ovr$1" ;;
  *) echo "unknown arm $1"; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/sltree" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-52}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/SlumpLengthHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
