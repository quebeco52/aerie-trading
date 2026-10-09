#!/bin/bash
# run2c.sh <after|before> <seed>: round 2c (retail intercept) with the DebtEngine tap; writes runs/r2c/<arm>-<seed>.jsonl.
#   after  = working tree, live macro (its own path)
#   before = HEAD c2f29f4 tree (HEADTREE, git archive + vendor symlink + working .env), live macro (its own path)
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/bank_credit
PROJ=/home/quebeco/Projects/Code/Private/aerie-trading
HEADTREE=${HEADTREE:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/10634ac4-8f03-4975-8240-1240afce8f77/scratchpad/wt_c2f29f4}
case "$1" in
  after)  BASEV="" ;;
  before) BASEV=$HEADTREE; PROJ=$HEADTREE ;;
  *) echo "arm?"; exit 1 ;;
esac
O=${OUTDIR:-$H/runs/r2c}/$1-$2
rm -f $O.jsonl
cd $PROJ && env BASE=$BASEV SEED=$2 YEARS=${YEARS:-20} TPY=${TPY:-360} OUT=$O.jsonl php -d memory_limit=3G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/BankCreditHarnessTest.php > $O.log 2>&1
