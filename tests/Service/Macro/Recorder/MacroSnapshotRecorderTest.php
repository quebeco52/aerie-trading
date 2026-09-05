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
                    return count($params) === 79
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
                        && $params[78] === $dto->energyInventoryIndexEma;
                })
            );

        $recorder = new MacroSnapshotRecorder();
        $recorder->recordSnapshot($dto, $connMock);
    }
}
