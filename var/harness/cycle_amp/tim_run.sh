#!/bin/bash
# tim_run.sh <arm> <seed>: TimingHarnessTest.php on the spending-timing arms (trees from tim_setup.sh).
#   A t0tree (HEAD 9344b2de); B t1tree + ovrB (sd 0.002); C t1tree (0.004); D t1tree + ovrD (0.006).
# Env: YEARS (33), TPY (360), POLITICS (1), SNAP (dir from tim_setup.sh). Outputs runs/T<arm>-<seed>.*
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir from tim_setup.sh}
mkdir -p "$H/runs"
case "$1" in
  A) OVRV="" ; TREE=t0tree ;;
  C) OVRV="" ; TREE=t1tree ;;
  *) OVRV="$SNAP/ovr$1" ; TREE=t1tree ;;
esac
rm -f "$H/runs/T$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/$TREE" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-33}" TPY="${TPY:-360}" \
  OUT="$H/runs/T$1-$2.jsonl" MACRO_OUT="$H/runs/T$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/TimingHarnessTest.php" > "$H/runs/T$1-$2.log" 2>&1
