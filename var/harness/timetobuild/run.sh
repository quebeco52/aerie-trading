#!/bin/bash
# run.sh <arm> <seed>: whole board, live macro (own stream, pairs across arms), TimeToBuildHarnessTest.
#   ttb   working tree
#   base  BASE tree (git archive of HEAD + copied vendor), default $TREES/ttbbase-<sha>
# Env: YEARS (20), TPY (360), TAG (output suffix). Outputs runs/<arm>-<seed><TAG>.{jsonl,macro.jsonl,log}.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
mkdir -p "$H/runs"
TAG=${TAG:-}
case "$1" in
  ttb)  BASEV="" ;;
  base) BASEV=${BASE:?set BASE=<git archive tree with vendor>} ;;
  *) echo "usage: run.sh ttb|base <seed>" >&2; exit 2 ;;
esac
R=$H/runs/$1-$2$TAG
rm -f "$R.jsonl" "$R.macro.jsonl"
cd "$PROJ" && env BASE="$BASEV" SEED="$2" YEARS="${YEARS:-20}" TPY="${TPY:-360}" OUT="$R.jsonl" MACRO_OUT="$R.macro.jsonl" \
  bin/php-slot php -d memory_limit=3G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration \
  "$H/TimeToBuildHarnessTest.php" > "$R.log" 2>&1
