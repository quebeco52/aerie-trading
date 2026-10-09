#!/bin/bash
# run.sh <rec|after|before> <seed>
#   rec    = working tree, live macro, records the macro path to macro/path-<seed>.gz
#   after  = working tree (systematic charge-offs) replaying that path
#   before = git HEAD tree (BASE=$HEADTREE, vendor symlinked) replaying the same path
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/bank_credit
PROJ=/home/quebeco/Projects/Code/Private/aerie-trading
HEADTREE=${HEADTREE:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/10634ac4-8f03-4975-8240-1240afce8f77/scratchpad/wt_head}
case "$1" in
  rec)    BASEV=""; MAC="MACRO_RECORD=$H/macro/path-$2.gz" ;;
  after)  BASEV=""; MAC="MACRO_REPLAY=$H/macro/path-$2.gz" ;;
  before) BASEV=$HEADTREE; PROJ=$HEADTREE; MAC="MACRO_REPLAY=$H/macro/path-$2.gz" ;;
esac
rm -f $H/runs/$1-$2.jsonl
cd $PROJ && env $MAC BASE=$BASEV SEED=$2 YEARS=${YEARS:-20} TPY=${TPY:-360} OUT=$H/runs/$1-$2.jsonl php -d memory_limit=3G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/BankCreditHarnessTest.php > $H/runs/$1-$2.log 2>&1
