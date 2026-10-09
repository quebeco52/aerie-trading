#!/bin/bash
# run2.sh <arm> <seed>: rec = working tree recording macro2/path-<seed>; div = working tree; single = CNDR pure-play copy
H=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/7a9b11ee-1ffa-4ce6-980f-4bf06c425d20/scratchpad/harness
case "$1" in
  rec)    PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV=""; MAC="MACRO_RECORD=$H/macro2/path-$2.gz" ;;
  rec3)   PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV=""; MAC="MACRO_RECORD=$H/macro3/path-$2.gz" ;;
  rec4)   PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV=""; MAC="MACRO_RECORD=$H/macro4/path-$2.gz" ;;
  rec5)   PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV=""; MAC="MACRO_RECORD=$H/macro5/path-$2.gz" ;;
  rec6)   PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV=""; MAC="MACRO_RECORD=$H/macro6/path-$2.gz" ;;
  rec7)   PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV=""; MAC="MACRO_RECORD=$H/macro7/path-$2.gz" ;;
  rec8)   PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV=""; MAC="MACRO_RECORD=$H/macro8/path-$2.gz" ;;
  pre)    PROJ=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/7a9b11ee-1ffa-4ce6-980f-4bf06c425d20/scratchpad/wt_pretfp; BASEV=$PROJ; MAC="" ;;
  tfp)    PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV=""; MAC="" ;;
  lab0)   PROJ=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/7a9b11ee-1ffa-4ce6-980f-4bf06c425d20/scratchpad/wt_lab; BASEV=$PROJ; MAC="MACRO_REPLAY=$H/macro7/path-$2.gz" ;;
  io)     PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV=""; MAC="MACRO_REPLAY=$H/macro6/path-$2.gz" ;;
  div)    PROJ=/home/quebeco/Projects/Code/Private/aerie-trading; BASEV=""; MAC="MACRO_REPLAY=$H/macro2/path-$2.gz" ;;
  single) PROJ=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/7a9b11ee-1ffa-4ce6-980f-4bf06c425d20/scratchpad/wt_single; BASEV=/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/7a9b11ee-1ffa-4ce6-980f-4bf06c425d20/scratchpad/wt_single; MAC="MACRO_REPLAY=$H/macro2/path-$2.gz" ;;
esac
cd $PROJ && env $MAC BASE=$BASEV SEED=$2 YEARS=${YEARS:-20} TPY=360 OUT=$H/runs2/$1-$2.jsonl php -d memory_limit=3G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/FullMarketHarnessTest.php > $H/runs2/$1-$2.log 2>&1
