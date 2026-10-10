<?php
// The corporate-tax target CreditFiscalSubsystem::calculateDynamicFiscalPolicy() clamps, recomputed unclamped from a
// macro state (DTO) with its parts; fiscalStance() takes MacroState, so its two public static pieces are composed here.
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem as CF;

function fiscalClampRow(object $m, int $seed, float $t): array
{
    $funded = $m->sovereignFundDollarsPerGdp > 0.0;
    $bohn = CF::bohnSurplus($m->sovereignNetDebtToGdp);
    $deficit = $funded ? 0.0 : CF::SOVEREIGN_STRUCTURAL_DEFICIT;
    $cut = CF::consolidationSpendingCut($m->sovereignNetDebtToGdp, $funded, $m->consolidationTaxShare);
    $stance = $bohn - $deficit - $cut;
    $stab = CF::FISCAL_STABILIZER_SENSITIVITY * $m->outputGapEma;
    $target = MacroEngine::BASE_CORPORATE_TAX_RATE + $m->corporateTaxPolicyShift + $stab + $stance;

    return ['seed' => $seed, 't' => round($t, 3), 'target' => $target, 'base' => MacroEngine::BASE_CORPORATE_TAX_RATE,
        'law' => $m->corporateTaxPolicyShift, 'stab' => $stab, 'stance' => $stance, 'bohn' => $bohn, 'deficit' => $deficit,
        'cut' => $cut, 'tax_share' => $m->consolidationTaxShare, 'rate' => $m->corporateTaxRate,
        'net_debt' => $m->sovereignNetDebtToGdp, 'fund_dpg' => $m->sovereignFundDollarsPerGdp, 'funded' => $funded,
        'gap_ema' => $m->outputGapEma, 'gap' => $m->outputGap, 'min' => 0.08, 'max' => 0.26];
}
