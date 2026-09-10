<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Trait;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\Model\BareStandardModel;
use App\Tests\Support\Model\ConfiguredStandardModel;
use PHPUnit\Framework\TestCase;

/**
 * The identity and factor-loading layer every sector model composes.
 */
final class StandardBaseModelTraitTest extends TestCase
{
    private BareStandardModel $model;
    private MathUtility $math;

    protected function setUp(): void
    {
        $this->model = new BareStandardModel();
        $this->math = new MathUtility();
    }

    /**
     * Declaring no macro fields is a genuine statement that the model reads none, not an oversight, so the
     * default must be an empty list rather than anything the field-declaration audit would wave through.
     */
    public function testAModelDeclaresNoMacroCouplingAndIsNonFinancialByDefault(): void
    {
        $this->assertSame([], $this->model->getOperatingMacroFields());
        $this->assertFalse($this->model->isFinancial(), 'The standard stack is the non-financial root.');
    }

    /**
     * The sector factor is the optional half of the two-factor demand model: a model that declares no
     * loading falls back to the one-factor firm model rather than to some default correlation with peers.
     */
    public function testSectorFactorLoadingFallsBackToTheOneFactorModel(): void
    {
        $this->assertSame(0.0, $this->model->getSectorFactorLoading(), 'An undeclared sector loading means no peer correlation at all.');
        $this->assertSame(0.30, (new ConfiguredStandardModel())->getSectorFactorLoading(), 'A declared loading must reach the draw.');
        $this->assertSame(0.60, $this->model->getFirmFactorLoading(), 'The firm loading is read ungated from the class.');

        // The two loadings must leave idiosyncratic variance behind, or a stream is fully explained by factors.
        $configured = new ConfiguredStandardModel();
        $explained = $configured->getFirmFactorLoading() ** 2 + $configured->getSectorFactorLoading() ** 2;
        $this->assertLessThan(1.0, $explained, 'Shared variance must stay below one so each stream keeps its own noise.');
    }

    /**
     * The stream context carries the model's loadings, and falls back to the one-factor draw whenever the
     * sector innovation is unavailable — a macro state without factors, or a call that passes no stock.
     */
    public function testStreamContextAppliesTheSectorInnovationOnlyWhenOneIsAvailable(): void
    {
        $model = new ConfiguredStandardModel();
        $stock = (new Stock())->setTicker('STR');
        $stock->setSector('Technology');

        $withFactor = new MacroStateDTO(sectorDemandZ: ['Technology' => 1.5]);
        $this->assertInstanceOf(\App\DTO\StreamContext::class, $model->exposeStreamContext([], $this->math, $withFactor, $stock));

        // A sector with no published factor, a macro state carrying none, and a call with no stock at all
        // must each degrade to the firm-only draw rather than erroring or inventing an innovation.
        $otherSector = (new Stock())->setTicker('OTH');
        $otherSector->setSector('Utilities');
        $this->assertInstanceOf(\App\DTO\StreamContext::class, $model->exposeStreamContext([], $this->math, $withFactor, $otherSector));
        $this->assertInstanceOf(\App\DTO\StreamContext::class, $model->exposeStreamContext([], $this->math, new MacroStateDTO(), $stock));
        $this->assertInstanceOf(\App\DTO\StreamContext::class, $model->exposeStreamContext([], $this->math, null, null));
    }

    /**
     * Cyclicality resolves sector constant first, unit fallback otherwise, with ticker tuning able to
     * override either — the precedence that lets one ticker be more cyclical than its sector.
     */
    public function testOperatingCyclicalityFallsBackToUnityAndHonoursTheSectorConstant(): void
    {
        $stock = (new Stock())->setTicker('NO_OVERRIDE_TICKER');

        $this->assertSame(1.0, $this->model->getOperatingCyclicality($stock), 'An undeclared sector moves one for one with the output gap.');
        $this->assertSame(1.80, (new ConfiguredStandardModel())->getOperatingCyclicality($stock), 'A declared cyclicality must reach the demand shift.');
    }

    /**
     * The operating base is the larger of revenue and equity, floored, so a pre-revenue or book-insolvent
     * firm still has a scale to size cash targets and hoarding against.
     */
    public function testOperatingBaseTakesTheLargerOfRevenueAndEquityAboveAFloor(): void
    {
        $revenueLed = (new Stock())->setTicker('REV');
        $revenueLed->setTotalRevenue('900000000');
        $revenueLed->setTotalEquity('400000000');
        $this->assertSame(900_000_000.0, $this->model->exposeOperatingBase($revenueLed), 'A revenue-heavy firm is sized on revenue.');

        $equityLed = (new Stock())->setTicker('EQ');
        $equityLed->setTotalRevenue('100000000');
        $equityLed->setTotalEquity('800000000');
        $this->assertSame(800_000_000.0, $this->model->exposeOperatingBase($equityLed), 'A pre-revenue firm is sized on its balance sheet.');

        $tiny = (new Stock())->setTicker('TINY');
        $tiny->setTotalRevenue('0');
        $tiny->setTotalEquity('0');
        $this->assertSame(FinancialConstants::MIN_OPERATING_BASE_CASH, $this->model->exposeOperatingBase($tiny), 'The base floors so cash targets never collapse to zero.');

        $insolvent = (new Stock())->setTicker('NEG');
        $insolvent->setTotalRevenue('5000000');
        $insolvent->setTotalEquity('-900000000');
        $this->assertSame(FinancialConstants::MIN_OPERATING_BASE_CASH, $this->model->exposeOperatingBase($insolvent), 'Negative equity must not produce a negative operating base.');
    }
}
