#!/bin/bash
# loopfit.sh <tag> <TAYLOR_INFLATION_WEIGHT> <TAYLOR_OUTPUT_GAP_WEIGHT> [seeds=36]: one point of the policy-loop fit.
# The two Taylor weights go into an OVR copy of MonetaryPolicySubsystem, so points can run side by side.
# CreditMacroProbeTest (120y, 360 tpy) feeds the ACF, sd, rule and timing moments; RatePathProbeTest (+/- the US
# post-surprise path) feeds J_path. Results land in $LOOPFIT_DIR/<tag>.an; rank them with loopfit_rank.py.
set -e
P=/home/quebeco/Projects/Code/Private/aerie-trading
TAG=$1; PI=$2; GAP=$3; N=${4:-36}
D=${LOOPFIT_DIR:-$TMPDIR/loopfit}
O=$D/ovr-$TAG
mkdir -p "$D" "$O/Service/Macro/Subsystem"
sed -e "s/public const TAYLOR_INFLATION_WEIGHT = [0-9.]*;/public const TAYLOR_INFLATION_WEIGHT = $PI;/" \
    -e "s/public const TAYLOR_OUTPUT_GAP_WEIGHT = [0-9.]*;/public const TAYLOR_OUTPUT_GAP_WEIGHT = $GAP;/" \
    "$P/src/Service/Macro/Subsystem/MonetaryPolicySubsystem.php" > "$O/Service/Macro/Subsystem/MonetaryPolicySubsystem.php"
grep -q "TAYLOR_INFLATION_WEIGHT = $PI;" "$O/Service/Macro/Subsystem/MonetaryPolicySubsystem.php"
grep -q "TAYLOR_OUTPUT_GAP_WEIGHT = $GAP;" "$O/Service/Macro/Subsystem/MonetaryPolicySubsystem.php"
rm -f "$D/$TAG"-*.json "$D/${TAG}path"-*.json "$D/${TAG}pathn"-*.json
cd "$P"
PHPUNIT="php -d memory_limit=2G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration"
jobs=()
for ((k = 0; k < N / 3; k++)); do
  seeds="$((k * 3 + 1)),$((k * 3 + 2)),$((k * 3 + 3))"
  jobs+=("env OVR=$O SEEDS=$seeds YEARS=120 TPY=360 OUT=$D/$TAG-$k.json $PHPUNIT var/harness/CreditMacroProbeTest.php")
  jobs+=("env OVR=$O SEEDS=$seeds TPY=360 SCALE=0.25 OUT=$D/${TAG}path-$k.json $PHPUNIT var/harness/RatePathProbeTest.php")
  jobs+=("env OVR=$O SEEDS=$seeds TPY=360 SCALE=-0.25 OUT=$D/${TAG}pathn-$k.json $PHPUNIT var/harness/RatePathProbeTest.php")
done
printf '%s > /dev/null 2>&1\n' "${jobs[@]}" | xargs -P "${LOOPFIT_JOBS:-16}" -I{} sh -c '{}'
{
  echo "point $TAG inflation $PI gap $GAP"
  python3 var/harness/policy_engine_an.py "$D" "$TAG" none "${TAG}path"
  python3 var/harness/timing_an.py "$D" "$TAG"
} > "$D/$TAG.an" 2>&1
cat "$D/$TAG.an"
