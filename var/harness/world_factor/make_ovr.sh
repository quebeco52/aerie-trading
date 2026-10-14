#!/bin/bash
# make_ovr.sh <elasticity> [short_sigma]: ovrE<elasticity>[s<sigma>]/ with CommodityLogisticsSubsystem's metals constants replaced.
set -eu
H=$(cd "$(dirname "$0")" && pwd)
PROJ=$(cd "$H/../../.." && pwd)
NAME="E$1${2:+s$2}"
D="$H/ovr$NAME/Service/Macro/Subsystem"
mkdir -p "$D"
sed -e "s/public const METALS_DEMAND_ELASTICITY = [0-9.]*;/public const METALS_DEMAND_ELASTICITY = $1;/" \
    ${2:+-e "s/public const METALS_SHORT_TERM_SIGMA = [0-9.]*;/public const METALS_SHORT_TERM_SIGMA = $2;/"} \
    "$PROJ/src/Service/Macro/Subsystem/CommodityLogisticsSubsystem.php" > "$D/CommodityLogisticsSubsystem.php"
echo "$NAME"
