<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\NarrativeEngine;
use App\Service\Market\MarketConsensusEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class RevenueDrainSimulationTest extends TestCase
{
    public function testSimulateMultipleQuartersAndLogRevenue(): void
    {
        $mathUtility = new MathUtility();
        $corporateMetrics = new CorporateMetrics();
        $debtEngine = new DebtEngine($mathUtility, $corporateMetrics);
        $capExEngine = new CapExEngine();
        $marketConsensusEngine = new MarketConsensusEngine();

        $ledgerMock = $this->createStub(CorporateLedgerService::class);
        $eventPublisherMock = $this->createStub(MarketEventPublisher::class);
        $narrativeEngineMock = $this->createStub(NarrativeEngine::class);
        $eventDispatcherMock = $this->createStub(EventDispatcherInterface::class);

        $treasuryEngine = new TreasuryEngine($corporateMetrics, $debtEngine, $capExEngine, $mathUtility);
        $capitalAllocationEngine = new CapitalAllocationEngine($ledgerMock, $corporateMetrics, $debtEngine, $mathUtility, $treasuryEngine);

        $earningsEngine = new EarningsEngine(
            $eventDispatcherMock,
            $eventPublisherMock,
            $capitalAllocationEngine,
            $debtEngine,
            $capExEngine,
            $mathUtility,
            $corporateMetrics,
            $narrativeEngineMock,
            $marketConsensusEngine
        );

        $tickers = ['TICK', 'WING', 'CASC', 'SHER', 'IBHI'];
        foreach ($tickers as $ticker) {
            $stock = new Stock();
            $stock->setTicker($ticker);
            $stock->setName($ticker);
            $stock->setIndustry('Consumer Electronics');
            if ($ticker === 'IBHI') $stock->setIndustry('Engineering & Construction');
            if ($ticker === 'WING') $stock->setIndustry('Steel');
            if ($ticker === 'CASC') $stock->setIndustry('Oil & Gas Refining & Marketing');
            if ($ticker === 'SHER') $stock->setIndustry('Apparel Manufacturing');

            $initMap = [];
            foreach (\App\Data\InitialMarket::STOCKS as $s) {
                $initMap[$s['ticker']] = $s;
            }
            $data = $initMap[$ticker] ?? [];

            $stock->setEarningsPerShare('2.50');
            $stock->setSharesOutstanding((string) ($data['shares_outstanding'] ?? 1000000000));
            $stock->setPrice('50.00');
            $stock->setVolatility((string) ($data['volatility'] ?? 0.15));
            $stock->setCurrentVolatility((string) ($data['volatility'] ?? 0.15));
            $stock->setBeta((string) ($data['beta'] ?? 1.0));
            $stock->setTotalEquity((string) ($data['total_equity'] ?? 40000000000.00));
            $stock->setWholesaleDebt((string) ($data['wholesale_debt'] ?? 10000000000.00));
            $stock->setCorporateTreasury((string) ($data['corporate_treasury'] ?? 5000000000.00));
            $stock->setCustomerDeposits((string) ($data['customer_deposits'] ?? 0.00));
            $stock->setBaselineRoic((string) ($data['baseline_roic'] ?? 0.15));
            $stock->setBaselineRoe((string) ($data['baseline_roe'] ?? 0.15));
            $stock->setOperatingMargin((string) ($data['operating_margin'] ?? 0.18));
            $stock->setTargetPayoutRatio((string) ($data['target_payout_ratio'] ?? 0.30));
            $stock->setDividendSpeed((string) ($data['dividendSpeed'] ?? 0.20));
            $stock->setCapexRatio((string) ($data['capex_ratio'] ?? 0.20));
            $stock->setSamRatio((string) ($data['sam_ratio'] ?? 0.05));
            $stock->setFixedCostRatio((float) ($data['fixed_cost_ratio'] ?? 0.35));
            $stock->setDepreciationRate((string) ($data['depreciation_rate'] ?? 0.05));

            $macro = new MacroStateDTO();
            $ticksPerYear = 3600;
            $ticksPerQuarter = (int) ($ticksPerYear / 4);
            $reportingTick = EarningsEngine::resolveReportingTick($ticker, $ticksPerYear);

            $initialRev = 0.0;
            $finalRev = 0.0;
            for ($q = 1; $q <= 40; $q++) {
                $tick = (($q - 1) * $ticksPerQuarter) + $reportingTick;
                $earningsEngine->calculate($stock, $macro, $tick, $ticksPerYear);
                $rev = (float) $stock->getTotalRevenue();
                if ($q === 1) $initialRev = $rev;
                $finalRev = $rev;
                if ($q % 10 === 0 || $q <= 5) {
                    echo sprintf(
                        "\n[%s] Q%02d: TotalRev=%.2fB | Equity=%.2fB | Treasury=%.2fB | InvestedCap=%.2fB | RoicTtm=%.4f | Margin=%.4f",
                        $ticker,
                        $q,
                        $rev / 1e9,
                        (float) $stock->getTotalEquity() / 1e9,
                        (float) $stock->getCorporateTreasury() / 1e9,
                        $stock->getInvestedCapital() / 1e9,
                        (float) $stock->getRoicTtm(),
                        (float) $stock->getOperatingMargin()
                    );
                }
            }

            $this->assertGreaterThan($initialRev * 0.50, $finalRev, "Revenue for $ticker should not collapse");
            $this->assertGreaterThan(0.05, (float) $stock->getOperatingMargin(), "Operating margin for $ticker should not decay to floor");
            $this->assertGreaterThan(0.0, (float) $stock->getTotalEquity(), "Equity for $ticker should remain positive");
        }
    }

    public function testInvestedCapitalEnforcesFiftyPercentEquityFloorEvenWithMassiveCash(): void
    {
        $stock = new Stock();
        $stock->setTicker('CASH_COW');
        $stock->setTotalEquity('50000000000.00'); // 50B
        $stock->setWholesaleDebt('10000000000.00'); // 10B
        $stock->setCustomerDeposits('0.00');
        $stock->setCorporateTreasury('120000000000.00'); // 120B (Massive cash hoard > Equity + Debt)

        // Without floor: 50B + 10B - 120B = -60B.
        // The 50% core business floor guarantees at least 50B * 0.50 = 25B
        $investedCapital = $stock->getInvestedCapital();
        $this->assertEquals(25000000000.00, $investedCapital);
    }
}
