#!/bin/bash
# run.sh <label> <seed> [REQ]: label rec* records the macro path; any other label replays runs/path-<seed>.gz
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/regulator/probe
case "$1" in
  rec*) MAC="MACRO_RECORD=$H/runs/path-$2.gz" ;;
  *)    MAC="MACRO_REPLAY=$H/runs/path-$2.gz" ;;
esac
cd /home/quebeco/Projects/Code/Private/aerie-trading && env $MAC REQ=$3 SEED=$2 YEARS=${YEARS:-20} TPY=360 OUT=$H/runs/$1-$2.jsonl php -d memory_limit=3G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/FullMarketHarnessTest.php > $H/runs/$1-$2.log 2>&1
