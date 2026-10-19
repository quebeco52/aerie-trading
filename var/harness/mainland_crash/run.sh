#!/bin/bash
# run.sh <arm> <seed>: MainlandCrashHarnessTest.php on the mainland-crash arms (tree from setup.sh).
#   A mctree + ovrA (crash off, sigma 0.00490); B mctree as is (crash 0.025/yr, sigma 0.00452).
# Env: YEARS (100), TPY (360), POLITICS (1), SNAP (dir from setup.sh). Outputs runs/<arm>-<seed>.*
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir from setup.sh}
mkdir -p "$H/runs"
case "$1" in
  A) OVRV="$SNAP/ovrA" ;;
  B) OVRV="" ;;
  *) echo "unknown arm $1"; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/mctree" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-100}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/MainlandCrashHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
