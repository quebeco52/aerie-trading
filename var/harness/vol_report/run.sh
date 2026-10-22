#!/bin/bash
# run.sh base|treat <seed>: live whole-ticker run (POLITICS=1) of vol_report/FullMarketHarnessTest.php writing VR_OUT.
#   treat  the main working tree (BASE=main, so its cache is var/harness/cache-aerie-trading)
#   base   the HEAD snapshot in BASE_TREE (git archive HEAD + vendor symlink)
# Env: YEARS (4), TPY (360). Output: var/harness/vol_report/runs/<arm>-<seed>.json
M=/home/quebeco/Projects/Code/Private/aerie-trading
H=$M/var/harness/vol_report
OUTD=$H/runs
mkdir -p "$OUTD"; rm -f "$OUTD/$1-$2.jsonl"
case "$1" in
  treat) T=$M ;;
  base)  T=${BASE_TREE:?set BASE_TREE=<git archive tree>} ;;
  *) echo "usage: run.sh base|treat <seed>" >&2; exit 2 ;;
esac
cd "$T" && env BASE="$T" POLITICS=1 SEED="$2" YEARS="${YEARS:-4}" TPY="${TPY:-360}" \
  VR_OUT="$OUTD/$1-$2.json" OUT="$OUTD/$1-$2.jsonl" \
  "$M/bin/php-slot" php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/FullMarketHarnessTest.php"
