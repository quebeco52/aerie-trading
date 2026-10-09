#!/bin/bash
# boom_arms.sh <tag> <momentum> <reversion> <volatility> <lift> [district=0] [gauss=1] [disaster=1]: builds OVR arm <tag> from the working tree with
#   AssetMarketSubsystem RESIDENTIAL_PRICE_MOMENTUM / RESIDENTIAL_MEAN_REVERSION / RESIDENTIAL_VOLATILITY,
#   MacroAggregateSubsystem KALDOR_HOUSEHOLD_NEW_BORROWING, and CreditFiscalSubsystem with the District's
#   light-touch terms off (the US calibration arm) unless district=1; DEMAND_SHOCK_SIGMA x gauss, disaster sizes x disaster.
# Env EXTRA='File.php:CONST=value,...' patches further constants in the three copied files.
# Then: FC_DIR=<dir> fc_run.sh <tag>; python3 djk_an.py <dir> <tag>...
set -e
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness
SRC=/home/quebeco/Projects/Code/Private/aerie-trading/src/Service/Macro/Subsystem
TAG=$1; MOM=$2; REV=$3; VOL=$4; LIFT=$5; DIST=${6:-0}; GS=${7:-1}; DS=${8:-1}
O=$H/ovr/$TAG/Service/Macro/Subsystem
rm -rf $H/ovr/$TAG; mkdir -p $O
cp $SRC/AssetMarketSubsystem.php $SRC/MacroAggregateSubsystem.php $SRC/CreditFiscalSubsystem.php $O/
python3 - "$O" "$MOM" "$REV" "$VOL" "$LIFT" "$DIST" "$GS" "$DS" "${EXTRA:-}" <<'PY'
import re, sys
O, mom, rev, vol, lift, dist, gs, ds, extra = sys.argv[1:]
def patch(f, const, val):
    p = f'{O}/{f}'; s = open(p).read()
    s2, n = re.subn(rf'public const {const} = [-0-9.e]+;', f'public const {const} = {val};', s)
    assert n == 1, (f, const); open(p, 'w').write(s2)
patch('AssetMarketSubsystem.php', 'RESIDENTIAL_PRICE_MOMENTUM', mom)
patch('AssetMarketSubsystem.php', 'RESIDENTIAL_MEAN_REVERSION', rev)
patch('AssetMarketSubsystem.php', 'RESIDENTIAL_VOLATILITY', vol)
patch('MacroAggregateSubsystem.php', 'KALDOR_HOUSEHOLD_NEW_BORROWING', lift)
def scale(f, const, fn):
    p = f'{O}/{f}'; s = open(p).read()
    m = re.search(rf'public const {const} = ([0-9.e-]+);', s); assert m, const
    open(p, 'w').write(s.replace(m.group(0), f'public const {const} = {fn(float(m.group(1))):.6f};'))
scale('MacroAggregateSubsystem.php', 'DEMAND_SHOCK_SIGMA', lambda v: v * float(gs))
scale('MacroAggregateSubsystem.php', 'DEMAND_DISASTER_UP_RATE', lambda v: v / float(ds))
scale('MacroAggregateSubsystem.php', 'DEMAND_DISASTER_DOWN_RATE', lambda v: v / float(ds))
scale('MacroAggregateSubsystem.php', 'DEMAND_DISASTER_CAP', lambda v: v * float(ds))
for item in filter(None, extra.split(',')):
    f, kv = item.split(':'); k, v = kv.split('=')
    patch(f, k, v)
if dist != '1':
    patch('CreditFiscalSubsystem.php', 'DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT', '0.0')
    patch('CreditFiscalSubsystem.php', 'DISTRICT_THIN_CAPITAL_DRAG_SCALE', '1.0')
PY
echo "arm $TAG: momentum $MOM reversion $REV vol $VOL lift $LIFT district $DIST gauss $GS disaster $DS"
