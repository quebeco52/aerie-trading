#!/bin/bash
# run.sh <arm> <seed>: CreditMacroProbeTest (macro only) for one seed on the working tree into runs/<arm>-<seed>.json
# (+ .crises). Env: YEARS (120), TPY (180). Arm "base" only (working tree).
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness
D=$H/fc_crisis_drag/runs
mkdir -p $D
cd /home/quebeco/Projects/Code/Private/aerie-trading || exit 1
SEEDS=$2 YEARS=${YEARS:-120} TPY=${TPY:-180} OUT=$D/$1-$2.json \
  bin/php-slot php -d memory_limit=2G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/CreditMacroProbeTest.php
