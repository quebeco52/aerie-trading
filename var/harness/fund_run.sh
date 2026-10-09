#!/bin/bash
# fund_run.sh <arm> <seed> [years]: one whole-board run of the fund harness into $RUNS (default var/harness/fund_runs), at $TPY (default 360).
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness
ARM=$1; SEED=$2; YEARS=${3:-40}; TPY=${TPY:-360}; RUNS=${RUNS:-$H/fund_runs}
mkdir -p $RUNS
OVRENV=""
if [ "$ARM" != "fund" ]; then OVRENV="OVR=$H/ovr/$ARM"; fi
cd /home/quebeco/Projects/Code/Private/aerie-trading && env $OVRENV ARM=$ARM SEED=$SEED YEARS=$YEARS TPY=$TPY OUT=$RUNS/$ARM-$SEED.jsonl \
  php -d memory_limit=3G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/FundHarnessTest.php > $RUNS/$ARM-$SEED.log 2>&1
