<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\BusinessModelInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Analysts expect the public quarter (OperatingStrategyInterface::measurePublicFinancials) and hear the rest, the
 * firm's own news, at their coverage visibility. That news has to average nothing, or the model carries a standing
 * surprise: under the firm's stationary draws, each model's actual quarter averages its public one within a percent
 * of revenue and half a point of cost ratio. Read at the quarter whose draws are all zero instead, an option-like term
 * (a carry hurdle, an incentive fee) left the estimate off its mean by up to 8%.
 */
final class PublicQuarterUnbiasedTest extends TestCase
{
    private const QUARTERS = 1_200;

    private const BURN_IN = 20;

    /** @return iterable<string, array{BusinessModelInterface, int}> */
    public static function models(): iterable
    {
        foreach (array_keys(Sectors::BUSINESS_MODELS) as $index => $identifier) {
            yield $identifier => [Sectors::getBusinessModelStrategy($identifier), $index];
        }
    }

    #[DataProvider('models')]
    public function testTheFirmsOwnNewsAveragesNothing(BusinessModelInterface $model, int $index): void
    {
        $macro = new MacroStateDTO(exchangeRateIndexEma: 100.0);
        $stock = (new Stock())->setTicker('PUBQ')->setBeta('1.0')->setTotalEquity('500000000');
        $draws = MathUtility::ownStream(1000 + $index);

        $revenueNews = [];
        $costNews = [];
        for ($quarter = 0; $quarter < self::QUARTERS; ++$quarter) {
            $public = $model->measurePublicFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $quarter % 4, (77 * $quarter) + $index);
            $actual = $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $draws, $quarter % 4);
            $stock->setEarningsMomentumZ($actual->streamZ);
            if ($quarter >= self::BURN_IN) {
                $revenueNews[] = ($actual->actualRevenue / max(1.0, $public->actualRevenue)) - 1.0;
                $costNews[] = $actual->clampedMargin - $public->clampedMargin;
            }
        }

        [$revenueMean, $revenueError] = self::meanAndError($revenueNews);
        [$costMean, $costError] = self::meanAndError($costNews);
        $this->assertLessThan(0.01 + (3.0 * $revenueError), abs($revenueMean), sprintf('Revenue news averages %+.4f (se %.4f).', $revenueMean, $revenueError));
        $this->assertLessThan(0.005 + (3.0 * $costError), abs($costMean), sprintf('Cost-ratio news averages %+.4f (se %.4f).', $costMean, $costError));
    }

    /**
     * @param list<float> $sample
     * @return array{0: float, 1: float}
     */
    private static function meanAndError(array $sample): array
    {
        $count = count($sample);
        $mean = array_sum($sample) / $count;
        $variance = array_sum(array_map(static fn(float $x): float => ($x - $mean) ** 2, $sample)) / $count;

        return [$mean, sqrt($variance / $count)];
    }
}
