#!/bin/bash
# run.sh <arm> <seed>: first-quarter board cap of the working tree. 'without' seeds the board from ovr_noplvr (no PLVR);
# any other arm name runs the tree as is (set OVR=<dir> to override seed data). Output runs/<arm>-<seed>.json + .log.
D=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/opening_cap
OV=${OVR:+OVR=$OVR}; [ "$1" = without ] && OV="OVR=$D/ovr_noplvr"
cd /home/quebeco/Projects/Code/Private/aerie-trading && env $OV SEED=$2 TPY=${TPY:-360} OUT=$D/runs/$1-$2.json bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap $D/bootstrap.php --no-configuration $D/OpeningCapTest.php > $D/runs/$1-$2.log 2>&1
