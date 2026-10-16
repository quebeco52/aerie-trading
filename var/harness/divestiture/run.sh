#!/bin/bash
# run.sh live <seed>: live-macro whole-ticker run of DivestitureHarnessTest.php (no replay, POLITICS off).
# Env: YEARS (20), TPY (360). Output: var/harness/new_firms/runs/live-<seed>.{jsonl,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
# An arm named np* keeps the production engine in the tracker (probe check).
case "$1" in np*) export NOPROBE=1;; esac
rm -f "$H/runs/$1-$2.jsonl"
cd "$PROJ" && env BASE="$PROJ" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" OPENING_OUT="$H/runs/$1-$2.open.json" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap "$PROJ/var/harness/bootstrap.php" --no-configuration \
  "$H/DivestitureHarnessTest.php" > "$H/runs/$1-$2.log" 2>&1
