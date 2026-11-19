<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\DTO\MacroStateDTO;
use App\Service\Model\Strategy\CapitalAllocationStrategyInterface;
use App\Service\Model\Strategy\DebtStrategyInterface;
use App\Service\Model\Strategy\MaStrategyInterface;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use App\Service\Model\Strategy\TreasuryStrategyInterface;
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
    public function isFinancial(): bool;

    /**
     * The seed row with the opening books its character implies (App\Service\Market\Pricing\SeedRows). A row that
     * states its books is handed back as it is.
     *
     * @param array<string, mixed> $seedRow A row of App\Data\Company\InitialMarket::STOCKS.
     * @return array<string, mixed>
     */
    public function resolveOpeningBooks(array $seedRow, MacroStateDTO $openingMacro): array;
}
