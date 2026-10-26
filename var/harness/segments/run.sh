#!/bin/bash
# run.sh <arm> <seed>: whole-ticker replay with the segment probe (SegmentsHarnessTest.php).
#   head   BASE=<git archive tree + vendor symlink> (the committed tree), outlook salt 1000+SEED, segment probe
#   seg    working tree, same salt and probe
#   segplain  working tree, no segment probe (read-only check against seg)
# Macro paths are the idle topic's recorded -oc paths (replay, so arms see the same macro).
# Env: YEARS (20), TPY (360). Outputs runs/<arm>-<seed>.{jsonl,log,idle.json,seg.json}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
PATH_GZ=$PROJ/var/harness/idle/macro/path-$2-oc.gz
SEGV="SEG_OUT=$H/runs/$1-$2.seg.json"
case "$1" in
  head) BASEV=${BASE:?set BASE=<tree>} ;;
  seg)  BASEV="" ;;
  segplain) BASEV=""; SEGV="SEG_OFF=1" ;;
  gapfix) BASEV=""; export OVR=$H/ovr_gap ;;  # working tree + Redis store that reads segment_outlook_gap back
  *) echo "usage: run.sh head|seg|segplain|gapfix <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env MACRO_REPLAY="$PATH_GZ" "$SEGV" BASE="$BASEV" OUTLOOK_SALT=1 SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" IDLE_OUT="$H/runs/$1-$2.idle.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/SegmentsHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
