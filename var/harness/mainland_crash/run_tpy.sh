#!/bin/bash
# run_tpy.sh <arm> <seed>: arm B (committed tree from setup.sh, crash on) at a given tick rate.
#   B360 = TPY 360, B3600 = TPY 3600 (the live game's rate). Env: YEARS (18), POLITICS (1), SNAP.
# Outputs runs/<arm>-<seed>.{macro.jsonl,jsonl,log}
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir from setup.sh}
mkdir -p "$H/runs"
case "$1" in
  B360) T=360 ;;
  B3600) T=3600 ;;
  *) echo "unknown arm $1"; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/mctree" OVR="" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-18}" TPY="$T" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/MainlandCrashHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
