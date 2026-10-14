#!/bin/bash
# make_oil_ovr.sh <elasticity> <sigma>: ovrO<elasticity>v<sigma>/ with ENERGY_DEMAND_ELASTICITY and ENERGY_VOLATILITY replaced.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
NAME="O$1v$2"
mkdir -p "$H/ovr$NAME/Service/Macro/Subsystem"
sed -e "s/public const ENERGY_DEMAND_ELASTICITY = [0-9.]*;/public const ENERGY_DEMAND_ELASTICITY = $1;/" \
    "$PROJ/src/Service/Macro/Subsystem/CommodityLogisticsSubsystem.php" > "$H/ovr$NAME/Service/Macro/Subsystem/CommodityLogisticsSubsystem.php"
sed -e "s/public const ENERGY_VOLATILITY = [0-9.]*;/public const ENERGY_VOLATILITY = $2;/" \
    "$PROJ/src/Service/Macro/MacroEngine.php" > "$H/ovr$NAME/Service/Macro/MacroEngine.php"
echo "$NAME"
