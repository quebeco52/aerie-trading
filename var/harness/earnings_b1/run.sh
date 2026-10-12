#!/bin/bash
# run.sh <arm> <seed>: whole-ticker run (live macro, no politics) of FullMarketHarnessTest.php (fork with
# Altman zone, consensus/reported EPS and guidance/emergency event capture).
#   before  main checkout src (HEAD)
#   other   snapshot tree via the BASE autoloader, one kernel cache dir per snapshot (cache-<snapshot>)
# Snapshot trees (cp -a of a checkout with vendor) default to $SNAP/eef-<arm>; override per arm with the env vars below.
# Env: YEARS (15), TPY (360), H (output dir, default this directory). Output: $H/runs/<arm>-<seed>.jsonl
set -eu
D=$(cd "$(dirname "$0")" && pwd)
H=${H:-$D}
MAIN=/home/quebeco/Projects/Code/Private/aerie-trading
SNAP=${SNAP:-/tmp/claude-1000}
mkdir -p $H/runs
case "$1" in
  before) BASEV="" ;;
  after)  BASEV=${AFTER:-$SNAP/eef-b1} ;;
  b2)     BASEV=${B2:-$SNAP/eef-b2} ;;
  b3)     BASEV=${B3:-$SNAP/eef-b3} ;;
  b3b)    BASEV=${B3B:-$SNAP/eef-b3b} ;;
  *) echo "usage: run.sh before|after|b2|b3|b3b <seed>" >&2; exit 2 ;;
esac
rm -f $H/runs/$1-$2.jsonl
cd $H && env BASE="$BASEV" SEED="$2" YEARS="${YEARS:-15}" TPY="${TPY:-360}" OUT="$H/runs/$1-$2.jsonl" \
  $MAIN/bin/php-slot php -d memory_limit=3G $MAIN/vendor/bin/phpunit --do-not-cache-result \
  --bootstrap $D/bootstrap.php --no-configuration $D/FullMarketHarnessTest.php
