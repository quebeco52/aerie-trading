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
        return ['min_icr' => 2.00, 'bankrupt_equity' => 0.0,  'distress_equity' => 0.0,  'warning_equity' => 0.0,  'wholesale_leverage_limit' => 1.0,  'dividend_crisis_icr' => 1.50, 'buyback_min_icr' => 2.00, 'reversion_speed' => 0.20, 'moat_spread' => 0.000, 'nwc_intensity' => 0.10, 'capex_completion_rate' => 0.33];
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
