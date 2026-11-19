#!/bin/bash
# run.sh <arm> <seed>: analyst anchor roll (AnalystAnchorHarnessTest.php), live macro, no politics, working tree.
#   base  STATE_LAST_PUBLIC_REVENUE stripped each tick (old expected-revenue roll)
#   fix   working tree as is
# Env: YEARS (20), TPY (360). Outputs runs/<arm>-<seed>.{jsonl,macro.jsonl,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
case "$1" in
  base|fix) ;;
  *) echo "usage: run.sh base|fix <seed>" >&2; exit 2 ;;
esac
rm -f "$H/runs/$1-$2.jsonl" "$H/runs/$1-$2.macro.jsonl"
cd "$PROJ" && env ARM="$1" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" \
  OUT="$H/runs/$1-$2.jsonl" MACRO_OUT="$H/runs/$1-$2.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/AnalystAnchorHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
