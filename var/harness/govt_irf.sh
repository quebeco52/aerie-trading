#!/bin/bash
# govt_irf.sh <label> [ovr dir] [seeds per process] [processes]: GovtImpulseProbeTest across processes in parallel, into govt_irf/<label>.
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness
L=$1; OVRDIR=${2:-}; PER=${3:-2}; P=${4:-16}
mkdir -p $H/govt_irf/$L
cd /home/quebeco/Projects/Code/Private/aerie-trading
for i in $(seq 0 $((P - 1))); do
  S=$(seq -s, $((i * PER + 1)) $((i * PER + PER)))
  OVRENV=""; [ -n "$OVRDIR" ] && OVRENV="OVR=$OVRDIR"
  env $OVRENV SEEDS=$S TPY=${TPY:-180} SHOCK=${SHOCK:-0.05} HORIZON=${HORIZON:-5} OUT=$H/govt_irf/$L/p$i.json \
    php -d memory_limit=2G vendor/bin/phpunit --bootstrap $H/bootstrap.php --no-configuration $H/GovtImpulseProbeTest.php > $H/govt_irf/$L/p$i.log 2>&1 &
done
wait
python3 $H/govt_irf_an.py $H/govt_irf/$L/p*.json
