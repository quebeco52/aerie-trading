#!/bin/bash
# loopfit_tree.sh <tree> <tag> [seeds=36]: var/harness/loopfit.sh at the current Taylor weights, run on <tree>
# (main checkout or a HEAD worktree with a copied vendor), every PHP process through bin/php-slot.
# A worktree lacks the untracked policy_fit.json / asym_fit.json: copy them into its var/harness/ first, or the
# rate-path probe reads a zero path (J_path then measures no response).
# Output: $LOOPFIT_DIR/<tag>-*.json and <tag>.an (policy_engine_an + timing_an, main's analysis scripts).
set -e
M=/home/quebeco/Projects/Code/Private/aerie-trading
P=$1; TAG=$2; N=${3:-36}
D=${LOOPFIT_DIR:?set LOOPFIT_DIR}
PI=$(grep -o 'public const TAYLOR_INFLATION_WEIGHT = [0-9.]*' "$P/src/Service/Macro/Subsystem/MonetaryPolicySubsystem.php" | grep -o '[0-9.]*$')
GAP=$(grep -o 'public const TAYLOR_OUTPUT_GAP_WEIGHT = [0-9.]*' "$P/src/Service/Macro/Subsystem/MonetaryPolicySubsystem.php" | grep -o '[0-9.]*$')
O=$D/ovr-$TAG
mkdir -p "$D" "$O/Service/Macro/Subsystem"
cp "$P/src/Service/Macro/Subsystem/MonetaryPolicySubsystem.php" "$O/Service/Macro/Subsystem/MonetaryPolicySubsystem.php"
# PATHONLY=1 reruns only the rate-path probes (keeps the 120y macro outputs).
[ -n "$PATHONLY" ] || rm -f "$D/$TAG"-*.json
rm -f "$D/${TAG}path"-*.json "$D/${TAG}pathn"-*.json
cd "$P"
[ "$P" = "$M" ] || export BASE=$P
PHPUNIT="$M/bin/php-slot php -d memory_limit=2G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration"
jobs=()
for ((k = 0; k < N / 3; k++)); do
  seeds="$((k * 3 + 1)),$((k * 3 + 2)),$((k * 3 + 3))"
  [ -n "$PATHONLY" ] || jobs+=("env OVR=$O SEEDS=$seeds YEARS=120 TPY=360 OUT=$D/$TAG-$k.json $PHPUNIT var/harness/CreditMacroProbeTest.php")
  jobs+=("env OVR=$O SEEDS=$seeds TPY=360 SCALE=0.25 OUT=$D/${TAG}path-$k.json $PHPUNIT var/harness/RatePathProbeTest.php")
  jobs+=("env OVR=$O SEEDS=$seeds TPY=360 SCALE=-0.25 OUT=$D/${TAG}pathn-$k.json $PHPUNIT var/harness/RatePathProbeTest.php")
done
printf '%s > /dev/null 2>&1\n' "${jobs[@]}" | xargs -P 8 -I{} sh -c '{}'
n=$(ls "$D/$TAG"-[0-9]*.json 2>/dev/null | wc -l)
[ "$n" -eq $((N / 3)) ] || { echo "$TAG: only $n/$((N / 3)) macro outputs"; exit 1; }
{
  echo "point $TAG inflation $PI gap $GAP"
  python3 "$M/var/harness/policy_engine_an.py" "$D" "$TAG" none "${TAG}path"
  python3 "$M/var/harness/timing_an.py" "$D" "$TAG"
} > "$D/$TAG.an" 2>&1
echo "$TAG: ok ($n macro files)"
