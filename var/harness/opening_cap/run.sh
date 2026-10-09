#!/bin/bash
# run.sh <with|without> <seed>: first-quarter board cap; 'without' seeds the board from ovr_noplvr (no PLVR).
D=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/opening_cap
OV=""; [ "$1" = without ] && OV="OVR=$D/ovr_noplvr"
cd /home/quebeco/Projects/Code/Private/aerie-trading && env $OV SEED=$2 TPY=${TPY:-360} OUT=$D/runs/$1-$2.json php -d memory_limit=3G vendor/bin/phpunit --bootstrap $D/bootstrap.php --no-configuration $D/OpeningCapTest.php > $D/runs/$1-$2.log 2>&1
