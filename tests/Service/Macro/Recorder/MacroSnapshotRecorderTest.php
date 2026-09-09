<?php

namespace App\Tests\Service\Macro\Recorder;

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroState;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

class MacroSnapshotRecorderTest extends TestCase
{
    public function testRecordSnapshotExecutesInsertStatement(): void
    {
        $state = new MacroState();
        $dto = MacroStateDTO::fromMacroState($state);

        $connMock = $this->createMock(Connection::class);
        $connMock->expects($this->once())
            ->method('executeStatement')
            ->with(
                $this->stringContains('INSERT INTO macro_report'),
                $this->callback(function (array $params) use ($dto) {
                    // Check that key fields are mapped into parameters
                    return count($params) === 107
                        && $params[1] === $dto->inflation
                        && $params[3] === $dto->outputGap
                        && $params[5] === $dto->policyRate
                        && $params[7] === $dto->targetRate
                        && $params[66] === $dto->nairu
                        && $params[68] === $dto->sovereignDebtToGdp
                        && $params[70] === $dto->financialConditionsIndex
                        && $params[73] === $dto->supercoreInflationEma
                        && $params[74] === $dto->coreGoodsInflationEma
                        && $params[75] === $dto->cumulativeInflationGapEma
                        && $params[76] === $dto->highYieldCreditSpreadEma
                        && $params[77] === $dto->inventoryStockGapEma
                        && $params[78] === $dto->energyInventoryIndexEma
                        && $params[79] === $dto->capacityUtilizationRate
                        && $params[80] === $dto->capacityUtilizationRateEma
                        && $params[81] === $dto->recessionProbability
                        && $params[82] === $dto->recessionProbabilityEma
                        && $params[83] === $dto->corporateDefaultRate
                        && $params[84] === $dto->corporateDefaultRateEma
                        && $params[85] === $dto->sloosTighteningIndex
                        && $params[86] === $dto->sloosTighteningIndexEma
                        && $params[87] === $dto->supplyChainPressureIndex
                        && $params[88] === $dto->supplyChainPressureIndexEma
                        && $params[89] === $dto->refiningCrackSpread
                        && $params[90] === $dto->refiningCrackSpreadEma
                        && $params[91] === $dto->dealActivityIndex
                        && $params[92] === $dto->dealActivityIndexEma
                        && $params[93] === $dto->manufacturingPmi
                        && $params[94] === $dto->manufacturingPmiEma
                        && $params[95] === $dto->producerPriceInflation
                        && $params[96] === $dto->producerPriceInflationEma
                        && $params[97] === $dto->tradeBalanceToGdp
                        && $params[98] === $dto->tradeBalanceToGdpEma
                        && $params[99] === $dto->housingStartsIndex
                        && $params[100] === $dto->housingStartsIndexEma
                        && $params[101] === $dto->moneySupplyGrowth
                        && $params[102] === $dto->moneySupplyGrowthEma;
                })
            );

        $recorder = new MacroSnapshotRecorder();
        $recorder->recordSnapshot($dto, $connMock);
    }
}
