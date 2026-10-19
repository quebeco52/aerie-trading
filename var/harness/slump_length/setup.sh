#!/bin/bash
# setup.sh <snapdir>: tree and overrides for run.sh (slump-length candidates).
#   sltree = git archive REV (default HEAD) + working-tree src: arm A (control).
#   ovrB: MAS easing drag 0.5505 -> 1.2505.        ovrC: MPS rule reads today's gap (cold-start formula always).
#   ovrD: MPS rule sees through only a positive productivity gap.
#   ovrE: MAS mainland as a level part of the gap, loading 0.5.   ovrF: B + C + D + E.
#   ovrH: B + D.   ovrG: B + D + E.
set -eu
SNAP=$1
REV=${REV:-HEAD}
PROJ=$(cd "$(dirname "$0")/../../.." && pwd)
cd "$PROJ"
rm -rf "$SNAP/sltree"; mkdir -p "$SNAP/sltree"
git archive "$REV" | tar -x -C "$SNAP/sltree"
ln -s "$PROJ/vendor" "$SNAP/sltree/vendor"
git diff --name-only "$REV" -- src | while read -r f; do cp "$f" "$SNAP/sltree/$f"; echo "copied $f"; done
D=Service/Macro/Subsystem
MAS=$D/MacroAggregateSubsystem.php
MPS=$D/MonetaryPolicySubsystem.php
SRC="$SNAP/sltree/src"
for a in B C D E F G H; do rm -rf "$SNAP/ovr$a"; mkdir -p "$SNAP/ovr$a/$D"; done

edB() { sed -e 's/KALDOR_MONETARY_DRAG_ACCOMMODATIVE = 0\.5505;/KALDOR_MONETARY_DRAG_ACCOMMODATIVE = 1.2505;/' "$1"; }
edE() {
  sed -e 's/\$mainlandImpulse = \$stressMultiplier \* self::mainlandDemandImpulse(\$mainlandSurprise);/$mainlandImpulse = 0.0;/' \
      -e '/^ *\$state->netExportGap = \$netExportGap;$/a\        $mainlandLevelChange = $mainlandSurprise !== 0.0 ? 0.5 * ($state->foreignOutputGap - $state->foreignOutputGapLag) : 0.0;' \
      -e "s/'netExports' => \$tradeChange \/ \$dt,/'netExports' => (\$tradeChange + \$mainlandLevelChange) \/ \$dt,/" \
      -e 's/return self::domesticDemandGapAt(\$state->outputGap, \$state->productivitySupplyGap,/return self::domesticDemandGapAt($state->outputGap - (0.5 * $state->foreignOutputGap), $state->productivitySupplyGap,/' "$1"
}
# C: the cold-start branch always taken (today's gap, no EMA, no projection).
edC() { sed -e 's/if (\$state->outputGapEma === \$openingGap && \$state->outputGap !== \$openingGap) {/if (true) { \/\/ ARM-C/' "$1"; }
# D: only inside cyclicalGapForecast (lines between its signature and the next docblock).
edD() {
  sed -e '/private function cyclicalGapForecast/,/^    \/\*\*/{
s/- \$state->productivitySupplyGap - \$state->inventoryInvestmentGap/- max(0.0, $state->productivitySupplyGap) - $state->inventoryInvestmentGap/
s/^\( *\)- \$state->productivitySupplyGap$/\1- max(0.0, $state->productivitySupplyGap)/
}' "$1"
}
edB "$SRC/$MAS" > "$SNAP/ovrB/$MAS"
edC "$SRC/$MPS" > "$SNAP/ovrC/$MPS"
edD "$SRC/$MPS" > "$SNAP/ovrD/$MPS"
edE "$SRC/$MAS" > "$SNAP/ovrE/$MAS"
edB "$SRC/$MAS" | edE /dev/stdin > "$SNAP/ovrF/$MAS"
edC "$SRC/$MPS" | edD /dev/stdin > "$SNAP/ovrF/$MPS"
edB "$SRC/$MAS" > "$SNAP/ovrH/$MAS"
edD "$SRC/$MPS" > "$SNAP/ovrH/$MPS"
edB "$SRC/$MAS" | edE /dev/stdin > "$SNAP/ovrG/$MAS"
edD "$SRC/$MPS" > "$SNAP/ovrG/$MPS"

chk() { echo "  $1: $(grep -c -F -e "$3" "$SNAP/$1/$2") x '$3'"; }
echo "grep checks (expected counts in brackets):"
for a in ovrB ovrF ovrG ovrH; do chk $a $MAS 'KALDOR_MONETARY_DRAG_ACCOMMODATIVE = 1.2505;'; done; echo "    [1 each]"
for a in ovrC ovrF; do chk $a $MPS 'if (true) { // ARM-C'; done; echo "    [1 each]"
for a in ovrG ovrH; do chk $a $MPS 'if (true) { // ARM-C'; done; echo "    [0 each]"
chk ovrH $MAS '$mainlandImpulse = 0.0;'; echo "    [0]"
for a in ovrD ovrF ovrG ovrH; do chk $a $MPS 'max(0.0, $state->productivitySupplyGap)'; chk $a $MPS '- $state->productivitySupplyGap'; done; echo "    [2 max, 0 bare each]"
for a in ovrE ovrF ovrG; do
  chk $a $MAS '$mainlandImpulse = 0.0;'
  chk $a $MAS '$mainlandLevelChange = $mainlandSurprise !== 0.0 ? 0.5 * ($state->foreignOutputGap - $state->foreignOutputGapLag) : 0.0;'
  chk $a $MAS "'netExports' => (\$tradeChange + \$mainlandLevelChange) / \$dt,"
  chk $a $MAS 'domesticDemandGapAt($state->outputGap - (0.5 * $state->foreignOutputGap),'
done; echo "    [1 each]"
for f in "$SNAP"/ovr*/$D/*.php; do php -l "$f" > /dev/null || echo "LINT FAIL $f"; done
echo "diff line counts vs sltree:"; for a in B C D E F G H; do for f in $MAS $MPS; do [ -f "$SNAP/ovr$a/$f" ] && echo "  ovr$a $(basename $f): $(diff "$SRC/$f" "$SNAP/ovr$a/$f" | grep -c '^[<>]')"; done; done
rm -rf "$PROJ/var/harness/cache-sltree"
