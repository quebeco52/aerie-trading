<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Model\Sector\CommodityBusinessModel;
use App\Service\Model\Sector\ReitBusinessModel;
use App\Service\Model\Sector\SemiconductorBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Service\Model\Sector\UtilityBusinessModel;
use PHPUnit\Framework\TestCase;

class AssetDepreciationDecayTest extends TestCase
{
    public function testSemiconductorAssetDepreciationDecay(): void
    {
        $model = new SemiconductorBusinessModel();

        // Exact replacement CapEx (R = 1.0): Zero drift
        $stock = new Stock();
        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 1.0, 0.25);
        $this->assertEquals('0.20', $stock->getOperatingMargin());

        // Underinvestment (R = 0.5): Efficiency decay
        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $decayed = (float) $stock->getOperatingMargin();
        $this->assertLessThan(0.20, $decayed);
        $this->assertGreaterThanOrEqual(SemiconductorBusinessModel::MIN_OPERATING_MARGIN_FLOOR, $decayed);

        // Modernization (R = 1.5): Productivity boost
        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $modernized = (float) $stock->getOperatingMargin();
        $this->assertGreaterThan(0.20, $modernized);
        $this->assertLessThanOrEqual(SemiconductorBusinessModel::MAX_OPERATING_MARGIN_CEILING, $modernized);
    }

    public function testCommodityAssetDepreciationDecay(): void
    {
        $model = new CommodityBusinessModel();

        $stock = new Stock();
        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 1.0, 0.25);
        $this->assertEquals('0.20', $stock->getOperatingMargin());

        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $this->assertLessThan(0.20, (float) $stock->getOperatingMargin());

        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $this->assertGreaterThan(0.20, (float) $stock->getOperatingMargin());
    }

    public function testUtilityAssetDepreciationDecay(): void
    {
        $model = new UtilityBusinessModel();

        $stock = new Stock();
        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 1.0, 0.25);
        $this->assertEquals('0.20', $stock->getOperatingMargin());

        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $this->assertLessThan(0.20, (float) $stock->getOperatingMargin());

        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $this->assertGreaterThan(0.20, (float) $stock->getOperatingMargin());
    }

    public function testStandardCorporateAssetDepreciationDecay(): void
    {
        $model = new StandardCorporateBusinessModel();

        $stock = new Stock();
        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 1.0, 0.25);
        $this->assertEquals('0.20', $stock->getOperatingMargin());

        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $this->assertLessThan(0.20, (float) $stock->getOperatingMargin());

        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $this->assertGreaterThan(0.20, (float) $stock->getOperatingMargin());
    }

    public function testReitAssetDepreciationDecay(): void
    {
        $model = new ReitBusinessModel();

        $stock = new Stock();
        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 1.0, 0.25);
        $this->assertEquals('0.20', $stock->getOperatingMargin());

        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 0.5, 0.25);
        $this->assertLessThan(0.20, (float) $stock->getOperatingMargin());

        $stock->setOperatingMargin('0.20');
        $model->applyAssetDepreciationDecay($stock, 1.5, 0.25);
        $this->assertGreaterThan(0.20, (float) $stock->getOperatingMargin());
    }
}
