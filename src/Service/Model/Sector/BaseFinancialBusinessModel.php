<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Service\Model\ModelParam;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroTransmission;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Trait\FinancialPhysicsTrait;
use App\Service\Model\Trait\StandardBaseModelTrait;
use App\Service\Model\Trait\StandardCapitalAllocationTrait;
use App\Service\Model\Trait\StandardOperatingPhysicsTrait;
use App\Service\Model\Trait\StandardTreasuryTrait;
use App\Service\Model\Trait\StandardValuationTrait;

/**
 * Base class for all financial institutions (banks, insurers, asset managers, brokerages, etc.).
 *
 * Centralizes standard trait composition and FinancialPhysicsTrait overrides (ROE evaluation,
 * regulatory leverage limits, bypass Hamada debt re-levering).
 */
abstract class BaseFinancialBusinessModel implements BusinessModelInterface
{
    use StandardTreasuryTrait;
    use StandardBaseModelTrait, StandardValuationTrait, StandardOperatingPhysicsTrait, StandardCapitalAllocationTrait, FinancialPhysicsTrait {
        FinancialPhysicsTrait::isFinancial insteadof StandardBaseModelTrait;
        FinancialPhysicsTrait::getTrueReturn insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getEvaluationCapital insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::calculateEconomicReturn insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getReversionSpeed insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getMoatSpread insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getWorkingCapitalIntensity insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getWorkingCapitalDays insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getCapExCompletionRate insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getPhysicalCapital insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getDepreciableBase insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::allowsPhysicalOrganicCapex insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getReturnBasisIncome insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getEffectiveReturn insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getBaselineReturn insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getPriceElasticityOfDemand insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getMaxOrganicGrowthSpeed insteadof StandardCapitalAllocationTrait;
        FinancialPhysicsTrait::getRegulatoryDividendCap insteadof StandardCapitalAllocationTrait;
        FinancialPhysicsTrait::checkBuybackRegulatoryLockout insteadof StandardCapitalAllocationTrait;
        FinancialPhysicsTrait::calculateStructuralEps insteadof StandardValuationTrait;
        FinancialPhysicsTrait::getEquityReturn insteadof StandardValuationTrait;
    }

    // --- Return Reversion ---
    /** Divisor on the trailing ROE's reversion speed: it reverts toward cost of equity plus moat at kappa / this. */
    public const TTM_ROE_WEIGHT = 0.50;

    // --- Bank Levy ---
    /** Whether the institution is a bank the levy is charged on: deposit-takers and investment banks are; brokers, clearinghouses, insurers, asset managers and non-bank lenders are not. */
    public const PAYS_BANK_LEVY = false;

    // --- Industry Share Dynamics ---
    /** Share of a financial institution's idiosyncratic gain taken from peers: deposits, mandates and AUM move between houses, but much of the swing is market volume. */
    public const DEFAULT_FINANCIAL_INDUSTRY_SUBSTITUTABILITY = 0.35;
    /** Share of an idiosyncratic gain taken from same-industry peers; funds, mandates and deposits move between houses only in part. */
    public const INDUSTRY_SUBSTITUTABILITY = self::DEFAULT_FINANCIAL_INDUSTRY_SUBSTITUTABILITY;

    // --- Firm-Level Common Factor ---
    /** One-factor loading of fee and spread streams on the institution-wide franchise innovation (rho^2 = 16% shared variance). */
    public const FIRM_FACTOR_LOADING = 0.40;
    /** Two-factor loading of fee and spread streams on the persistent macro-sector demand factor (rho_s^2 = 12% variance shared with sector peers). */
    public const SECTOR_FACTOR_LOADING = 0.35;

    // --- Bank Levy (UK Finance Act 2011, Schedule 19; FDIC) ---
    /** Share of deposits a deposit-insurance scheme covers, which the levy leaves out: 60.4% of US domestic deposits in 2024 (FDIC, estimated insured deposits). */
    public const INSURED_DEPOSIT_SHARE = 0.604;

    public function getFirmFactorLoading(): float
    {
        return static::FIRM_FACTOR_LOADING;
    }

    /**
     * The bank levy on the balance sheet at the rate in force: wholesale funding split by how much of it reprices
     * within a year (the floating share), the revolver as short-term funding, and the deposits deposit insurance does
     * not cover at the long-term rate; equity, which is Tier 1, and insured deposits are left out.
     */
    public function calculateAnnualBankLevy(Stock $stock, MacroStateDTO $macroState): float
    {
        if (!static::PAYS_BANK_LEVY || $macroState->bankLevyRate <= 0.0) {
            return 0.0;
        }

        return $macroState->bankLevyRate * $this->annualBankLevyBase($stock);
    }

    public function annualBankLevyBase(Stock $stock): float
    {
        if (!static::PAYS_BANK_LEVY) {
            return 0.0;
        }

        $wholesale = (float) $stock->getWholesaleDebt();
        $floating = max(0.0, min(1.0, (float) ($stock->getFloatingDebtRatio() ?? 0.0)));
        $uninsuredDeposits = (float) ($stock->getCustomerDeposits() ?? 0.0) * (1.0 - self::INSURED_DEPOSIT_SHARE);

        return MacroTransmission::calculateAnnualBankLevy(
            shortTermFunding: ($wholesale * $floating) + (float) $stock->getRevolverDrawn(),
            longTermFunding: ($wholesale * (1.0 - $floating)) + $uninsuredDeposits,
            shortTermRate: 1.0,
        );
    }

    public function getSectorFactorLoading(): float
    {
        return static::SECTOR_FACTOR_LOADING;
    }

    /**
     * An institution's leverage converges to its own time-invariant target (Gropp & Heider 2010), the ratio it
     * was built with. One without a tuned target runs none.
     */
    public function getTargetCapitalRatio(Stock $stock, ?MacroStateDTO $macroState = null): ?float
    {
        $target = $this->resolveModelParameters($stock, [ModelParam::TargetCapitalRatio->value => 0.0])[ModelParam::TargetCapitalRatio];

        return $target > 0.0 ? $target : null;
    }
}
