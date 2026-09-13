<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Data\ModelParam;
use App\Data\StockModelTuning;
use App\DTO\MacroStateDTO;
use App\DTO\ModelParameters;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

trait StandardBaseModelTrait
{
    protected string $modelIdentifier = 'none';

    public function setModelIdentifier(string $identifier): void
    {
        $this->modelIdentifier = $identifier;
    }

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

        return new StreamContext($momentum, $mathUtility, $this->getFirmFactorLoading(), $sectorInnovation, $this->getSectorFactorLoading());
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
     * Resolves model tuning parameters merging baseline defaults with ticker overrides.
     *
     * @param array<ModelParam|string, float> $defaults
     */
    protected function resolveModelParameters(Stock $stock, array $defaults = []): ModelParameters
    {
        return StockModelTuning::resolve($stock->getTicker(), $defaults);
    }
}
