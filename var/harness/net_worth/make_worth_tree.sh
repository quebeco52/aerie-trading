#!/bin/bash
# make_worth_tree.sh <dest>: HEAD worktree + copied vendor and loop-fit data, with the fiscal reaction (Bohn surplus,
# consolidation cut, purchases target), the sovereign spread and the reimbursement update reading net financial worth
# (gross debt less the whole fund and the rescue holdings) instead of GFSM net debt. The term premium's duration
# supply and the published net debt stay GFSM. main must have no src/ changes against HEAD.
set -eu
M=/home/quebeco/Projects/Code/Private/aerie-trading
W=$1
cd "$M"
[ -z "$(git status --porcelain -- src)" ] || { echo "main src/ differs from HEAD" >&2; exit 1; }
git worktree add --detach "$W" HEAD
mkdir -p "$W/var/harness/net_worth"; cp var/harness/net_worth/* "$W/var/harness/net_worth/"
cp -dR vendor "$W/vendor"
cp var/harness/policy_fit.json var/harness/asym_fit.json "$W/var/harness/"
[ -f .env.local ] && cp .env.local "$W/" || true
cp .env "$W/.env"
F="$W/src/Service/Macro/Subsystem/CreditFiscalSubsystem.php"
perl -0pi -e '
  s/self::bohnSurplus\(\$state->sovereignNetDebtToGdp\)/self::bohnSurplus(self::worthDebt(\$state, \$state->sovereignNetDebtToGdp))/g;
  s/consolidationSpendingCut\(\$state->sovereignNetDebtToGdp,/consolidationSpendingCut(self::worthDebt(\$state, \$state->sovereignNetDebtToGdp),/g;
  s/purchasesTarget\(\$state->publicSpendingShare, \$state->sovereignNetDebtToGdp,/purchasesTarget(\$state->publicSpendingShare, self::worthDebt(\$state, \$state->sovereignNetDebtToGdp),/g;
  s/max\(0\.0, \$state->sovereignNetDebtToGdpEma - MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD\)/max(0.0, self::worthDebt(\$state, \$state->sovereignNetDebtToGdpEma) - MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD)/g;
  s/(    public static function bohnSurplus)/    \/** NET_WORTH arm: GFSM net debt less the fund\x27s equities and the rescue holdings (minus net financial worth). *\/\n    public static function worthDebt(MacroState \$state, float \$netDebt): float\n    {\n        return \$netDebt - (\$state->sovereignFundToGdp - \$state->sovereignFundBondsToGdp) - \$state->bankRescueAssetsToGdp;\n    }\n\n$1/;
' "$F"
rm -rf "$W/var/harness/cache"* "$M/var/harness/cache/test"
grep -c "worthDebt" "$F"
diff -r "$M/src" "$W/src" | grep -c '^>' || true
