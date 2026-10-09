#!/bin/bash
# run2d.sh <rec|rc|rd> <seed>: round 2d (lender ICR<1 refinancing exemption), DebtEngine tap on; writes runs/r2d/<arm>-<seed>.jsonl.
#   rec = R2C tree (working tree with the lender clause removed from hasPrimaryMarketAccess; R2CTREE), live macro, records the path
#   rc  = R2C tree replaying rec's macro path        rd = working tree replaying the same path
# Live working-tree runs (after-*) were written by: OUTDIR=$H/runs/r2d ./run2c.sh after <seed>
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/bank_credit
PROJ=/home/quebeco/Projects/Code/Private/aerie-trading
R2CTREE=${R2CTREE:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/10634ac4-8f03-4975-8240-1240afce8f77/scratchpad/wt_r2c}
MACRO=${MACRODIR:-/tmp/claude-1000/-home-quebeco-Projects-Code-Private-aerie-trading/10634ac4-8f03-4975-8240-1240afce8f77/scratchpad/r2d_macro}
mkdir -p $MACRO
REC=""; REP=""; BASEV=""
case "$1" in
  rec) BASEV=$R2CTREE; PROJ=$R2CTREE; REC=$MACRO/path-$2.gz ;;
  rc)  BASEV=$R2CTREE; PROJ=$R2CTREE; REP=$MACRO/path-$2.gz ;;
  rd)  REP=$MACRO/path-$2.gz ;;
  *) echo "arm?"; exit 1 ;;
esac
O=$H/runs/r2d/$1-$2
rm -f $O.jsonl
cd $PROJ && env BASE=$BASEV SEED=$2 YEARS=${YEARS:-20} TPY=${TPY:-360} OUT=$O.jsonl MACRO_RECORD=$REC MACRO_REPLAY=$REP php -d memory_limit=3G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/BankCreditHarnessTest.php > $O.log 2>&1
