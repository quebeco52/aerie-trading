#!/bin/bash
# make_ovr.sh <arm> <tree>: copy the tree's OWN EarningsEngine.php into ovr-<arm>/ with a read-only probe wrapped around
# calculateDepreciation() (records utilization, seasonal factor, schedule D, charged D into \DepProbe). Prints the diff.
set -eu
D=$(cd "$(dirname "$0")" && pwd)
mkdir -p "$D/ovr-$1/Service/Corporate"
O="$D/ovr-$1/Service/Corporate/EarningsEngine.php"
sed '/private function calculateDepreciation(EarningsSimulationContext $ctx): void/{
n
s/^    {$/    {\
        $this->calculateDepreciationUnprobed($ctx);\
        \\DepProbe::$q[$ctx->stock->getTicker()] = [$ctx->capacityUtilization, $ctx->seasonalFactor, $ctx->structuralDepreciation, $ctx->quarterlyDepreciation];\
    }\
\
    private function calculateDepreciationUnprobed(EarningsSimulationContext $ctx): void\
    {/
}' "$2/src/Service/Corporate/EarningsEngine.php" > "$O"
php -l "$O" >/dev/null
diff "$2/src/Service/Corporate/EarningsEngine.php" "$O" || true
