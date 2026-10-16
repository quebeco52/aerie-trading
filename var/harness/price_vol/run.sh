#!/bin/bash
# run.sh <arm> <seed>: price-channel probe on PriceVolHarnessTest.php (a fork of FullMarketHarnessTest.php).
#   live|fix  this worktree's tree as it stands (live = baseline main, fix = after the budget fix), live macro, probe on. Writes runs/live-<seed>.{csv,json,jsonl,log}.
# Env: YEARS (10), TPY (360), SKIP (warm-up ticks kept out of the budget audit, default TPY).
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
RUNS=$H/runs
mkdir -p "$RUNS"
case "$1" in
  live|fix) ;;
  *) echo "usage: run.sh live|fix <seed>" >&2; exit 2 ;;
esac
rm -f "$RUNS/$1-$2.jsonl"
cd "$PROJ" && env BASE="$PROJ" SEED="$2" YEARS="${YEARS:-10}" TPY="${TPY:-360}" OUT="$RUNS/$1-$2.jsonl" \
  PROBE_CSV="$RUNS/$1-$2.csv" PROBE_JSON="$RUNS/$1-$2.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/PriceVolHarnessTest.php" > "$RUNS/$1-$2.log" 2>&1
