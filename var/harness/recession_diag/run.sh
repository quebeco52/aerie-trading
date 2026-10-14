#!/bin/bash
# run.sh <arm> <seed>: RecessionDiagHarnessTest.php (live macro, whole board, politics) on a frozen snapshot tree.
#   D1  the snapshot tree as is ($TREE: git archive HEAD + rsync of the working tree's src config tests bin + vendor COPIED)
#   OVR=<dir> (optional) prepends edited src copies (bootstrap.php), e.g. P0 = CONSOLIDATION_PACE 10.0.
# Writes runs/<arm>-<seed>.macro.jsonl (quarter rows), runs/<arm>-<seed>.tick.csv and .gap.jsonl (gap probe, one row a tick).
# Env: TREE (required), YEARS (21), TPY (360), POLITICS (1).
set -u
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
TREE=${TREE:?set TREE to the snapshot tree}
mkdir -p "$H/runs"
rm -f "$H/runs/$1-$2.macro.jsonl" "$H/runs/$1-$2.tick.csv" "$H/runs/$1-$2.gap.jsonl"
cd "$PROJ" && env BASE="$TREE" OVR="${OVR:-}" POLITICS="${POLITICS:-1}" SEED="$2" YEARS="${YEARS:-21}" TPY="${TPY:-360}" \
  MACRO_OUT="$H/runs/$1-$2.macro.jsonl" TICK_OUT="$H/runs/$1-$2.tick.csv" GAP_OUT="$H/runs/$1-$2.gap.jsonl" \
  bin/php-slot php -d memory_limit=3G "$TREE/vendor/bin/phpunit" --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/RecessionDiagHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
