#!/bin/bash
# chan_setup.sh [remove]: three detached worktrees of HEAD (src must be clean, `git status --short src` empty), each
# with its own vendor copy, the working tree's gate.php, and one capital-shortfall channel zeroed at its use site:
#   wt_noBridges  CreditFiscalSubsystem: the Bridges et al. household lending cut ($capitalSqueeze = 0)
#   wt_noPremium  DomesticDemand::excessCreditPremium(): the shortfall's premium on the demand drag
#   wt_noSLOOS    CreditFiscalSubsystem::calculateSloosCreditStandards(): the shortfall in the standards survey
# `chan_setup.sh remove` deletes them.
set -e
cd "$(dirname "$0")"
ROOT=../../..
if [ "$1" = remove ]; then
  for w in noBridges noPremium noSLOOS; do git -C $ROOT worktree remove --force var/harness/regulator/wt_$w || true; done
  exit 0
fi
for w in noBridges noPremium noSLOOS; do
  git -C $ROOT worktree add --detach var/harness/regulator/wt_$w HEAD
  cp -a $ROOT/vendor wt_$w/
  cp ../politics/gate.php wt_$w/var/harness/politics/gate.php
done
CF=src/Service/Macro/Subsystem/CreditFiscalSubsystem.php
MA=src/Service/Macro/Subsystem/Aggregate/DomesticDemand.php
sed -i 's/\$capitalSqueeze = \$lendingCut \* max(0.0, \$capitalRequired - \$state->bankCapitalRequiredLast);/$capitalSqueeze = 0.0;/' wt_noBridges/$CF
sed -i 's/+ (self::CAPITAL_SHORTFALL_PREMIUM_EQUIVALENT \* CreditFiscalSubsystem::capitalShortfall(\$state));/+ 0.0;/' wt_noPremium/$MA
sed -i 's/\$capitalTightening = DomesticDemand::CAPITAL_SHORTFALL_PREMIUM_EQUIVALENT \* self::capitalShortfall(\$state);/$capitalTightening = 0.0;/' wt_noSLOOS/$CF
git -C wt_noBridges diff --stat; git -C wt_noPremium diff --stat; git -C wt_noSLOOS diff --stat
