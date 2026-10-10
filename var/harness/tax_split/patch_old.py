#!/usr/bin/env python3
"""patch_old.py <tree>: restore the pre-split corporate-rate stabiliser and revenue in <tree>'s CreditFiscalSubsystem."""
import sys

path = sys.argv[1] + "/src/Service/Macro/Subsystem/CreditFiscalSubsystem.php"
src = open(path).read()
subs = [
    ("        $state->netTaxRate += MacroEngine::FISCAL_ADJUSTMENT_SPEED * ($targetNetTaxRate - $state->netTaxRate) * $dt;\n", ""),
    ("$state->corporateTaxRate += MacroEngine::FISCAL_ADJUSTMENT_SPEED * (MacroEngine::BASE_CORPORATE_TAX_RATE + $state->corporateTaxPolicyShift - $state->corporateTaxRate) * $dt;",
     "$state->corporateTaxRate += MacroEngine::FISCAL_ADJUSTMENT_SPEED * (MacroEngine::BASE_CORPORATE_TAX_RATE + $state->corporateTaxPolicyShift + (self::FISCAL_STABILIZER_SENSITIVITY * $state->outputGapEma) + self::fiscalStance($state) - $state->corporateTaxRate) * $dt;"),
    ("$taxRevenue = ($state->netTaxRate * $output)",
     "$taxRevenue = (($state->corporateTaxRate + MacroEngine::TARGET_CORPORATE_TAX_RATE - MacroEngine::BASE_CORPORATE_TAX_RATE - $state->corporateTaxShiftRealized) * $output)"),
    ("+ ($state->corporateTaxRate * self::CORPORATE_PROFITS_TO_GDP * $output)",
     "+ ($state->corporateTaxShiftRealized * self::CORPORATE_PROFITS_TO_GDP * $output)"),
]
for old, new in subs:
    assert src.count(old) == 1, old
    src = src.replace(old, new)
open(path, "w").write(src)
print("patched", path)
