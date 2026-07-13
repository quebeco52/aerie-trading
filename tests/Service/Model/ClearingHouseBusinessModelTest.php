<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use PHPUnit\Framework\TestCase;
use App\Service\Model\ClearingHouseBusinessModel;
use App\Service\Model\BusinessModelInterface;
use App\Service\Math\MathUtility;

class ClearingHouseBusinessModelTest extends TestCase
{
    private ClearingHouseBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new ClearingHouseBusinessModel();
    }

    public function testImplementsBusinessModelInterface(): void
    {
        $this->assertInstanceOf(BusinessModelInterface::class, $this->model);
    }

    public function testCalculateEarningsValue(): void
    {
        $mathMock = $this->createMock(MathUtility::class);

        $val1 = $this->model->calculateEarningsValue(100.0, 150.0, 5.0, 0.08, $mathMock);
        $this->assertSame(150.0, $val1);

        $val2 = $this->model->calculateEarningsValue(200.0, 150.0, 5.0, 0.08, $mathMock);
        $this->assertSame(200.0, $val2);
    }
}
