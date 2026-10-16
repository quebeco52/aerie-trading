#!/bin/bash
# run_step1.sh <arm> <seed>: macro-review step 1. s1new|s1newfund run this worktree; s1base|s1basefund run a
# clean export of HEAD at BASE (default /tmp/claude-1000/base, vendor copied, macro_moments.php copied in).
# YEARS default 100. Output: runs/<arm>_<seed>.jsonl.
cd /home/quebeco/Projects/Code/Private/aerie-trading/.claude/worktrees/macro-review-step1 || exit 1
arm=$1; seed=$2; out=var/harness/rework/runs; mkdir -p $out
base=${BASE:-/tmp/claude-1000/base}
case $arm in
  s1new)      exec bin/php-slot php -d memory_limit=2G var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 0 ;;
  s1newfund)  exec bin/php-slot php -d memory_limit=2G var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 1 ;;
  s1base)     exec bin/php-slot php -d memory_limit=2G $base/var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 0 ;;
  s1basefund) exec bin/php-slot php -d memory_limit=2G $base/var/harness/rework/macro_moments.php $seed ${YEARS:-100} $out/${arm}_$seed.jsonl 1 ;;
  *) echo "unknown arm $arm"; exit 2 ;;
esac
