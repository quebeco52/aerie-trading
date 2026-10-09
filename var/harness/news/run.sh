#!/bin/bash
# run.sh <seed> [<seed> ...]: one news census per seed on the snapshot tree in $BASE (default: scratchpad newstree).
# Env: ARM (before|after: output dir runs_<arm>) YEARS (10) TPY (3600). Rows go to runs_<arm>/news-<seed>.jsonl.
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/news
BASE=${BASE:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/96d17b4b-c422-421a-9307-fa7be80e521e/scratchpad/newstree}
R=$H/runs_${ARM:-before}; mkdir -p $R
for s in "$@"; do
  cd /home/quebeco/Projects/Code/Private/aerie-trading && BASE=$BASE SEED=$s POLITICS_SEED=$s YEARS=${YEARS:-10} TPY=${TPY:-3600} OUT=$R/news-$s.jsonl \
    php -d memory_limit=3G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/NewsCountHarnessTest.php > $R/news-$s.log 2>&1 &
done
wait
