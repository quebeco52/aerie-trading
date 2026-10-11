#!/bin/bash
# y10_macro_run.sh <arm> <seed>: y10_macro.php (macro loop + politics, no board, no fund) on the frozen snapshot tree.
#   MY0 baseline $SNAP/y10tree; MY1 with OVR $SNAP/ovr1. Output runs/<arm>-<seed>.macro.jsonl, read by y10_analyse.py MY0 MY1.
# Env: YEARS (21), TPY (360), SNAP.
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
SNAP=${SNAP:?set SNAP to the dir holding y10tree/ and ovr1/}
mkdir -p "$H/runs"
case "$1" in
  MY0) OVRV="" ;;
  MY*) OVRV="$SNAP/ovr${1#MY}" ;;
  *) echo "usage: y10_macro_run.sh MY0|MY<n> <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env BASE="$SNAP/y10tree" OVR="$OVRV" bin/php-slot php -d memory_limit=3G "$H/y10_macro.php" "$2" "${YEARS:-21}" "${TPY:-360}" \
  "$H/runs/$1-$2.macro.jsonl" > "$H/runs/$1-$2.log" 2>&1
