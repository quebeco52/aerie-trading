#!/bin/bash
# run.sh <arm> <seed>: arm cur runs gap_floor.php on the working tree (YEARS default 100, TPY default 48).
cd /home/quebeco/Projects/Code/Private/aerie-trading || exit 1
arm=$1; seed=$2; out=var/harness/gap_floor/runs
mkdir -p $out
case $arm in
  cur) exec bin/php-slot php -d memory_limit=3G var/harness/gap_floor/gap_floor.php $seed ${YEARS:-100} ${TPY:-48} $out/${arm}_$seed.jsonl ;;
  *) echo "unknown arm $arm"; exit 2 ;;
esac
