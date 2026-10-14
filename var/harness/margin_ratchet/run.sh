#!/bin/bash
# run.sh <arm> <seed>: whole-ticker run (live macro, no politics) of the margin_ratchet FullMarketHarnessTest fork
# (earnings_b1 fork + per-quarter stored Stock::operatingMargin 'som', final om_final / ind / isfin).
#   before  HEAD snapshot (git archive HEAD + .env* + cp -a vendor) via the BASE autoloader, cache-<snapshot>/
#   after   main checkout working tree (plant-modernization margin ratchet removed)
# Env: YEARS (30), TPY (360), BEFORE (snapshot dir), H (output dir). Output: $H/runs/<arm>-<seed>.jsonl
set -eu
D=$(cd "$(dirname "$0")" && pwd)
H=${H:-$D}
MAIN=/home/quebeco/Projects/Code/Private/aerie-trading
mkdir -p $H/runs
case "$1" in
  after|jdaw|jdaw2)  BASEV="" ;;  # jdaw = 10-14 WEAV refit + JDAW tree; jdaw2 = JDAW asset-light retune (output kept apart from the cached after arm)
  before) BASEV=${BEFORE:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/3aa06804-00db-4439-bb82-43aadf25c7e1/scratchpad/sam-before} ;;
  ficc) BASEV=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/3aa06804-00db-4439-bb82-43aadf25c7e1/scratchpad/ficc-after ;;  # rates-volatility FICC desk (HEAD + its files)
  pen|led|ma|plc) BASEV=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/3aa06804-00db-4439-bb82-43aadf25c7e1/scratchpad/sam-$1 ;;  # call-site bisect of the sector-drift SAM
  *) echo "usage: run.sh before|after|ficc|pen|led|ma|plc <seed>" >&2; exit 2 ;;
esac
rm -f $H/runs/$1-$2.jsonl
cd $H && env BASE="$BASEV" SEED="$2" YEARS="${YEARS:-30}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" \
  $MAIN/bin/php-slot php -d memory_limit=3G $MAIN/vendor/bin/phpunit --do-not-cache-result \
  --bootstrap $D/bootstrap.php --no-configuration $D/FullMarketHarnessTest.php
