<?php
declare(strict_types=1);

namespace App\Service\Model\Trait;

use App\Entity\Stock;

trait StandardBaseModelTrait
{
    protected string $modelIdentifier = 'none';

    public function setModelIdentifier(string $identifier): void
    {
        $this->modelIdentifier = $identifier;
    }

    public function getModelThresholds(): array
    {
        return \App\Data\Sectors::getModelThresholds($this->modelIdentifier);
    }

    protected function getOperatingBase(Stock $stock): float
    {
        return max((float) $stock->getTotalRevenue(), (float) $stock->getTotalEquity(), \App\Service\Math\FinancialConstants::MIN_OPERATING_BASE_CASH);
    }

    protected function resolveModelParameters(Stock $stock, array $defaults = []): array
    {
        return \App\Data\StockModelTuning::resolve($stock->getTicker(), $defaults);
    }
}
