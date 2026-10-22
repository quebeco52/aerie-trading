<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Data\Company\StockModelTuning;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\ModelParam;
use App\Service\Model\ModelParameters;
use App\Service\Model\StreamContext;

trait StandardBaseModelTrait
{
    public function isFinancial(): bool
    {
        return false;
    }

    /**
     * Default: no declared macro coupling. A model that does not override this reads no macro field.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [];
    }

    /**
     * Builds the quarterly stream context with this model's firm-factor and sector-factor loadings applied.
     * The sector factor is read from the macro state for the stock's own sector; models that pass neither
     * (or a macro state without sector factors, as in unit tests) fall back to the one-factor firm model.
     *
     * @param array<string, float> $momentum Previous quarter stream map ($stock->getEarningsMomentumZ()).
     */
    protected function createStreamContext(array $momentum, MathUtility $mathUtility, ?MacroStateDTO $macroState = null, ?Stock $stock = null): StreamContext
    {
        $sectorDemandLevel = null;
        if ($macroState !== null && $stock !== null) {
            $sector = $stock->getSector();
            $sectorDemandLevel = isset($macroState->sectorDemandZ[$sector]) ? (float) $macroState->sectorDemandZ[$sector] : null;
        }

        return new StreamContext($momentum, $mathUtility, $this->getFirmFactorLoading(), $sectorDemandLevel, $this->getSectorFactorLoading());
    }

    /**
     * Ticker override first (StockModelTuning), then the sector's OPERATING_CYCLICALITY.
     */
    public function getOperatingCyclicality(Stock $stock): float
    {
        $params = $this->resolveModelParameters($stock, [ModelParam::OperatingCyclicality->value => (float) static::OPERATING_CYCLICALITY]);

        return (float) $params[ModelParam::OperatingCyclicality];
    }

    protected function getOperatingBase(Stock $stock): float
    {
        return max((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity(), FinancialConstants::MIN_OPERATING_BASE_CASH);
    }

    /**
     * Folds a quarter's annualised return into the trailing return the firm is measured on, and pulls the result
     * toward the return its moat can sustain above the return it is required to make. Every model's return reverts
     * the same way; what differs between models is only what the return is struck on and what it is required to earn.
     *
     * The moat is worn down by market saturation (Penrose limit to growth), and the pull is scaled by the trailing
     * figure's weight in the model's blended return target, so that target moves at exactly the model's reversion
     * speed.
     *
     * @param float $trailing            The trailing return before this quarter; zero before the first report.
     * @param float $realised            This quarter's return, annualised.
     * @param float $requiredReturn      The return the capital must earn (cost of equity, WACC, or a cap rate).
     * @param float $trailingBlendWeight The trailing return's weight in the blended return target.
     * @param float $saturationBase      The capital the saturation penalty is sized on.
     * @param float $currentWeight       This quarter's weight in the trailing blend.
     * @return float The new trailing return, within the reported-return bounds.
     */
    protected function revertTrailingReturn(
        Stock $stock,
        float $trailing,
        float $realised,
        float $requiredReturn,
        float $trailingBlendWeight,
        float $saturationBase,
        ?MacroStateDTO $macroState,
        float $currentWeight = FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT,
    ): float {
        $blended = $trailing === 0.0 ? $realised : ($realised * $currentWeight) + ($trailing * (1.0 - $currentWeight));

        $saturationPenalty = $macroState !== null
            ? CorporateMetrics::getInstance()->calculateMarketSaturationPenalty($stock, $saturationBase, $macroState)
            : 0.0;
        // Saturation erodes the excess return above the required one, never the required return itself.
        $effectiveMoat = max(0.0, $this->getMoatSpread() - $saturationPenalty);
        $blended += TimeSeries::calculateReversionPull($blended, $requiredReturn, $this->getReversionSpeed() / $trailingBlendWeight, $effectiveMoat);

        return max(FinancialConstants::MIN_REPORTED_RETURN, min(FinancialConstants::MAX_REPORTED_RETURN, $blended));
    }

    /**
     * Resolves model tuning parameters merging baseline defaults with ticker overrides.
     *
     * @param array<string, float> $defaults Keyed by ModelParam values.
     */
    protected function resolveModelParameters(Stock $stock, array $defaults = []): ModelParameters
    {
        return StockModelTuning::resolve($stock->getTicker(), $defaults);
    }
}
