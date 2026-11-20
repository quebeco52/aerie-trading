<?php

declare(strict_types=1);

namespace App\Tests\Data\Company;

use App\Data\Company\CostStructure;
use App\Data\Company\IndustryFixedCosts;
use App\Data\Company\InitialMarket;
use App\Data\Company\ManagementStyle;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Market\Pricing\SeedRows;
use App\Service\Math\Decimal;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The cost-structure ladder and per-industry opening fixed cost shares: step offsets, full in-scope board coverage,
 * opening-book wiring (including the homebuilder working-capital covenant), and refusal of invalid seeds.
 */
#[Group('unit')]
final class IndustryFixedCostsTest extends TestCase
{
    public function testCostStructureStepsShiftFixedShareMonotonically(): void
    {
        $this->assertSame(0.10, CostStructure::STEP_OFFSET);

        $previous = -INF;
        foreach (CostStructure::cases() as $step) {
            $offset = $step->fixedShareOffset();
            $this->assertGreaterThan($previous, $offset, "{$step->value} must carry a higher fixed share offset than the step before it.");
            $this->assertNotEmpty($step->label());
            $previous = $offset;
        }

        $this->assertEqualsWithDelta(-0.20, CostStructure::VeryFlexible->fixedShareOffset(), 1e-12);
        $this->assertEqualsWithDelta(-0.10, CostStructure::Flexible->fixedShareOffset(), 1e-12);
        $this->assertSame(0.0, CostStructure::Typical->fixedShareOffset());
        $this->assertEqualsWithDelta(0.10, CostStructure::Rigid->fixedShareOffset(), 1e-12);
        $this->assertEqualsWithDelta(0.20, CostStructure::VeryRigid->fixedShareOffset(), 1e-12);

        $sprw = $this->rawRow('SPRW');
        $humm = $this->rawRow('HUMM');
        $this->assertSame(0.0, IndustryFixedCosts::forRow(['cost_structure' => CostStructure::VeryFlexible->value] + $sprw));
        $this->assertSame(0.95, IndustryFixedCosts::forRow(['cost_structure' => CostStructure::VeryRigid->value] + $humm));
    }

    public function testEveryInScopeBoardIndustryHasEntryAndSeedRowsPassValidation(): void
    {
        foreach (array_keys(IndustryFixedCosts::BY_INDUSTRY) as $industry) {
            $this->assertArrayHasKey($industry, Sectors::INDUSTRY_METRICS, "IndustryFixedCosts lists unknown industry {$industry}.");
            $share = IndustryFixedCosts::forIndustry($industry);
            $this->assertGreaterThanOrEqual(IndustryFixedCosts::MIN_FIXED_SHARE, $share);
            $this->assertLessThanOrEqual(IndustryFixedCosts::MAX_FIXED_SHARE, $share);
        }

        $inScope = 0;
        $outOfScope = 0;
        $steers = [];
        foreach (InitialMarket::STOCKS as $row) {
            $industry = is_string($row['industry'] ?? null) ? $row['industry'] : null;
            $strategy = Sectors::strategyFor($industry);
            if ($strategy->derivesOpeningFixedCostRatio()) {
                $inScope++;
                IndustryFixedCosts::assertRow($row);
                $this->assertArrayNotHasKey('fixed_cost_ratio', $row, "{$row['ticker']} states fixed_cost_ratio despite being in scope.");
                if (isset($row['cost_structure'])) {
                    $this->assertNotSame(CostStructure::Typical->value, $row['cost_structure'], "{$row['ticker']} states typical cost_structure; omit the default.");
                    $steers[$row['ticker']] = $row['cost_structure'];
                }
            } else {
                $outOfScope++;
                $this->assertArrayHasKey('fixed_cost_ratio', $row, "{$row['ticker']} must keep seeded fixed_cost_ratio.");
                $this->assertArrayNotHasKey('cost_structure', $row, "{$row['ticker']} is out of scope for cost_structure.");
            }
        }

        $this->assertSame(92, $inScope);
        $this->assertSame(11, $outOfScope);
        $this->assertSame([
            'SHRK' => CostStructure::Flexible->value,
            'TICK' => CostStructure::Rigid->value,
        ], $steers);

        // Pin key table entries (multi-firm medians, Investment Companies exception, CASC/BOWR ceilings).
        foreach ([
            'Aerospace & Defense' => 0.65,
            'Auto Manufacturers' => 0.45,
            'Building Products & Equipment' => 0.50,
            'Conglomerates' => 0.35,
            'Consulting Services' => 0.45,
            'Farm & Heavy Construction Machinery' => 0.55,
            'Financial Data & Ratings' => 0.70,
            'Investment Banking' => 0.50,
            'Investment Companies' => 0.35,
            'Marine Shipping' => 0.65,
            'Oil & Gas Refining & Marketing' => 0.025,
            'Railroads' => 0.80,
            'Residential Construction' => 0.10,
            'Restaurants' => 0.55,
            'Security & Protection Services' => 0.45,
            'Specialty Industrial Machinery' => 0.55,
            'Telecom Services' => 0.70,
        ] as $industry => $expectedShare) {
            $this->assertSame($expectedShare, IndustryFixedCosts::BY_INDUSTRY[$industry], $industry);
        }
    }

    public function testEveryInScopeFirmOpensWithItsDerivedFixedShare(): void
    {
        $openingMacro = new MacroStateDTO();
        $builder = $this->createBuilder();

        $board = [];
        $spheres = [];
        foreach (SeedRows::all() as $stockData) {
            $stock = (new Stock())->setTicker($stockData['ticker']);
            $sphere = $builder->open($stock, $stockData, $openingMacro);
            if ($sphere !== null) {
                $spheres[] = $sphere;
            }
            $board[$stockData['ticker']] = $stock;
        }
        $builder->openSpheres($spheres, $board, $openingMacro);

        $stepOffsets = [
            'very_flexible' => -0.20,
            'flexible' => -0.10,
            'typical' => 0.0,
            'rigid' => 0.10,
            'very_rigid' => 0.20,
        ];

        $inScopeCount = 0;
        $outOfScopeCount = 0;
        foreach (InitialMarket::STOCKS as $raw) {
            $ticker = $raw['ticker'];
            $stock = $board[$ticker];
            $strategy = Sectors::strategyFor($raw['industry']);
            if ($strategy->derivesOpeningFixedCostRatio()) {
                $inScopeCount++;
                $step = (string) ($raw['cost_structure'] ?? 'typical');
                $rawShare = IndustryFixedCosts::BY_INDUSTRY[$raw['industry']] + $stepOffsets[$step];
                $expected = max(0.0, min(0.95, round($rawShare, 6)));
                $this->assertEqualsWithDelta(
                    $expected,
                    $stock->getFixedCostRatio(),
                    1e-9,
                    "{$ticker} ({$raw['industry']}, {$step}) opened with unexpected fixed_cost_ratio.",
                );
            } else {
                $outOfScopeCount++;
                $this->assertEqualsWithDelta(
                    (float) $raw['fixed_cost_ratio'],
                    $stock->getFixedCostRatio(),
                    1e-9,
                    "{$ticker} (out of scope) must open with its seeded fixed_cost_ratio.",
                );
            }
        }

        $this->assertSame(92, $inScopeCount);
        $this->assertSame(11, $outOfScopeCount);
    }

    public function testLoreStepMovesOpenedFixedShareByStatedAmount(): void
    {
        $grip = $this->rawRow('GRIP');
        unset($grip['cost_structure']);
        $openingMacro = new MacroStateDTO();

        $openedTypical = $this->openResolved(SeedRows::resolve($grip, $openingMacro), $openingMacro);
        $openedFlexible = $this->openResolved(SeedRows::resolve(['cost_structure' => CostStructure::Flexible->value] + $grip, $openingMacro), $openingMacro);
        $openedRigid = $this->openResolved(SeedRows::resolve(['cost_structure' => CostStructure::Rigid->value] + $grip, $openingMacro), $openingMacro);

        $baseShare = IndustryFixedCosts::BY_INDUSTRY[$grip['industry']];
        $this->assertEqualsWithDelta($baseShare, $openedTypical->getFixedCostRatio(), 1e-9);
        $this->assertEqualsWithDelta($baseShare - 0.10, $openedFlexible->getFixedCostRatio(), 1e-9);
        $this->assertEqualsWithDelta($baseShare + 0.10, $openedRigid->getFixedCostRatio(), 1e-9);
    }

    public function testSeedRowsRefusesFixedCostRatioOnInScopeRowsAndUnknownCostStructure(): void
    {
        foreach (['WING', 'SHOR', 'BOWR', 'CASC', 'BRKW', 'KING', 'FINC', 'CROW', 'ACC', 'POOL'] as $ticker) {
            $row = $this->rawRow($ticker);
            try {
                SeedRows::resolve(['fixed_cost_ratio' => 0.40] + $row);
                $this->fail("Expected SeedRows::resolve() to refuse fixed_cost_ratio on {$ticker}.");
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $grip = $this->rawRow('GRIP');
        $lake = $this->rawRow('LAKE');
        $safe = $this->rawRow('SAFE');
        $peli = $this->rawRow('PELI');

        foreach ([
            ['cost_structure' => 'semi_fixed'] + $grip,
            ['cost_structure' => 1] + $grip,
            ['cost_structure' => 'semi_fixed'] + $lake,
            ['cost_structure' => CostStructure::Flexible->value] + $lake,
            ['cost_structure' => CostStructure::Rigid->value] + $safe,
            ['cost_structure' => CostStructure::Flexible->value] + $peli,
            ['industry' => 'General'] + $grip,
        ] as $badRow) {
            try {
                SeedRows::resolve($badRow);
                $this->fail('Expected SeedRows::resolve() to refuse invalid cost_structure or industry.');
            } catch (\DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        foreach (['LAKE', 'SAFE', 'DOVE', 'WELL', 'PELI'] as $ticker) {
            $raw = $this->rawRow($ticker);
            $resolved = SeedRows::resolve($raw);
            $this->assertSame($raw['fixed_cost_ratio'], $resolved['fixed_cost_ratio'], $ticker);
        }
    }

    public function testHomebuilderTrialStockUsesDerivedFixedCostRatioForCovenantBooks(): void
    {
        $openingMacro = new MacroStateDTO();
        $rawBowr = $this->rawRow('BOWR');
        $resolved = SeedRows::resolve($rawBowr, $openingMacro);

        $strategy = Sectors::strategyFor($rawBowr['industry']);
        $revenue = (float) $resolved['revenue'];
        $margin = (float) $resolved['operating_margin'];
        $taxRate = $strategy->getEffectiveTaxRate($openingMacro->corporateTaxRate);
        $depRate = (float) $resolved['depreciation_rate'];

        // Independently evaluate the EBITDA covenant at fixed_cost_ratio = 0.10 vs the entity fallback (0.35).
        $style = ManagementStyle::tryFromNullable($rawBowr['management_style'] ?? null);
        $covenantMultiple = $strategy->getLeverageTarget(
            Sectors::ebitdaCovenant($rawBowr['industry']) * min(1.0, (new Stock())->setManagementStyle($style)->getManagementProfile()->leverageBias()),
        );
        $equity = (float) $resolved['total_equity'];
        $debt = (float) $resolved['wholesale_debt'];
        $cash = (float) $resolved['corporate_treasury'];
        $investedCapital = max(0.5 * $equity, $equity + $debt - $cash);

        $trialStock = static function (float $fcr) use ($rawBowr, $style, $margin, $revenue, $equity, $debt, $cash, $resolved): Stock {
            $stock = (new Stock())
                ->setTicker('BOWR')
                ->setIndustry($rawBowr['industry'])
                ->setManagementStyle($style)
                ->setFixedCostRatio($fcr)
                ->setOperatingMargin(Decimal::format($margin, 4))
                ->setTotalRevenue(Decimal::format($revenue))
                ->setTotalEquity(Decimal::format($equity))
                ->setWholesaleDebt(Decimal::format($debt))
                ->setCorporateTreasury(Decimal::format($cash))
                ->setBaselineRoic((string) $resolved['baseline_roic'])
                ->setLongRunReturn((string) $resolved['baseline_roic']);
            CorporateMetrics::getInstance()->buildWorkingCapitalBalances(
                $stock,
                Sectors::strategyFor($rawBowr['industry'])->getWorkingCapitalDays($stock),
                $revenue,
                $revenue * (1.0 - $margin),
            );

            return $stock;
        };

        $derivedStock = $trialStock(IndustryFixedCosts::BY_INDUSTRY['Residential Construction']);
        $fallbackStock = $trialStock(0.35);

        $depDerived = max(0.0, $strategy->getPlantCapital($derivedStock, $investedCapital) - (float) $derivedStock->getNetWorkingCapital()) * $depRate;
        $depFallback = max(0.0, $strategy->getPlantCapital($fallbackStock, $investedCapital) - (float) $fallbackStock->getNetWorkingCapital()) * $depRate;

        $ebitdaDerived = (float) $strategy->getNormalEbitda($derivedStock, $taxRate, $depDerived);
        $ebitdaFallback = (float) $strategy->getNormalEbitda($fallbackStock, $taxRate, $depFallback);

        $capDerived = ($covenantMultiple * $ebitdaDerived) + $cash;
        $capFallback = ($covenantMultiple * $ebitdaFallback) + $cash;

        $this->assertEqualsWithDelta($capDerived, $debt, 1.0);
        $this->assertGreaterThan(10_000_000.0, abs($capDerived - $capFallback));
    }

    private function createBuilder(): OpeningBoardBuilder
    {
        mt_srand(20260929);
        $math = new MathUtility();

        return new OpeningBoardBuilder(
            $math,
            new DebtEngine($math, CorporateMetrics::getInstance()),
            new MarketEngine($math),
            new AnchorStakeLedger(),
        );
    }

    /** @param array<string, mixed> $resolvedRow */
    private function openResolved(array $resolvedRow, MacroStateDTO $macro): Stock
    {
        $stock = (new Stock())->setTicker($resolvedRow['ticker']);
        $this->createBuilder()->open($stock, $resolvedRow, $macro);

        return $stock;
    }

    /** @return array<string, mixed> */
    private function rawRow(string $ticker): array
    {
        foreach (InitialMarket::STOCKS as $row) {
            if ($row['ticker'] === $ticker) {
                return $row;
            }
        }

        $this->fail("No seed row for {$ticker}.");
    }
}
