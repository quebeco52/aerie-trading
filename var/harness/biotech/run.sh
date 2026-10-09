#!/bin/bash
# run.sh <seed> [years]: one whole-ticker replay of the current tree at 360 ticks/year, IBIS rows to runs/s<seed>.jsonl
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/biotech
mkdir -p $H/runs
cd /home/quebeco/Projects/Code/Private/aerie-trading && rm -f $H/runs/s$1.jsonl && env OVR=$H/ovr SEED=$1 YEARS=${2:-30} TPY=360 OUT=$H/runs/s$1.jsonl \
  php -d memory_limit=3G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/BiotechHarnessTest.php > $H/runs/s$1.log 2>&1
