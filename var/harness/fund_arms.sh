#!/bin/bash
# fund_arms.sh: (re)builds the OVR copies of SovereignFundSubsystem the fund harness arms run on, from the working tree.
#   norebal  the fund exists and draws, but neither band ever trips (rebalancing off; same fiscal path, near-common RNG)
#   nofund   the fund never incepts (no draw, no trades, no foreign-equity draws)
#   exec1    programmes trade over one month instead of three
#   nostab   the full fund, but the budget runs no fund-financed stabilisation (the fund as it was before 2026-09-27's hybrid)
# fund_run.sh <arm> <seed> [years] runs one arm; ARM=fund uses no OVR.
set -e
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness
SRC=/home/quebeco/Projects/Code/Private/aerie-trading/src/Service/Macro/Subsystem/SovereignFundSubsystem.php
for arm in norebal nofund exec1; do
  mkdir -p $H/ovr/$arm/Service/Macro/Subsystem
  cp $SRC $H/ovr/$arm/Service/Macro/Subsystem/SovereignFundSubsystem.php
done
python3 - <<'EOF'
H='/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/ovr'
def patch(arm, old, new):
    p=f'{H}/{arm}/Service/Macro/Subsystem/SovereignFundSubsystem.php'
    s=open(p).read(); assert s.count(old)==1, (arm, old); open(p,'w').write(s.replace(old,new))
patch('norebal', "        if (!$domesticBreach && !$equityBreach) {", "        if (true) {")
patch('nofund', "        $state->sovereignFundTrade = 0.0;\n\n        if (!$this->isIncepted($state)) {", "        $state->sovereignFundTrade = 0.0;\n        return;\n\n        if (!$this->isIncepted($state)) {")
patch('exec1', "public const REBALANCE_EXECUTION_MONTHS = 3.0;", "public const REBALANCE_EXECUTION_MONTHS = 1.0;")
import os, shutil
# The old 'spend' arm is gone: since 2026-09-27 the budget always spends the draw.
os.makedirs(f'{H}/nostab/Service/Macro/Subsystem', exist_ok=True)
shutil.copy('/home/quebeco/Projects/Code/Private/aerie-trading/src/Service/Macro/Subsystem/CreditFiscalSubsystem.php', f'{H}/nostab/Service/Macro/Subsystem/CreditFiscalSubsystem.php')
p = f'{H}/nostab/Service/Macro/Subsystem/CreditFiscalSubsystem.php'
src = open(p).read()
old = "        if ($state->sovereignFundDollarsPerGdp <= 0.0) {\n            $state->sovereignFundStabilisationToGdp = 0.0;"
assert src.count(old) == 1
open(p, 'w').write(src.replace(old, "        if (true) {\n            $state->sovereignFundStabilisationToGdp = 0.0;"))
EOF
echo "arms rebuilt in $H/ovr"
