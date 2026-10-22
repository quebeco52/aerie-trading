<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Vacancy, provisions, restructuring work and a lender's losses answer the default rates against the level they have
 * measurably settled at, not the constant the default process is built on. The rates average below those constants,
 * so a term read against them booked a standing gain or loss in every quiet year.
 */
final class DefaultRateTrendTest extends TestCase
{
    /**
     * @return iterable<string, array{0: class-string<OperatingStrategyInterface>, 1: string, 2: bool}> Model, ticker, whether the term reads both ways.
     */
    public static function readers(): iterable
    {
        yield 'reit vacancy' => [Sector\ReitBusinessModel::class, 'TRND', true];
        yield 'mall tenants' => [Sector\ResortsCasinosBusinessModel::class, 'GULL', true];
        yield 'captive auto loans' => [Sector\AutoManufacturerBusinessModel::class, 'TRND', true];
        yield 'conglomerate float' => [Sector\ConglomerateBusinessModel::class, 'TRND', true];
        yield 'law restructuring' => [Sector\LawFirmBusinessModel::class, 'TRND', true];
        yield 'bank loan book' => [Sector\CommercialBankBusinessModel::class, 'TRND', true];
        yield 'card book' => [Sector\CreditServicesBusinessModel::class, 'TRND', true];
        yield 'distressed opportunity' => [Sector\DistressedDebtBusinessModel::class, 'TRND', true];
        yield 'clearing member stress' => [Sector\ClearingHouseBusinessModel::class, 'TRND', true];
    }

    /**
     * At its trend a default rate is no news, wherever the trend sits against the constant: a rate that has settled
     * 40% over the constant moves earnings far less than the same rate read as 40% over its trend. (A lender's Vasicek
     * factor maps an average year to a slightly different loss at each level, so lenders are not exactly at zero.)
     *
     * @param class-string<OperatingStrategyInterface> $model
     */
    #[DataProvider('readers')]
    public function testARateAtItsTrendIsNoNewsWhereverTheTrendSits(string $model, string $ticker, bool $twoSided): void
    {
        $neutral = $this->ebit($model, $ticker, 1.0, 1.0);
        $settled = $this->ebit($model, $ticker, 1.4, 1.4) - $neutral;
        $excess = $this->ebit($model, $ticker, 1.4, 1.0) - $neutral;

        $this->assertNotEqualsWithDelta(0.0, $excess, 1e-6);
        $this->assertLessThan(0.25 * abs($excess), abs($settled));
    }

    /**
     * Each rate above its trend costs or pays; below it the term gives that back, unless it is an option on distress.
     *
     * @param class-string<OperatingStrategyInterface> $model
     */
    #[DataProvider('readers')]
    public function testTheTermReadsTheExcessOverTheTrend(string $model, string $ticker, bool $twoSided): void
    {
        $usual = $this->ebit($model, $ticker, 1.0, 1.0);
        $read = 0;
        foreach ([[1.3, 1.0, 0.7, 1.0], [1.0, 1.3, 1.0, 0.7]] as [$corporateBad, $retailBad, $corporateQuiet, $retailQuiet]) {
            $bad = $this->ebit($model, $ticker, 1.0, 1.0, $corporateBad, $retailBad) - $usual;
            if (abs($bad) < 1e-6) {
                continue; // the model does not read this rate
            }
            ++$read;
            $quiet = $this->ebit($model, $ticker, 1.0, 1.0, $corporateQuiet, $retailQuiet) - $usual;
            if ($twoSided) {
                $this->assertLessThan(0.0, $bad * $quiet, 'A quiet year moves earnings the other way.');
            } else {
                $this->assertEqualsWithDelta(0.0, $quiet, 1e-6, 'A quiet year leaves the option idle.');
            }
        }
        $this->assertGreaterThan(0, $read, 'The model reads at least one default rate.');
    }

    /**
     * Operating earnings after a lender's charge-offs and any provision beyond them, with both default rates at a
     * multiple of their constants (each scaled again on its own) and their trends at another.
     *
     * @param class-string<OperatingStrategyInterface> $model
     */
    private function ebit(string $model, string $ticker, float $rateMultiple, float $trendMultiple, float $corporateScale = 1.0, float $retailScale = 1.0): float
    {
        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn(0.0);
        $math->method('generateStandardNormal')->willReturn(0.0);
        $math->method('generateUniform')->willReturn(1.0);

        $macro = new MacroStateDTO(
            corporateDefaultRateEma: MacroConstants::CORPORATE_DEFAULT_LONG_RUN_MEAN * $rateMultiple * $corporateScale,
            retailDefaultRateEma: MacroConstants::RETAIL_DEFAULT_LONG_RUN_MEAN * $rateMultiple * $retailScale,
            corporateDefaultRateTrend: MacroConstants::CORPORATE_DEFAULT_LONG_RUN_MEAN * $trendMultiple,
            retailDefaultRateTrend: MacroConstants::RETAIL_DEFAULT_LONG_RUN_MEAN * $trendMultiple,
        );
        // A balance sheet a lender's book is sized on; the operating firms ignore it.
        $stock = (new Stock())->setTicker($ticker)->setBeta('1.0')->setTotalEquity('10000000000')
            ->setCustomerDeposits('80000000000')->setWholesaleDebt('10000000000')->setCorporateTreasury('5000000000');

        $result = (new $model())->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.0, $macro, $math);

        return $result->ebit - $result->creditLossProvision - $result->netChargeOffs;
    }
}
