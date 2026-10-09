#!/bin/bash
# batch.sh <runs-subdir> "<arms>" <seed>...: for each seed, run the arms in order (rec first if listed), seeds in parallel.
# php-slot caps concurrency. Env YEARS/TPY pass through. Example: YEARS=20 ./batch.sh runs "rec after before" 1 2 3
H=$(cd "$(dirname "$0")" && pwd)
export RUNS="$H/$1"
ARMS=$2
shift 2
for s in "$@"; do
  (
    if [[ " $ARMS " == *" rec "* ]]; then "$H/run.sh" rec "$s" || echo "FAIL rec $s"; fi
    for a in $ARMS; do
      [ "$a" = rec ] && continue
      "$H/run.sh" "$a" "$s" || echo "FAIL $a $s" &
    done
    wait
  ) &
done
wait
grep -h "^seed\|FAIL\|Exception\|Error" "$RUNS"/*.log | head -40
