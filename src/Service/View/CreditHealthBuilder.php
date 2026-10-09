<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Math\FinancialConstants;

/**
 * What would put an operating company into bankruptcy, as its lenders see it.
 *
 * An operating firm files when it cannot pay (FailureSweep: a payment default still uncured once the grace
 * period lapses), so the card leads with that state and the three things that decide it: the committed line
 * it can still draw, the covenant that closes the bond market to it, and how many times its operating
 * income covers the interest. Merton's distance to default is the forward-looking reading the rating agency
 * grades on. A firm its capital ratio governs is judged on that instead (CompanySnapshotBuilder), and a
 * delisted one has nothing left to owe.
 */
class CreditHealthBuilder
{
    public function __construct(
        private readonly DebtEngine $debtEngine,
    ) {}

    /**
     * @return array{creditHealth: array{inDefault: bool, quartersInDefault: int, cureDueNextReport: bool, revolverCommitment: float, revolverDrawn: float, revolverUtilization: float|null, netDebtToEbitda: float, covenantLimit: float|null, hasCovenantHeadroom: bool, interestCoverage: float, distanceToDefault: float, coverageState: string}|null}
     */
    public function build(Stock $stock, MacroStateDTO $macroState): array
    {
        $strategy = Sectors::strategyFor($stock->getIndustry());
        if ($stock->isBankrupt() || $strategy->requiresAlternativeZScore()) {
            return ['creditHealth' => null];
        }

        $health = $this->debtEngine->analyzeDebtHealth($stock, $macroState);
        $commitment = (float) $stock->getRevolverCommitment();
        $drawn = (float) $stock->getRevolverDrawn();

        return ['creditHealth' => [
            'inDefault' => $stock->isPaymentDefault(),
            'quartersInDefault' => $stock->getQuartersInDefault(),
            // FailureSweep accelerates once the default has stood past the grace period.
            'cureDueNextReport' => $stock->isPaymentDefault() && $stock->getQuartersInDefault() >= FinancialConstants::PAYMENT_DEFAULT_GRACE_QUARTERS,
            'revolverCommitment' => $commitment,
            'revolverDrawn' => $drawn,
            'revolverUtilization' => $commitment > 0.0 ? $drawn / $commitment : null,
            'netDebtToEbitda' => $health->netDebtToEbitda,
            'covenantLimit' => $health->ebitdaCovenantLimit < DebtEngine::EBITDA_COVENANT_EXEMPT_LIMIT ? $health->ebitdaCovenantLimit : null,
            'hasCovenantHeadroom' => $health->hasLeverageHeadroom,
            'interestCoverage' => $health->interestCoverage,
            'distanceToDefault' => $this->debtEngine->resolveDistanceToDefault($stock, $macroState),
            // DebtEngine's flags: operating income below zero, or below the sector's minimum coverage.
            'coverageState' => $health->isLiquidityCrisis ? 'negative' : ($health->isLiquidityWarning ? 'below minimum' : 'adequate'),
        ]];
    }
}
