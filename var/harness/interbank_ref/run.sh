#!/bin/bash
# run.sh before|after <seed>: live whole-ticker run (POLITICS=1, HEALTH=1) of interbank_ref/FullMarketHarnessTest.php.
#   after   the main working tree (BASE=main, cache var/harness/cache-aerie-trading: delete it after a constructor change)
#   before  the HEAD worktree in BEFORE_TREE (default /tmp/claude-1000/ir-before, `git worktree add` + copied vendor)
# Env: YEARS (8), TPY (360). Output: var/harness/interbank_ref/runs/<arm>-<seed>.jsonl
M=/home/quebeco/Projects/Code/Private/aerie-trading
H=$M/var/harness/interbank_ref
OUTD=${RUNS:-$H/runs}
mkdir -p "$OUTD"; rm -f "$OUTD/$1-$2.jsonl"
case "$1" in
  after)  T=$M ;;
  before) T=${BEFORE_TREE:-/tmp/claude-1000/ir-before} ;;
  *) echo "usage: run.sh before|after <seed>" >&2; exit 2 ;;
esac
cd "$T" && env BASE="$T" POLITICS=1 HEALTH=1 SEED="$2" YEARS="${YEARS:-8}" TPY="${TPY:-360}" \
  OUT="$OUTD/$1-$2.jsonl" \
  "$M/bin/php-slot" php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/FullMarketHarnessTest.php"
