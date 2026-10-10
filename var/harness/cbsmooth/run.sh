#!/bin/bash
# run.sh <arm> <seed>: live-macro full-board run of CbSmoothHarnessTest.php on a frozen snapshot tree.
#   A  CB_SMOOTHING_SPEED 4.0 (snapshot as is)
#   B  0.65   (OVR copy in $SNAP/ovrB)
#   C  1.4    (OVR copy in $SNAP/ovrC)
# Env: YEARS (21), TPY (360), POLITICS (1), SNAP (scratch dir holding cbtree/ ovrB/ ovrC/).
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/fa6eb337-3fae-4393-a0c0-312404e10f42/scratchpad}
mkdir -p "$H/runs"
case "$1" in
  A) OVRV="" ;;
  B|C) OVRV="$SNAP/ovr$1" ;;
  *) echo "usage: run.sh A|B|C <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/cbtree" OVR="$OVRV" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-21}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/CbSmoothHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
