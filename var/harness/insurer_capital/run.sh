#!/bin/bash
# run.sh <arm> <seed>: whole-ticker run (live macro, no politics) of the insurer_capital FullMarketHarnessTest fork
# (earnings_b1 fork + per-quarter stored Stock::operatingMargin 'som', final om_final / ind / isfin).
#   before  HEAD snapshot (git archive HEAD + .env* + cp -a vendor) via the BASE autoloader, cache-<snapshot>/
#   after   main checkout working tree (insurer cash backing split, NAIC 440 ceiling, taxed divestiture gains)
# Env: YEARS (30), TPY (360), BEFORE (snapshot dir), H (output dir). Output: $H/runs/<arm>-<seed>.jsonl
set -eu
D=$(cd "$(dirname "$0")" && pwd)
H=${H:-$D}
MAIN=/home/quebeco/Projects/Code/Private/aerie-trading
mkdir -p $H/runs
case "$1" in
  after)  BASEV="" ;;
  before) BASEV=${BEFORE:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/1cdddd51-306a-47f5-9f31-ba3ea29d83f9/scratchpad/ic-before} ;;
  *) echo "usage: run.sh before|after <seed>" >&2; exit 2 ;;
esac
rm -f $H/runs/$1-$2.jsonl
cd $H && env BASE="$BASEV" SEED="$2" YEARS="${YEARS:-30}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" \
  $MAIN/bin/php-slot php -d memory_limit=3G $MAIN/vendor/bin/phpunit --do-not-cache-result \
  --bootstrap $D/bootstrap.php --no-configuration $D/FullMarketHarnessTest.php
