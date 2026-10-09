#!/bin/bash
# shape.sh <arm> <seed> [years]: macro-only 120y probe; base = pre-TFP snapshot, anything else = working tree
H=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/7a9b11ee-1ffa-4ce6-980f-4bf06c425d20/scratchpad/harness
case "$1" in
  base) PROJ=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/7a9b11ee-1ffa-4ce6-980f-4bf06c425d20/scratchpad/wt_pretfp; BASEV=$PROJ ;;
  *)    PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV="" ;;
esac
cd $PROJ && env BASE=$BASEV SEEDS=$2 YEARS=${3:-120} TPY=360 OUT=$H/shape/$1-$2.json php -d memory_limit=2G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/LaborMacroProbeTest.php > $H/shape/$1-$2.log 2>&1
