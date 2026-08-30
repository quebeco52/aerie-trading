<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

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
        FinancialPhysicsTrait::updateDynamicRoic insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getReversionSpeed insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getMoatSpread insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getWorkingCapitalIntensity insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getCapExCompletionRate insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getPhysicalCapital insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::allowsPhysicalOrganicCapex insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getReturnBasisIncome insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getEffectiveReturn insteadof StandardOperatingPhysicsTrait;
        FinancialPhysicsTrait::getMaxOrganicGrowthSpeed insteadof StandardCapitalAllocationTrait;
        FinancialPhysicsTrait::getRegulatoryDividendCap insteadof StandardCapitalAllocationTrait;
        FinancialPhysicsTrait::checkBuybackRegulatoryLockout insteadof StandardCapitalAllocationTrait;
        FinancialPhysicsTrait::calculateStructuralEps insteadof StandardValuationTrait;
    }
}
