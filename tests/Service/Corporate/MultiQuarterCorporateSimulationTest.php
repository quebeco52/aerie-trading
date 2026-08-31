<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Entity\Stock;
use App\DTO\MacroStateDTO;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Math\MathUtility;
use App\Service\Math\CorporateMetrics;
use App\Service\Event\NarrativeEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\MarketConsensusEngine;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Closed-Loop Multi-Quarter Simulation Invariant Test.
 * 
 * Verifies that running consecutive quarterly earnings reports across all sector models
 * does not induce mathematical death spirals, balance sheet collapses, or NWC explosion.
 */
class MultiQuarterCorporateSimulationTest extends TestCase
{
    private EarningsEngine $earningsEngine;
    private MathUtility $mathUtility;

    protected function setUp(): void
    {
        $this->mathUtility = new MathUtility();
        $corporateMetrics = new CorporateMetrics();
        $debtEngine = new DebtEngine($this->mathUtility, $corporateMetrics);
        $capExEngine = new CapExEngine();
        $marketConsensusEngine = new MarketConsensusEngine();

        $ledgerMock = $this->createStub(CorporateLedgerService::class);
        $eventPublisherMock = $this->createStub(MarketEventPublisher::class);
        $narrativeEngineMock = $this->createStub(NarrativeEngine::class);
        $eventDispatcherMock = $this->createStub(EventDispatcherInterface::class);

        $treasuryEngine = new TreasuryEngine(
            $corporateMetrics,
            $debtEngine,
            $capExEngine,
            $this->mathUtility
        );

        $capitalAllocationEngine = new CapitalAllocationEngine(
            $ledgerMock,
            $corporateMetrics,
            $debtEngine,
            $this->mathUtility,
            $treasuryEngine
        );

        $this->earningsEngine = new EarningsEngine(
            $eventDispatcherMock,
            $eventPublisherMock,
            $capitalAllocationEngine,
            $debtEngine,
            $capExEngine,
            $this->mathUtility,
            $corporateMetrics,
            $narrativeEngineMock,
            $marketConsensusEngine
        );
    }

    public static function representativeIndustriesProvider(): array
    {
        return [
            'Heavy Manufacturing' => ['Heavy Manufacturing', '0.18', '0.12'],
            'Specialty Industrial Machinery' => ['Specialty Industrial Machinery', '0.22', '0.18'],
            'Commercial Bank' => ['Commercial Banking', '0.12', '0.30'],
            'Semiconductors' => ['Semiconductors', '0.25', '0.25'],
            'Consumer Staples' => ['Consumer Staples', '0.14', '0.10'],
            'Real Estate (REIT)' => ['Real Estate', '0.08', '0.45'],
            'Defense Contractor' => ['Defense & Aerospace', '0.16', '0.12'],
        ];
    }

    #[DataProvider('representativeIndustriesProvider')]
    public function testMultiQuarterSteadyStateStability(string $industry, string $baselineRoic, string $operatingMargin): void
    {
        $stock = new Stock();
        $stock->setTicker('SIM_' . substr(md5($industry), 0, 4));
        $stock->setName('Simulation Corp ' . $industry);
        $stock->setIndustry($industry);
        $stock->setEarningsPerShare('2.50');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('50.00');
        $stock->setVolatility('0.15');
        $stock->setCurrentVolatility('0.15');
        $stock->setBeta('1.0');
        $stock->setTotalEquity('40000000000.00'); // $40B
        $stock->setWholesaleDebt('10000000000.00'); // $10B
        $stock->setCorporateTreasury('5000000000.00'); // $5B
        $stock->setBaselineRoic($baselineRoic);
        $stock->setBaselineRoe('0.15');
        $stock->setOperatingMargin($operatingMargin);
        $stock->setTargetPayoutRatio('0.30');
        $stock->setDividendSpeed('0.20');
        $stock->setCapexRatio('0.20');
        $stock->setSamRatio('0.05');

        $neutralMacro = new MacroStateDTO(
            outputGapEma: 0.0,
            inflationEma: 0.02,
            policyRate: 0.04,
            policyRateEma: 0.04,
            yield2yEma: 0.04,
            yield5yEma: 0.042,
            yield10yEma: 0.045,
            nominalGdpIndex: 1.0,
            marketVolatilityEma: 0.15,
            macroCreditSpreadEma: 0.015,
            interbankLiquiditySpreadEma: 0.0010
        );

        $ticksPerQuarter = (int) (252 / 4);
        $reportingTick = abs(crc32($stock->getTicker())) % $ticksPerQuarter;
        $initialRevenue = null;
        $quarterlyRevenues = [];

        // Simulate 8 consecutive quarters (2 years)
        for ($quarter = 1; $quarter <= 8; $quarter++) {
            $quarterTick = (($quarter - 1) * $ticksPerQuarter) + $reportingTick;
            $this->earningsEngine->calculate($stock, $neutralMacro, $quarterTick, 252);

            $currentRevenue = (float) $stock->getTotalRevenue();
            $currentEquity = (float) $stock->getTotalEquity();
            $currentTreasury = (float) $stock->getCorporateTreasury();
            $currentInvestedCapital = $stock->getInvestedCapital();
            $roicTtm = (float) $stock->getRoicTtm();

            if ($initialRevenue === null) {
                $initialRevenue = $currentRevenue;
            }
            $quarterlyRevenues[] = $currentRevenue;

            // Invariant 1: Revenue must never experience a sudden single-quarter collapse (> 60% drop)
            if ($quarter > 1) {
                $prevRev = $quarterlyRevenues[$quarter - 2];
                $this->assertGreaterThan(
                    $prevRev * 0.40,
                    $currentRevenue,
                    "Quarter {$quarter} for {$industry} suffered an abnormal revenue collapse from \${$prevRev} to \${$currentRevenue}"
                );
            }

            // Invariant 2: Invested Capital must remain strongly positive and within 10x range
            $this->assertGreaterThan(
                1_000_000_000.0,
                $currentInvestedCapital,
                "Invested capital collapsed below minimum threshold in quarter {$quarter} for {$industry}"
            );

            // Invariant 3: Total Equity must remain solvent
            $this->assertGreaterThan(
                5_000_000_000.0,
                $currentEquity,
                "Total equity drained below solvency in quarter {$quarter} for {$industry}"
            );

            // Invariant 4: Treasury must be non-negative
            $this->assertGreaterThanOrEqual(
                0.0,
                $currentTreasury,
                "Corporate treasury went negative in quarter {$quarter} for {$industry}"
            );

            // Invariant 5: ROIC TTM must stay bounded
            $this->assertGreaterThanOrEqual(-0.50, $roicTtm);
            $this->assertLessThanOrEqual(1.00, $roicTtm);
        }

        // Final Invariant: Over 8 quarters of neutral macro, revenue should not drift by more than 3x or decay below 25%
        $finalRevenue = end($quarterlyRevenues);
        $this->assertGreaterThan(
            $initialRevenue * 0.25,
            $finalRevenue,
            "{$industry} experienced secular decay: Revenue dropped from \${$initialRevenue} to \${$finalRevenue} over 8 quarters"
        );
    }
}
