<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\BusinessModelInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Management's pre-announcement projects the quarter now closing from the lag state the last report persisted
 * and today's input prices (EarningsEngine::evaluatePreAnnouncement). That is foreknowledge only if it is the
 * gap the report then books: every model either projects exactly what its next quarter books, or, because it
 * books no input-cost drag at all, projects nothing.
 */
#[Group('unit')]
class InputCostProjectionTest extends TestCase
{
    /** @return iterable<string, array{string, BusinessModelInterface}> */
    public static function models(): iterable
    {
        foreach (array_keys(Sectors::BUSINESS_MODELS) as $identifier) {
            yield $identifier => [$identifier, Sectors::getBusinessModelStrategy($identifier)];
        }
    }

    #[DataProvider('models')]
    public function testTheProjectionIsWhatTheNextReportBooks(string $identifier, BusinessModelInterface $model): void
    {
        $stock = new Stock();
        $stock->setTicker('PROJ');
        $stock->setBeta('1.0');
        $stock->setVolatility('0.20');
        $stock->setTotalRevenue('4000000000');
        $stock->setOperatingMargin('0.10');
        $stock->setTotalEquity('5000000000');
        $stock->setWholesaleDebt('2000000000');
        $stock->setCorporateTreasury('500000000');

        // The last report, booked in calm input markets, opens whatever state the model carries.
        mt_srand(11);
        $opening = $model->computeActualFinancials($stock, 1e9, 0.6, 2e8, 0.2, new MacroStateDTO(), new MathUtility());
        $stock->setEarningsMomentumZ($opening->streamZ);

        // The quarter now closing, into a metals and producer-price shock.
        $shock = new MacroStateDTO(industrialMetalsIndexEma: 300.0, producerPriceInflationEma: 0.08, naturalGasPriceIndexEma: 180.0);
        $projected = $model->projectInputCostGap($stock, $shock);

        mt_srand(12);
        $closing = $model->computeActualFinancials(clone $stock, 1e9, 0.6, 2e8, 0.2, $shock, new MathUtility());
        $booked = $closing->streamZ;

        if (!isset($booked[FinancialConstants::STATE_INPUT_COST_LEVEL])) {
            $this->assertNull($projected, "{$identifier} books no input-cost drag, so it has no squeeze to project");

            return;
        }

        // Exact for a model whose recovered share is fixed; a defence book's cost-plus share moves with its order
        // mix, so its management projects on last quarter's mix and lands within a hair of the booked gap.
        $this->assertNotNull($projected, "{$identifier} books an input-cost drag its management could have projected");
        $this->assertEqualsWithDelta(
            $booked[FinancialConstants::STATE_INPUT_COST_LEVEL] - ($booked[FinancialConstants::STATE_INPUT_COST_RECOVERY] ?? 0.0),
            $projected,
            1e-4,
            "{$identifier}: the projected gap is the one the report books",
        );

        // And in cost-ratio terms, on the base the model charges the gap to (a refiner's opex share, not its crude).
        $drag = static fn(array $state): float => (float) $state[FinancialConstants::STATE_INPUT_COST_DRAG_BASE]
            * ((float) $state[FinancialConstants::STATE_INPUT_COST_LEVEL] - (float) ($state[FinancialConstants::STATE_INPUT_COST_RECOVERY] ?? 0.0));
        $this->assertEqualsWithDelta(
            $drag($booked) - $drag($opening->streamZ),
            (float) $model->projectInputCostDragChange($stock, $shock),
            1e-4,
            "{$identifier}: the projected drag change is the one the report books",
        );
    }
}
