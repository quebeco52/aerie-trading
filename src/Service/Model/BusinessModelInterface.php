<?php

namespace App\Service\Model;

use App\Service\Model\Strategy\OperatingStrategyInterface;
use App\Service\Model\Strategy\DebtStrategyInterface;
use App\Service\Model\Strategy\CapitalAllocationStrategyInterface;
use App\Service\Model\Strategy\TreasuryStrategyInterface;
use App\Service\Model\Strategy\MaStrategyInterface;
use App\Service\Model\Strategy\ValuationStrategyInterface;

/**
 * Interface that defines the core financial physics required to process 
 * earnings and balance sheet evolutions for specific industries.
 * 
 * Segregated into specific strategy interfaces to allow domain engines to 
 * type-hint specific behaviors while allowing Business Models to act as a 
 * unified strategy provider.
 */
interface BusinessModelInterface extends 
    OperatingStrategyInterface,
    DebtStrategyInterface,
    CapitalAllocationStrategyInterface,
    TreasuryStrategyInterface,
    MaStrategyInterface,
    ValuationStrategyInterface
{
    public function setModelIdentifier(string $identifier): void;
    public function getModelThresholds(): array;
}
