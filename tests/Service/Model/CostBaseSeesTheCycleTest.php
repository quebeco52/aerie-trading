<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Model\BusinessModelInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The sticky committed cost base (EarningsEngine::resolveCommittedCostScale) reads activity as the root
 * macro_demand_shift plus resolveSectorActivityShift(). A model that zeroes the root shift to apply the cycle
 * per stream must hand the same activity back through resolveSectorActivityShift(), or its cost base never
 * sees a recession and never restructures.
 */
class CostBaseSeesTheCycleTest extends TestCase
{
    private const GAP = -0.03;

    /**
     * Models that staff to the opening workload of an order backlog: a fresh book reads normal whatever the
     * cycle, so each model's own test runs the book down instead.
     */
    private const BACKLOG_DRIVEN = [
        'defense_contractor',
        'specialty_industrial_machinery',
    ];

    /** Models whose volume does not read the domestic cycle, and why. */
    private const ACYCLICAL = [
        'biotech'            => 'prescription volume follows patents and approvals, not the gap',
        'mining'             => 'sells every tonne into a global pool at the going price',
        'oil_gas_producer'   => 'sells every barrel into a global pool at the going price',
    ];

    /** @return iterable<string, array{string, BusinessModelInterface}> */
    public static function models(): iterable
    {
        foreach (array_keys(Sectors::BUSINESS_MODELS) as $identifier) {
            yield $identifier => [$identifier, Sectors::getBusinessModelStrategy($identifier)];
        }
    }

    #[DataProvider('models')]
    public function testTheCostBaseSeesARecession(string $identifier, BusinessModelInterface $model): void
    {
        if (in_array($identifier, self::BACKLOG_DRIVEN, true)) {
            $this->markTestSkipped("{$identifier} staffs to its backlog; its own test runs the book down");
        }

        $stock = new Stock();
        $stock->setTicker('XXXX');
        $stock->setBeta('1.0');

        // A recession as the macro reports one: the gap, Okun's-law unemployment (~0.5pp per 1% of gap), and
        // the stress readings that come with it.
        $recession = new MacroStateDTO(
            outputGap: self::GAP,
            outputGapEma: self::GAP,
            unemploymentRate: MacroEngine::NATURAL_UNEMPLOYMENT - (0.5 * self::GAP),
            marketVolatilityEma: 0.25,
            macroCreditSpread: 2.0 * MacroEngine::BASE_CREDIT_SPREAD,
            macroCreditSpreadEma: 2.0 * MacroEngine::BASE_CREDIT_SPREAD,
            domesticDemandGapEma: self::GAP,
            foreignOutputGap: self::GAP,
            consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL + (MacroEngine::SENTIMENT_GAP_LOADING * self::GAP),
            corporateDefaultRate: 2.0 * MacroEngine::CORPORATE_DEFAULT_BASELINE,
            corporateDefaultRateEma: 2.0 * MacroEngine::CORPORATE_DEFAULT_BASELINE,
            manufacturingPmi: MacroEngine::PMI_BASELINE - 5.0,
            exchangeRateIndexEma: 100.0,
        );

        $root = $model->getMacroPhysics($stock, $recession)['macro_demand_shift'];
        $sector = $model->resolveSectorActivityShift($stock, $recession);
        $activity = $root + $sector;

        // One channel or the other: utilisation already reads the root shift, so a sector shift on top counts it twice.
        $this->assertFalse(
            abs($root) > 1e-12 && abs($sector) > 1e-12,
            "{$identifier} hands the cost base the cycle through both the root shift and resolveSectorActivityShift()"
        );

        if (isset(self::ACYCLICAL[$identifier])) {
            $this->assertEqualsWithDelta(0.0, $activity, 1e-12, "{$identifier} is listed as acyclical: " . self::ACYCLICAL[$identifier]);

            return;
        }

        $this->assertGreaterThan(1e-4, abs($activity), "{$identifier}'s cost base is blind to a 3% output gap");
    }
}
