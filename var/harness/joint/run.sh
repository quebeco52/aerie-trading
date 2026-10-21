#!/bin/bash
# run.sh <arm> <seed>: whole board on a live macro with the Diet (POLITICS=1), JointHarnessTest.php.
#   pre    tree-d89691cc (git archive d89691cc + vendor symlink); rev-<x>: tree-rev-<x>
#   live   the tree as it stands (BASE = aerie-joint symlink to the checkout, so the cache is var/harness/cache-aerie-joint)
#   recNS  tree-noswan live, records the macro path to runs/recNS-<seed>.macro.gz; rpNS / rpSW replay that path
#          into tree-noswan / tree-swan (politics is off on replay); rpD12 replays it into tree-d12 (Batch P)
# Env: YEARS (20), TPY (360), MACRO_RECORD / MACRO_REPLAY (passed through; the rec/rp arms set them). Outputs runs/<arm>-<seed>.{jsonl,macro.jsonl,ticks.csv,anom.jsonl,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
RUNS=$H/runs
mkdir -p "$RUNS"
case "$1" in
  live|tr|tr-*) TREE=$H/aerie-joint ;;
  pre)  TREE=$H/tree-d89691cc ;;
  base) TREE=$H/tree-base ;;
  baseA|fixA|fixAB|baseC|fixC|baseD1|fixD1|baseD2|fixD2|fixD2b|fixD2c|capE|capE2|baseE|fixE|fixE2|baseF|fixF|fixG|fixH|fixI|fixJ|baseK|fixK|fixL|fixM) TREE=$H/tree-$1 ;;
  wt|wt-*) TREE=$H/tree-$1 ;;
  recNS) TREE=$H/tree-noswan; MACRO_RECORD=$RUNS/recNS-$2.macro.gz ;;
  rpNS)  TREE=$H/tree-noswan; MACRO_REPLAY=$RUNS/recNS-$2.macro.gz ;;
  rpSW)  TREE=$H/tree-swan;   MACRO_REPLAY=$RUNS/recNS-$2.macro.gz ;;
  rpD12) TREE=$H/tree-d12;    MACRO_REPLAY=$RUNS/recNS-$2.macro.gz ;;
  rpIPP) TREE=$H/tree-ipp;    MACRO_REPLAY=$RUNS/recNS-$2.macro.gz ;;
  rpT|rpF0|rpF) TREE=$H/tree-${1#rp}; MACRO_REPLAY=$RUNS/recNS-$2.macro.gz ;;
  pre-*|rev-*) TREE=$H/tree-$1 ;;
  *) echo "usage: run.sh live|pre|<tree arm> <seed>" >&2; exit 2 ;;
esac
P=$RUNS/$1-$2
rm -f "$P.jsonl" "$P.macro.jsonl" "$P.ticks.csv" "$P.anom.jsonl"
touch "$P.anom.jsonl"
cd "$PROJ" && env MACRO_RECORD="${MACRO_RECORD:-}" MACRO_REPLAY="${MACRO_REPLAY:-}" BASE="$TREE" POLITICS=1 HEALTH=1 SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" \
  OUT="$P.jsonl" MACRO_OUT="$P.macro.jsonl" TICK_CSV="$P.ticks.csv" ANOM_OUT="$P.anom.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/JointHarnessTest.php" > "$P.log" 2>&1
