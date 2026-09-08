<?php

declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Data\ModelParam;
use App\Data\StockModelTuning;
use App\DTO\ModelParameters;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;

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
