#!/bin/bash
# fc_arms.sh: OVR copies of CreditFiscalSubsystem for the financial-centre arms, from the working tree (arm "base").
#   us      the District's light-touch terms off (JST panel hazard, unscaled drag): the US calibration check
#   nocrisis the US arm with no credit crisis ever (logit intercept -40): the crisis-free cycle, CBO 1985-2007
#   noccyb  the regulator never sets the Basel III countercyclical buffer (US practice since 2016); measured inert
#           2026-09-28 (+0.03 crises a century, z 1.0), so the working tree keeps the Basel schedule
# Run with fc_run.sh <arm>; analyse with fc_an.py [dir] [arms...].
set -e
H=/home/quebeco/Projects/Code/Private/aerie-trading/var/harness
SRC=/home/quebeco/Projects/Code/Private/aerie-trading/src/Service/Macro/Subsystem/CreditFiscalSubsystem.php
rm -rf $H/ovr/fc $H/ovr/fcnoccyb
for arm in us noccyb nocrisis; do
  mkdir -p $H/ovr/$arm/Service/Macro/Subsystem
  cp $SRC $H/ovr/$arm/Service/Macro/Subsystem/CreditFiscalSubsystem.php
done
python3 - <<'PY'
H='/home/quebeco/Projects/Code/Private/aerie-trading/var/harness/ovr'
def patch(arm, old, new):
    p=f'{H}/{arm}/Service/Macro/Subsystem/CreditFiscalSubsystem.php'
    s=open(p).read(); assert s.count(old)==1, (arm, old); open(p,'w').write(s.replace(old,new))
patch('us', "public const DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT = 0.485;", "public const DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT = 0.0;")
patch('us', "public const DISTRICT_THIN_CAPITAL_DRAG_SCALE = 1.095;", "public const DISTRICT_THIN_CAPITAL_DRAG_SCALE = 1.0;")
patch('noccyb', "public const MAX_CCYB = 0.025;", "public const MAX_CCYB = 0.0;")
patch('nocrisis', "public const DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT = 0.485;", "public const DISTRICT_FUNDING_CRISIS_LOGIT_SHIFT = 0.0;")
patch('nocrisis', "public const CREDIT_CRISIS_LOGIT_INTERCEPT = -3.77;", "public const CREDIT_CRISIS_LOGIT_INTERCEPT = -40.0;")
PY
echo "fc arms rebuilt: us noccyb"
