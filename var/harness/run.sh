#!/bin/bash
# run.sh <arm> <seed>: whole-ticker replay of FullMarketHarnessTest.php.
#   rec       working tree, records the macro path for <seed>
#   new       working tree, replays the recorded macro path
#   base      BASE=<tree> (a `git archive` tree with a vendor symlink), replays the same path
# Env: YEARS (20), TPY (360), RUNS (output dir, default var/harness/full). Outputs <RUNS>/runs/<arm>-<seed>.{jsonl,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../.." && pwd)
RUNS=${RUNS:-$H/full}
mkdir -p "$RUNS/runs" "$RUNS/macro"
PATH_GZ=$RUNS/macro/path-$2.gz
case "$1" in
  rec)  MAC="MACRO_RECORD=$PATH_GZ"; BASEV="" ;;
  new)  MAC="MACRO_REPLAY=$PATH_GZ"; BASEV="" ;;
  base) MAC="MACRO_REPLAY=$PATH_GZ"; BASEV=${BASE:?set BASE=<tree>} ;;
  *) echo "usage: run.sh rec|new|base <seed>" >&2; exit 2 ;;
esac
cd "$PROJ" && env "$MAC" BASE="$BASEV" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" OUT="$RUNS/runs/$1-$2.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$H/bootstrap.php" --no-configuration \
  "$H/FullMarketHarnessTest.php" > "$RUNS/runs/$1-$2.log" 2>&1
