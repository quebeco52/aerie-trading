#!/bin/bash
# gapsplit_run.sh <arm> [ovr dir]: loop moments (CreditMacroProbe 36x120y + RatePath +/-), a dump-shaped scan (32x80y) and
# TFP impulse pairs (48 seeds) for one arm. Output in $D/<arm>-*.
set -e
P=/home/quebeco/Projects/Code/Private/aerie-trading
D=${GAPSPLIT_DIR:-$TMPDIR/gapsplit}; ARM=$1; O=${2:-}; mkdir -p "$D"
cd "$P"
PHPUNIT="php -d memory_limit=2G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration"
E=""; [ -n "$O" ] && E="OVR=$O"
rm -f "$D/$ARM"-*.json "$D/${ARM}path"-*.json "$D/${ARM}pathn"-*.json "$D/${ARM}tfp"-*.json "$D/${ARM}diag"-*.ndjson
jobs=()
for ((k = 0; k < 12; k++)); do
  seeds="$((k * 3 + 1)),$((k * 3 + 2)),$((k * 3 + 3))"
  jobs+=("env $E SEEDS=$seeds YEARS=120 TPY=360 OUT=$D/$ARM-$k.json $PHPUNIT var/harness/CreditMacroProbeTest.php")
  jobs+=("env $E SEEDS=$seeds TPY=360 SCALE=0.25 OUT=$D/${ARM}path-$k.json $PHPUNIT var/harness/RatePathProbeTest.php")
  jobs+=("env $E SEEDS=$seeds TPY=360 SCALE=-0.25 OUT=$D/${ARM}pathn-$k.json $PHPUNIT var/harness/RatePathProbeTest.php")
done
for ((k = 0; k < 16; k++)); do
  jobs+=("env $E SEEDS=$((100 + k * 2 + 1)),$((100 + k * 2 + 2)) YEARS=80 TPY=360 OUT=$D/${ARM}diag-$k.ndjson $PHPUNIT var/harness/DiagnosticsRunTest.php")
  jobs+=("env $E SEEDS=$((200 + k * 3 + 1)),$((200 + k * 3 + 2)),$((200 + k * 3 + 3)) BURN=20 HORIZON=6 TPY=360 SHOCK=0.01 OUT=$D/${ARM}tfp-$k.json $PHPUNIT var/harness/TfpImpulseProbeTest.php")
done
printf '%s > /dev/null 2>&1\n' "${jobs[@]}" | xargs -P 16 -I{} sh -c '{}'
echo "$ARM done"
