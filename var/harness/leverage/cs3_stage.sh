#!/bin/bash
# cs3_stage.sh <seeds> <N>: run C10 and C8 on the cs3 snapshot, then write cs3_s<N>.out (spread, leverage, failures, types).
set -u
cd "$(dirname "$0")/../../.."
SNAP=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/fa6eb337-3fae-4393-a0c0-312404e10f42/scratchpad/cs3 \
  TREE=cs3tree bin/harness-batch var/harness/leverage/run.sh "$1" C10 C8
st=$?
O=var/harness/leverage/cs3_s$2.out
{
  echo "### analyse_spread.py C10 C8"; python3 var/harness/leverage/analyse_spread.py C10 C8
  echo; echo "### analyse.py C10 C8 9 19"; python3 var/harness/leverage/analyse.py C10 C8 9 19
  echo; echo "### failures.py C10 C8"; python3 var/harness/leverage/failures.py C10 C8
  echo; echo "### analyse_types.py C10 C8"; python3 var/harness/leverage/analyse_types.py C10 C8
} > "$O" 2>&1
echo "batch status $st; wrote $O ($(wc -l < "$O") lines)"
exit $st
