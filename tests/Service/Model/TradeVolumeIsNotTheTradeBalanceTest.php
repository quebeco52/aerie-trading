<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Model\Sector\ApparelManufacturingBusinessModel;
use App\Service\Model\Sector\CommunicationEquipmentBusinessModel;
use App\Service\Model\Sector\ComputerHardwareBusinessModel;
use App\Service\Model\Sector\LogisticsBusinessModel;
use App\Service\Model\Sector\MerchantHouseBusinessModel;
use App\Service\Model\Sector\RailroadBusinessModel;
use App\Service\Model\Sector\ShippingBusinessModel;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The trade balance is net exports, not a volume: a slump at home cuts imports and lifts it. Carriers and exporters
 * move with gross trade, the domestic cycle for what comes in and the customers abroad for what goes out, so a
 * balance that moves on its own moves none of their revenue.
 */
#[Group('unit')]
final class TradeVolumeIsNotTheTradeBalanceTest extends TestCase
{
    /** @return iterable<string, array{OperatingStrategyInterface}> */
    public static function tradeModels(): iterable
    {
        yield 'shipping' => [new ShippingBusinessModel()];
        yield 'logistics' => [new LogisticsBusinessModel()];
        yield 'railroad' => [new RailroadBusinessModel()];
        yield 'merchant house' => [new MerchantHouseBusinessModel()];
        yield 'computer hardware' => [new ComputerHardwareBusinessModel()];
        yield 'communication equipment' => [new CommunicationEquipmentBusinessModel()];
        yield 'apparel' => [new ApparelManufacturingBusinessModel()];
    }

    #[DataProvider('tradeModels')]
    public function testANetBalanceMovingAloneMovesNoRevenue(OperatingStrategyInterface $model): void
    {
        $stock = new Stock();
        $stock->setTicker('TRDE');
        $stock->setBeta('1.2');
        $quiet = new ScriptedDraws();

        $revenue = static fn(float $tradeBalance): float => $model->computeActualFinancials(
            $stock,
            1000.0,
            0.40,
            50.0,
            0.15,
            new MacroStateDTO(outputGapEma: -0.02, tradeBalanceToGdpEma: $tradeBalance),
            $quiet,
        )->actualRevenue;

        $this->assertSame($revenue(MacroConstants::TRADE_BALANCE_BASELINE), $revenue(MacroConstants::TRADE_BALANCE_BASELINE + 0.02));
    }
}
