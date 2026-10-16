#!/bin/bash
# run_step2.sh <arm> <seed>: macro-review step 2. s2new|s2newfund run this worktree; s2base|s2basefund run a
# clean export of HEAD at BASE (default /tmp/claude-1000/base2, vendor copied, macro_moments.php copied in).
# YEARS default 100. Output: runs/<arm>_<seed>.jsonl.
cd /home/quebeco/Projects/Code/Private/aerie-trading/.claude/worktrees/macro-review-step2 || exit 1
arm=$1; seed=$2; out=var/harness/rework/runs; mkdir -p $out
base=${BASE:-/tmp/claude-1000/base2}
case $arm in
  s2new)      exec bin/php-slot php -d memory_limit=2G var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 0 ;;
  s2newfund)  exec bin/php-slot php -d memory_limit=2G var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 1 ;;
  s2base)     exec bin/php-slot php -d memory_limit=2G $base/var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 0 ;;
  s2basefund) exec bin/php-slot php -d memory_limit=2G $base/var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 1 ;;
  *) echo "unknown arm $arm"; exit 2 ;;
esac
