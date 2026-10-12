#!/bin/bash
# run.sh after|before <seed>: live whole-ticker run (POLITICS=1, FISCAL=1) writing one macro row a quarter.
#   after   the main working tree, or the snapshot in AFTER_TREE (run with BASE=<tree>)
#   before  the HEAD snapshot in BEFORE_TREE (make_before_tree.sh), run on its own vendor/src with BASE=<tree>
# Both arms run THIS topic's harness fork (debt_anchor/FullMarketHarnessTest.php).
# Env: YEARS (100), TPY (360). Output: var/harness/debt_anchor/runs/<arm>-<seed>.macro.jsonl
M=/home/quebeco/Projects/Code/Private/aerie-trading
H=$M/var/harness/debt_anchor
OUTD=$H/runs
mkdir -p "$OUTD"
case "$1" in
  after)  T=${AFTER_TREE:-$M}; B=${AFTER_TREE:-} ;;
  before) T=${BEFORE_TREE:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/1cdddd51-306a-47f5-9f31-ba3ea29d83f9/scratchpad/da-before}; B=$T ;;
  *) echo "usage: run.sh after|before <seed>" >&2; exit 2 ;;
esac
rm -f "$OUTD/$1-$2.macro.jsonl"
cd "$T" && env BASE="$B" POLITICS=1 FISCAL=1 SEED="$2" YEARS="${YEARS:-100}" TPY="${TPY:-360}" \
  MACRO_OUT="$OUTD/$1-$2.macro.jsonl" \
  "$M/bin/php-slot" php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/FullMarketHarnessTest.php"
