#!/bin/bash
# fc_run.sh <arm> [seeds=36] [years=120]: CreditMacroProbeTest (macro only) for one arm into fc_runs/<arm>-k.json; arm base = working tree.
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness
ARM=$1; N=${2:-36}; Y=${3:-120}; D=${FC_DIR:-$H/fc_runs}
mkdir -p $D; rm -f $D/$ARM-*.json
OVRENV=""; [ "$ARM" != "base" ] && OVRENV="OVR=$H/ovr/$ARM"
cd /home/quebeco/Projects/Code/Private/aerie-trading
for ((k = 0; k < N / 2; k++)); do
  echo "env $OVRENV SEEDS=$((k * 2 + 1)),$((k * 2 + 2)) YEARS=$Y TPY=${TPY:-180} OUT=$D/$ARM-$k.json php -d memory_limit=2G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/CreditMacroProbeTest.php > /dev/null 2>&1"
done | xargs -P 16 -I{} sh -c '{}'
ls $D/$ARM-*.json | wc -l
