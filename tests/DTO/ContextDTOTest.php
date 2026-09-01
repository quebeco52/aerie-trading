<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\AcquisitionContext;
use App\DTO\CapitalAllocationContext;
use App\DTO\DivestitureContext;
use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Model\BusinessModelInterface;
use PHPUnit\Framework\TestCase;

class ContextDTOTest extends TestCase
{
    public function testMarketPricingContextInstantiation(): void
    {
        $macro = new MacroStateDTO();
        $ctx = new MarketPricingContext(
            currentPrice: 100.0,
            currentVolatility: 0.20,
            longTermVolatility: 0.20,
            earningsPerShare: 5.0,
            dt: 1.0,
            macroState: $macro,
            bookValuePerShare: 40.0
        );

        $this->assertSame(100.0, $ctx->currentPrice);
        $this->assertSame(0.20, $ctx->currentVolatility);
        $this->assertSame(5.0, $ctx->earningsPerShare);
        $this->assertSame(1.0, $ctx->dt);
        $this->assertSame($macro, $ctx->macroState);
        $this->assertSame(40.0, $ctx->bookValuePerShare);
        $this->assertSame(2.0, $ctx->lambda);
    }

    public function testAcquisitionContextInstantiation(): void
    {
        $stock = new Stock();
        $stock->setTicker('ACQ');
        $macro = new MacroStateDTO();

        $ctx = new AcquisitionContext($stock, $macro, 1.0);
        $this->assertSame($stock, $ctx->acquirer);
        $this->assertSame($macro, $ctx->macroState);
        $this->assertSame(1.0, $ctx->dt);
        $this->assertSame(0.0, $ctx->treasury);
    }

    public function testDivestitureContextInstantiation(): void
    {
        $stock = new Stock();
        $stock->setTicker('SELLER');
        $macro = new MacroStateDTO();

        $ctx = new DivestitureContext($stock, $macro, 1.0);
        $this->assertSame($stock, $ctx->seller);
        $this->assertSame($macro, $ctx->macroState);
        $this->assertSame(1.0, $ctx->dt);
    }

    public function testCapitalAllocationContextInstantiation(): void
    {
        $stock = new Stock();
        $macro = new MacroStateDTO();

        $ctx = new CapitalAllocationContext(
            stock: $stock,
            macroState: $macro,
            actualAnnualEps: 4.0,
            quarterlyFcfPerShare: 1.0,
            currentPrice: 50.0,
            sharesOutstanding: 1000000.0
        );

        $this->assertSame($stock, $ctx->stock);
        $this->assertSame($macro, $ctx->macroState);
        $this->assertSame(4.0, $ctx->actualAnnualEps);
        $this->assertSame(1.0, $ctx->quarterlyFcfPerShare);
        $this->assertSame(50.0, $ctx->currentPrice);
        $this->assertSame(1000000.0, $ctx->sharesOutstanding);
    }

    public function testEarningsSimulationContextInstantiation(): void
    {
        $stock = new Stock();
        $macro = new MacroStateDTO();
        $strategyStub = $this->createStub(BusinessModelInterface::class);

        $ctx = new EarningsSimulationContext(
            stock: $stock,
            macroState: $macro,
            strategy: $strategyStub,
            businessModel: 'tech',
            dt: 0.25
        );

        $this->assertSame($stock, $ctx->stock);
        $this->assertSame($macro, $ctx->macroState);
        $this->assertSame($strategyStub, $ctx->strategy);
        $this->assertSame('tech', $ctx->businessModel);
        $this->assertSame(0.25, $ctx->dt);
    }
}
