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
                    return count($params) === 62
                        && $params[1] === $dto->inflation
                        && $params[3] === $dto->outputGap
                        && $params[5] === $dto->policyRate;
                })
            );

        $recorder = new MacroSnapshotRecorder();
        $recorder->recordSnapshot($dto, $connMock);
    }
}
