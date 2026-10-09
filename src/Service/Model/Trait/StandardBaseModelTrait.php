<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Service\Model\ModelParam;
use App\Data\Company\StockModelTuning;
use App\DTO\MacroStateDTO;
use App\Service\Model\ModelParameters;
use App\Service\Model\StreamContext;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;

trait StandardBaseModelTrait
{
    public function isFinancial(): bool
    {
        return false;
    }

    /**
     * Default: no declared macro coupling. Composed by both business-model roots
     * (StandardCorporateBusinessModel and, via BaseFinancialBusinessModel, every financial
     * model), so every model that does not override this reads no macro field at all — a
     * genuine signal (see BiotechBusinessModel), not an oversight.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [];
    }

    /**
     * One-factor loading that every revenue stream drawn through createStreamContext() places on the
     * firm-wide demand innovation. Each model root (StandardCorporateBusinessModel, BaseFinancialBusinessModel)
     * declares FIRM_FACTOR_LOADING and returns it late-bound, so a sector model changes the loading by
     * overriding the constant alone.
     */
    abstract public function getFirmFactorLoading(): float;

    /**
     * Loading every revenue stream places on the persistent demand factor of the firm's macro sector
     * (MacroStateDTO::$sectorDemandZ). Peers in one sector share customers and input markets, so a slump
     * in industrial orders reaches every machinery maker's book together; the firm factor above carries the
     * remainder. Declared on each root as SECTOR_FACTOR_LOADING; rho_f^2 + rho_s^2 must stay below one.
     */
    abstract public function getSectorFactorLoading(): float;

    /**
     * Builds the quarterly stream context with this model's firm-factor and sector-factor loadings applied.
     * The sector factor is read from the macro state for the stock's own sector; models that pass neither
     * (or a macro state without sector factors, as in unit tests) fall back to the one-factor firm model.
     *
     * @param array<string, float> $momentum Previous quarter stream map ($stock->getEarningsMomentumZ()).
     */
    protected function createStreamContext(array $momentum, MathUtility $mathUtility, ?MacroStateDTO $macroState = null, ?Stock $stock = null): StreamContext
    {
        $sectorInnovation = null;
        if ($macroState !== null && $stock !== null) {
            $sector = $stock->getSector();
            $sectorInnovation = isset($macroState->sectorDemandZ[$sector]) ? (float) $macroState->sectorDemandZ[$sector] : null;
        }

        return $this->activeStreamContext = new StreamContext($momentum, $mathUtility, $this->getFirmFactorLoading(), $sectorInnovation, $this->getSectorFactorLoading());
    }

    /** The stream context of the physics call in progress, kept for the actuals bridge to read the sector split from. */
    private ?StreamContext $activeStreamContext = null;

    /**
     * Hands over, and forgets, the stream context the physics call just built. Set inside calculateSectorPhysics
     * and consumed in the same computeActualFinancials frame, so the model instance carries nothing between calls.
     */
    protected function takeActiveStreamContext(): ?StreamContext
    {
        $streams = $this->activeStreamContext;
        $this->activeStreamContext = null;

        return $streams;
    }

    /**
     * Ticker override first (StockModelTuning), then the sector constant, then unit cyclicality.
     */
    public function getOperatingCyclicality(Stock $stock): float
    {
        $sectorDefault = defined('static::OPERATING_CYCLICALITY') ? (float) static::OPERATING_CYCLICALITY : 1.0;
        $params = $this->resolveModelParameters($stock, [ModelParam::OperatingCyclicality->value => $sectorDefault]);

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
     * figure's weight in the model's blended return target, so that target moves at exactly the model's reversion speed.
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
        float $currentWeight = FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT
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
     * @param array<ModelParam|string, float> $defaults
     */
    protected function resolveModelParameters(Stock $stock, array $defaults = []): ModelParameters
    {
        return StockModelTuning::resolve($stock->getTicker(), $defaults);
    }
}
