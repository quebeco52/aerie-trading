#!/bin/bash
# gb_run.sh <arm> <seed>: GapBiasHarnessTest.php (why the gap averages below zero), all on $SNAP/gbtree (git archive 9344b2de).
#   A POLITICS=1; B POLITICS=0 (founding levers); C linear Phillips (OVR $SNAP/ovrC) POLITICS=1; D C with POLITICS=0.
# Env: YEARS (33), TPY (360), SNAP (dir holding gbtree/ and ovrC/). Outputs runs/G<arm>-<seed>.*
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir holding gbtree/}
mkdir -p "$H/runs"
case "$1" in
  A) OVRV="" ; POL=1 ;;
  B) OVRV="" ; POL=0 ;;
  C) OVRV="$SNAP/ovrC" ; POL=1 ;;
  D) OVRV="$SNAP/ovrC" ; POL=0 ;;
  *) echo "unknown arm $1"; exit 2 ;;
esac
[ "$POL" = 0 ] && POL=""
rm -f "$H/runs/G$1-$2.macro.jsonl"
cd "$PROJ" && env BASE="$SNAP/gbtree" OVR="$OVRV" POLITICS="$POL" SEED="$2" YEARS="${YEARS:-33}" TPY="${TPY:-360}" \
  OUT="$H/runs/G$1-$2.jsonl" MACRO_OUT="$H/runs/G$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/GapBiasHarnessTest.php" > "$H/runs/G$1-$2.log" 2>&1
