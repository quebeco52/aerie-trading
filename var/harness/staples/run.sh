#!/bin/bash
# run.sh <arm> <seed>: staples rework check on StaplesHarnessTest.php (a fork of FullMarketHarnessTest.php).
#   rec   baseline tree, live macro, records the macro path for <seed>
#   base  baseline tree (BASEDIR), replays the recorded path
#   new   treatment tree (the worktree), replays the recorded path
# Env: YEARS (20), TPY (360), BASEDIR (baseline tree, default /tmp/claude-1000/staples-base).
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
BASEDIR=${BASEDIR:-/tmp/claude-1000/staples-base}
RUNS=$H/runs
mkdir -p "$RUNS" "$H/macro"
PATH_GZ=$H/macro/path-$2.gz
case "$1" in
  rec)  MAC="MACRO_RECORD=$PATH_GZ"; TREE=$BASEDIR ;;
  base) MAC="MACRO_REPLAY=$PATH_GZ"; TREE=$BASEDIR ;;
  new)  MAC="MACRO_REPLAY=$PATH_GZ"; TREE=$PROJ ;;
  *) echo "usage: run.sh rec|base|new <seed>" >&2; exit 2 ;;
esac
rm -f "$RUNS/$1-$2.jsonl"
cd "$PROJ" && env "$MAC" BASE="$TREE" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" OUT="$RUNS/$1-$2.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/StaplesHarnessTest.php" > "$RUNS/$1-$2.log" 2>&1
