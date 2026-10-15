#!/bin/bash
# run.sh <arm> <seed>: arm new|newfund runs the working tree; old|oldfund runs var/harness/rework/old_tree (git worktree
# at the pre-rework commit, vendor copied with cp -a). YEARS default 100. Output: runs/<arm>_<seed>.jsonl.
cd /home/quebeco/Projects/Code/Private/aerie-trading || exit 1
arm=$1; seed=$2; out=var/harness/rework/runs; mkdir -p $out
case $arm in
  new)     exec bin/php-slot php -d memory_limit=2G var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 0 ;;
  newfund) exec bin/php-slot php -d memory_limit=2G var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 1 ;;
  old)     exec bin/php-slot php -d memory_limit=2G var/harness/rework/old_tree/var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 0 ;;
  oldfund) exec bin/php-slot php -d memory_limit=2G var/harness/rework/old_tree/var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 1 ;;
  *) echo "unknown arm $arm"; exit 2 ;;
esac
