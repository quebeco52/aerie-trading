<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\ConsensusDTO;
use PHPUnit\Framework\TestCase;

class ConsensusDTOTest extends TestCase
{
    public function testConsensusDTOProperties(): void
    {
        $dto = new ConsensusDTO(
            analystExpectedRevenue: 1050.0,
            analystExpectedVariableCosts: 420.0,
            dynamicVisibility: 0.75
        );

        $this->assertSame(1050.0, $dto->analystExpectedRevenue);
        $this->assertSame(420.0, $dto->analystExpectedVariableCosts);
        $this->assertSame(0.75, $dto->dynamicVisibility);
    }
}
