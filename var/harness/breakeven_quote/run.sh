#!/bin/bash
# run.sh <arm> <seed>: arm cur runs breakeven_quote.php on the working tree (YEARS default 40, TPY default 48).
cd /home/quebeco/Projects/Code/Private/aerie-trading || exit 1
arm=$1; seed=$2; out=var/harness/breakeven_quote/runs
mkdir -p $out
case $arm in
  cur) exec bin/php-slot php -d memory_limit=3G var/harness/breakeven_quote/breakeven_quote.php $seed ${YEARS:-40} ${TPY:-48} $out/${arm}_$seed.jsonl ;;
  *) echo "unknown arm $arm"; exit 2 ;;
esac
