<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\DTO\StreamContext;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\HeavyManufacturingBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The persistent macro-sector demand factor is the second common factor behind every firm's earnings:
 * peers in one sector must share part of each quarter's innovation, and a macro state without the factor
 * must leave the one-factor firm model untouched so existing calibrations and tests hold.
 */
#[AllowMockObjectsWithoutExpectations]
class SectorDemandFactorTest extends TestCase
{
    public function testTwoFactorInnovationCompositionKeepsUnitVarianceAndSharesSectorTerm(): void
    {
        // Firm draw F = 1.0, idiosyncratic u = 0.0; sector realization S = 2.0.
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal'])->getMock();
        $math->method('generateStandardNormal')->willReturnOnConsecutiveCalls(1.0, 0.0);

        $ctx = new StreamContext([], $math, 0.60, 2.0, 0.40);
        $z = $ctx->generateZ('sales', 0.0);

        // e = rho_s S + rho_f F + sqrt(1 - rho_s^2 - rho_f^2) u = 0.40 * 2.0 + 0.60 * 1.0 + 0
        $this->assertEqualsWithDelta(1.40, $z, 1e-9);
    }

    public function testSectorLoadingIsIgnoredWithoutASectorRealization(): void
    {
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal'])->getMock();
        $math->method('generateStandardNormal')->willReturnOnConsecutiveCalls(1.0, 0.0);

        $ctx = new StreamContext([], $math, 0.60, null, 0.40);
        $z = $ctx->generateZ('sales', 0.0);

        // One-factor fallback: e = 0.60 * F + 0.80 * u
        $this->assertEqualsWithDelta(0.60, $z, 1e-9);
    }

    public function testExogenousStreamsNeverLoadOnTheSectorFactor(): void
    {
        $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal'])->getMock();
        $math->method('generateStandardNormal')->willReturn(0.0);

        $ctx = new StreamContext([], $math, 0.60, 3.0, 0.40);
        $this->assertEqualsWithDelta(0.0, $ctx->generateExogenousZ('event', 0.0), 1e-9);
    }

    public function testPeersInOneSectorShareTheSectorDemandFactorThroughTheModel(): void
    {
        $model = new HeavyManufacturingBusinessModel();
        $macro = new MacroStateDTO(sectorDemandZ: ['Industrials' => -2.0]);

        $results = [];
        foreach (['ALPHA', 'BETA'] as $ticker) {
            $stock = new Stock();
            $stock->setTicker($ticker);
            $stock->setSector('Industrials');
            $stock->setBeta('1.0');

            // Every own draw is zero: whatever remains in the stream Z is the shared sector term.
            $math = $this->getMockBuilder(MathUtility::class)->onlyMethods(['generateStandardNormal'])->getMock();
            $math->method('generateStandardNormal')->willReturn(0.0);

            $results[$ticker] = $model->computeActualFinancials($stock, 100_000_000.0, 0.45, 20_000_000.0, 0.10, $macro, $math);
        }

        // OEM orders persist at phi = 0.20, so the innovation is scaled by sqrt(1 - phi^2).
        $expected = -2.0 * StandardCorporateBusinessModel::SECTOR_FACTOR_LOADING * sqrt(1.0 - 0.04);
        $this->assertEqualsWithDelta($expected, $results['ALPHA']->streamZ['oem_equipment'], 1e-9);
        $this->assertEqualsWithDelta($expected, $results['BETA']->streamZ['oem_equipment'], 1e-9);
        $this->assertLessThan(100_000_000.0, $results['ALPHA']->actualRevenue);
    }

    public function testMacroStateRoundTripsTheSectorDemandFactor(): void
    {
        $dto = new MacroStateDTO(sectorDemandZ: ['Energy' => 0.75]);
        $restored = MacroStateDTO::fromArray($dto->toArray());

        $this->assertEqualsWithDelta(0.75, $restored->sectorDemandZ['Energy'], 1e-12);
    }

    public function testRootLoadingsLeaveIdiosyncraticVariance(): void
    {
        $corporate = (StandardCorporateBusinessModel::FIRM_FACTOR_LOADING ** 2) + (StandardCorporateBusinessModel::SECTOR_FACTOR_LOADING ** 2);
        $this->assertLessThan(1.0, $corporate);
    }
}
