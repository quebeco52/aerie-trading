#!/bin/bash
# run_c.sh <arm> <seed>: fix C question (Winter 1994 capacity-constrained insurer supply), two frozen snapshots.
#   before  scratchpad ds-after (HEAD 28a82bc + debt-supply change)
#   after   scratchpad c-after  (the same + fix C)
#   c2      scratchpad c2-after (fix C + insurer capital target)
# Env: YEARS (30), TPY (360), SNAPS (snapshot parent dir). Output: insurer_capital/fixc/runs/<arm>-<seed>.jsonl
set -eu
D=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/insurer_capital
H=$D/fixc
MAIN=/home/quebeco/Projects/Code/Private/aerie-trading
S=${SNAPS:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/1cdddd51-306a-47f5-9f31-ba3ea29d83f9/scratchpad}
mkdir -p $H/runs
case "$1" in
  before) BASEV=$S/ds-after ;;
  after)  BASEV=$S/c-after ;;
  c2)     BASEV=$S/c2-after ;;
  *) echo "usage: run_c.sh before|after|c2 <seed>" >&2; exit 2 ;;
esac
rm -f $H/runs/$1-$2.jsonl
cd $H && env BASE="$BASEV" SEED="$2" YEARS="${YEARS:-30}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" \
  $MAIN/bin/php-slot php -d memory_limit=3G $BASEV/vendor/bin/phpunit --do-not-cache-result \
  --bootstrap $D/bootstrap.php --no-configuration $D/FullMarketHarnessTest.php
