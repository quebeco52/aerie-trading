#!/bin/bash
# run.sh <arm> <seed>: rec records macro path <seed> on the working tree; pol replays it as the cabinets set review;
# strict replays it at leniency 0 (2023 Guidelines); loose at leniency 1 (2010 Guidelines).
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness
M=$H/merger
case "$1" in
  rec)    MAC="MACRO_RECORD=$M/macro/path-$2.gz" ;;
  pol)    MAC="MACRO_REPLAY=$M/macro/path-$2.gz" ;;
  strict) MAC="MACRO_REPLAY=$M/macro/path-$2.gz LENIENCY=0" ;;
  loose)  MAC="MACRO_REPLAY=$M/macro/path-$2.gz LENIENCY=1" ;;
esac
cd /home/quebeco/Projects/Code/Private/aerie-trading && env $MAC SEED=$2 YEARS=${YEARS:-20} TPY=360 OUT=$M/runs/$1-$2.jsonl php -d memory_limit=3G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $M/MergerHarnessTest.php > $M/runs/$1-$2.log 2>&1
