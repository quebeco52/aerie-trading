#!/bin/bash
# setup.sh <snapdir>: trees and overlays for run.sh (slump diagnostic, round 2).
#   r2head = git archive OLD (default 85b1458c, before the package was committed): arm A, the old model.
#   r2new  = git archive REV + working-tree src (git diff --name-only REV -- src): arm G, the B + D + E package.
#   Every arm carries the pairing overlay P on AssetMarketSubsystem: stepMainlandQuarter() and mainlandCrash() draw
#   from a private MathUtility::ownStream(SEED * 7919 + 1), so the mainland's shocks match across arms.
#   ovrA: r2head AMS + P.  ovrG: r2new AMS + P.  The rest are r2new + P + one edit:
#   ovrP1 MAS PHILLIPS_MAX_CAPACITY 0.035 -> 0.060, PHILLIPS_CONVEX_KAPPA 0.0038 -> 0.00651 (slope at zero kept).
#   ovrP2 MAS PHILLIPS_DOWNWARD_RIGIDITY_FACTOR 0.50 -> 1.00.
#   ovrH2 AMS RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY 2.5 -> 0.0, RESIDENTIAL_INCOME_ELASTICITY 1.0 -> 2.75.
#   ovrN1 LMS calculateUnemployment: NAIRU moves (scarring, healing, clamp) feed crisisScarPending / OKUNS_COEFFICIENT.
#   ovrS1 MacroEngine STRESS_MULTIPLIER_GAP_SENSITIVITY 10.0 -> 0.0.
set -eu
SNAP=$1
REV=${REV:-HEAD}
OLD=${OLD:-85b1458c}
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$PROJ"
for t in r2head:$OLD r2new:$REV; do d=${t%%:*}; rm -rf "$SNAP/$d"; mkdir -p "$SNAP/$d"; git archive "${t#*:}" | tar -x -C "$SNAP/$d"; ln -s "$PROJ/vendor" "$SNAP/$d/vendor"; done
git diff --name-only "$REV" -- src | while read -r f; do cp "$f" "$SNAP/r2new/$f"; echo "copied $f"; done
D=Service/Macro/Subsystem
AMS=$D/AssetMarketSubsystem.php
MAS=$D/MacroAggregateSubsystem.php
LMS=$D/LaborMarketSubsystem.php
ME=Service/Macro/MacroEngine.php
NEW="$SNAP/r2new/src"
for a in A G P1 P2 H2 N1 S1; do rm -rf "$SNAP/ovr$a"; mkdir -p "$SNAP/ovr$a/$D"; done

edP() {
  sed -e '/private function stepMainlandQuarter/,/private static function mainlandCrashProbability/s/\$this->mathUtility->/$this->mainlandRng()->/g' \
      -e '/^    \/\*\* Chance a crash strikes the mainland in a given quarter. \*\/$/i\    private ?MathUtility $mainlandStream = null;\n\n    private function mainlandRng(): MathUtility\n    {\n        return $this->mainlandStream ??= MathUtility::ownStream(((int) getenv('"'"'SEED'"'"')) * 7919 + 1);\n    }\n' "$1"
}
edP "$SNAP/r2head/src/$AMS" > "$SNAP/ovrA/$AMS"
for a in G P1 P2 N1 S1; do edP "$NEW/$AMS" > "$SNAP/ovr$a/$AMS"; done
edP "$NEW/$AMS" | sed -e 's/RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY = 2\.5;/RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY = 0.0;/' \
  -e 's/RESIDENTIAL_INCOME_ELASTICITY = 1\.0;/RESIDENTIAL_INCOME_ELASTICITY = 2.75;/' > "$SNAP/ovrH2/$AMS"
sed -e 's/PHILLIPS_MAX_CAPACITY = 0\.035;/PHILLIPS_MAX_CAPACITY = 0.060;/' -e 's/PHILLIPS_CONVEX_KAPPA = 0\.0038;/PHILLIPS_CONVEX_KAPPA = 0.00651;/' \
  "$NEW/$MAS" > "$SNAP/ovrP1/$MAS"
sed -e 's/PHILLIPS_DOWNWARD_RIGIDITY_FACTOR = 0\.50;/PHILLIPS_DOWNWARD_RIGIDITY_FACTOR = 1.00;/' "$NEW/$MAS" > "$SNAP/ovrP2/$MAS"
sed -e '/^ *\$excessSlack = max(0\.0, \$state->unemploymentRateEma/i\        $nairuBefore = $state->nairu;' \
    -e '/^ *\$state->nairu = max(self::MIN_NAIRU, min(self::MAX_NAIRU, \$state->nairu));$/a\        $state->crisisScarPending -= ($state->nairu - $nairuBefore) / self::OKUNS_COEFFICIENT;' \
  "$NEW/$LMS" > "$SNAP/ovrN1/$LMS"
mkdir -p "$SNAP/ovrS1/Service/Macro"
sed -e 's/STRESS_MULTIPLIER_GAP_SENSITIVITY = 10\.0;/STRESS_MULTIPLIER_GAP_SENSITIVITY = 0.0;/' "$NEW/$ME" > "$SNAP/ovrS1/$ME"

chk() { echo "  $1 $(basename "$2"): $(grep -c -F -e "$3" "$SNAP/$1/$2") x '$3'"; }
echo "grep checks (expected counts in brackets):"
for a in ovrA ovrG ovrP1 ovrP2 ovrH2 ovrN1 ovrS1; do chk $a $AMS '$this->mainlandRng()->'; chk $a $AMS 'MathUtility::ownStream(((int) getenv('; done
echo "    [6 and 1 each]"
echo "  mathUtility draws left in AMS: head $(grep -c -F '$this->mathUtility->' "$SNAP/r2head/src/$AMS") -> $(grep -c -F '$this->mathUtility->' "$SNAP/ovrA/$AMS"), new $(grep -c -F '$this->mathUtility->' "$NEW/$AMS") -> $(grep -c -F '$this->mathUtility->' "$SNAP/ovrG/$AMS")    [each falls by 6]"
chk ovrP1 $MAS 'PHILLIPS_MAX_CAPACITY = 0.060;'; chk ovrP1 $MAS 'PHILLIPS_CONVEX_KAPPA = 0.00651;'; echo "    [1 each]"
chk ovrP2 $MAS 'PHILLIPS_DOWNWARD_RIGIDITY_FACTOR = 1.00;'; echo "    [1]"
chk ovrH2 $AMS 'RESIDENTIAL_UNEMPLOYMENT_SENSITIVITY = 0.0;'; chk ovrH2 $AMS 'RESIDENTIAL_INCOME_ELASTICITY = 2.75;'; echo "    [1 each]"
chk ovrN1 $LMS '$nairuBefore = $state->nairu;'; chk ovrN1 $LMS '$state->crisisScarPending -= ($state->nairu - $nairuBefore) / self::OKUNS_COEFFICIENT;'; echo "    [1 each]"
chk ovrS1 $ME 'STRESS_MULTIPLIER_GAP_SENSITIVITY = 0.0;'; echo "    [1]"
for f in "$SNAP"/ovr*/$D/*.php "$SNAP"/ovrS1/$ME; do php -l "$f" > /dev/null && echo "  lint ok $f" || echo "LINT FAIL $f"; done
echo "diff line counts:"
for a in A G P1 P2 H2 N1 S1; do for f in $AMS $MAS $LMS $ME; do
  [ -f "$SNAP/ovr$a/$f" ] || continue
  base="$NEW/$f"; [ "$a" = A ] && base="$SNAP/r2head/src/$f"
  echo "  ovr$a $(basename "$f"): $(diff "$base" "$SNAP/ovr$a/$f" | grep -c '^[<>]')"
done; done
rm -rf "$PROJ/var/harness/cache-r2head" "$PROJ/var/harness/cache-r2new"
