#!/bin/bash
# run.sh <arm> <seed> [years]: opening + realised betas; writes runs/<arm>-<seed>.json
D=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/betas
cd /home/quebeco/Projects/Code/Private/aerie-trading && env SEED=$2 YEARS=${3:-5} TPY=${TPY:-360} OUT=$D/runs/$1-$2.json \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration $D/BetaHarnessTest.php > $D/runs/$1-$2.log 2>&1
