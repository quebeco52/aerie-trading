<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\AbstractBusinessModel;
use App\Service\Model\Sector\AdvertisingAgencyBusinessModel;
use App\Service\Model\Sector\ApparelManufacturingBusinessModel;
use App\Service\Model\Sector\BrokerageBusinessModel;
use App\Service\Model\Sector\ExchangeOperatorBusinessModel;
use App\Service\Model\Sector\HeavyManufacturingBusinessModel;
use App\Service\Model\Sector\SecurityProtectionBusinessModel;
use App\Service\Model\Sector\SpecialtyIndustrialMachineryBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Revenue lines that drift against their industry (TimeSeries::driftedLineShares()): a firm with its model's typical
 * mix grows at the industry's measured rate, one whose mix leans toward a drifting line grows by the lean times the
 * drift, and each quarter splits its revenue by the drifted mix at its date.
 */
#[Group('unit')]
final class LineDriftTest extends TestCase
{
    /**
     * Each model with drifting lines: the industry's measured rate, a firm whose lore leans its mix, that firm's lean
     * times each drift, the line the drift grows, and the drifts.
     *
     * @return iterable<string, array{AbstractBusinessModel, float, string, float, string, array<string, float>}>
     */
    public static function models(): iterable
    {
        $measured = static fn(float $share1997, float $share2019): float => MacroConstants::TREND_REAL_GROWTH
            + MacroTransmission::gdpShareDrift($share1997, $share2019, FinancialConstants::SECULAR_SHARE_WINDOW_YEARS);

        yield 'security, cash-heavy carrier' => [new SecurityProtectionBusinessModel(), $measured(SecurityProtectionBusinessModel::SECULAR_SHARE_1997, SecurityProtectionBusinessModel::SECULAR_SHARE_2019), 'NCKR', 0.75 * -0.02, 'vaults', SecurityProtectionBusinessModel::LINE_DRIFTS];
        yield 'advertising, data-heavy agency' => [new AdvertisingAgencyBusinessModel(), $measured(AdvertisingAgencyBusinessModel::SECULAR_SHARE_1997, AdvertisingAgencyBusinessModel::SECULAR_SHARE_2019), 'LYRE', ((0.20 - 0.50) * -0.02) + ((0.30 - 0.15) * 0.04), 'martech_consulting', AdvertisingAgencyBusinessModel::LINE_DRIFTS];
        yield 'brokerage, trading-heavy broker' => [new BrokerageBusinessModel(), MacroConstants::TREND_REAL_GROWTH, 'FINC', ((0.75 - 0.60) * -0.02) + ((0.25 - 0.40) * 0.03), 'advisory', BrokerageBusinessModel::LINE_DRIFTS];
        yield 'exchange, the industry mix' => [new ExchangeOperatorBusinessModel(), MacroConstants::TREND_REAL_GROWTH, 'ROOK', 0.0, 'market_data', ExchangeOperatorBusinessModel::LINE_DRIFTS];
        yield 'apparel, the industry mix' => [new ApparelManufacturingBusinessModel(), $measured(ApparelManufacturingBusinessModel::SECULAR_SHARE_1997, ApparelManufacturingBusinessModel::SECULAR_SHARE_2019), 'SHER', 0.0, 'dtc_retail', ApparelManufacturingBusinessModel::LINE_DRIFTS];
        yield 'heavy machinery, aftermarket-heavy maker' => [new HeavyManufacturingBusinessModel(), $measured(HeavyManufacturingBusinessModel::SECULAR_SHARE_1997, HeavyManufacturingBusinessModel::SECULAR_SHARE_2019), 'CHUF', (0.65 - 0.35) * 0.015, 'aftermarket_mro', HeavyManufacturingBusinessModel::LINE_DRIFTS];
        yield 'specialty machinery, services-heavy maker' => [new SpecialtyIndustrialMachineryBusinessModel(), $measured(SpecialtyIndustrialMachineryBusinessModel::SECULAR_SHARE_1997, SpecialtyIndustrialMachineryBusinessModel::SECULAR_SHARE_2019), 'RIVE', (0.60 - 0.30) * 0.015, 'aftermarket_services', SpecialtyIndustrialMachineryBusinessModel::LINE_DRIFTS];
    }

    /** The industry's measured rate already holds its typical mix's drifts, so only a firm's lean adds to it. */
    #[DataProvider('models')]
    public function testOnlyTheFirmsLeanFromTheTypicalMixMovesItsGrowth(AbstractBusinessModel $model, float $industryRate, string $ticker, float $lean, string $growingLine, array $drifts): void
    {
        $this->assertEqualsWithDelta($industryRate, $model->getSecularGrowthRate((new Stock())->setTicker('NONE')), 1e-15);
        $this->assertEqualsWithDelta($industryRate + $lean, $model->getSecularGrowthRate((new Stock())->setTicker($ticker)), 1e-12);
    }

    /**
     * The quarter's weights are the drifted mix at its date: the opening mix at the open, and twenty years on a larger
     * share for the line that grows.
     */
    #[DataProvider('models')]
    public function testTheQuarterSplitsRevenueByTheDriftedMix(AbstractBusinessModel $model, float $industryRate, string $ticker, float $lean, string $growingLine, array $drifts): void
    {
        $stock = static fn(): Stock => (new Stock())->setTicker($ticker)->setBeta('1.0');
        $weightsAt = static fn(float $years): array => $model->computeActualFinancials($stock(), 1000.0, 0.35, 100.0, 0.10, new MacroStateDTO(totalTime: $years), MathUtility::ownStream(11))->streamZ;
        $planAt = static fn(float $years): array => self::lineShares($model, $stock(), $years);

        $opening = array_filter($planAt(0.0), static fn(float $share): bool => $share > 0.0);
        foreach ($weightsAt(0.0) as $key => $weight) {
            if (str_starts_with((string) $key, 'weight:')) {
                $this->assertEqualsWithDelta($opening[substr((string) $key, 7)] ?? 0.0, $weight, 1e-12);
            }
        }

        $later = $weightsAt(20.0);
        $this->assertEqualsWithDelta($planAt(20.0)[$growingLine], $later["weight:{$growingLine}"], 1e-12);
        $this->assertGreaterThan($opening[$growingLine] ?? 0.0, $later["weight:{$growingLine}"]);
    }

    /**
     * The ledger grows a firm on its opening lean, faded; the exact mix-weighted path also gains as the growing line
     * takes a larger share. The two stay within 1% over 30 years for every firm shipped.
     */
    #[DataProvider('models')]
    public function testTheLedgersPlanStaysOnTheMixWeightedPath(AbstractBusinessModel $model, float $industryRate, string $ticker, float $lean, string $growingLine, array $drifts): void
    {
        $halfLife = FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS;
        $compound = static fn(array $shares): float => log(array_sum(array_map(
            static fn(string $line, float $share): float => $share * exp(TimeSeries::fadedExcessIntegral($drifts[$line] ?? 0.0, 0.0, 30.0, $halfLife)),
            array_keys($shares),
            $shares,
        )));
        $firm = self::lineShares($model, (new Stock())->setTicker($ticker), 0.0);
        $typical = self::lineShares($model, (new Stock())->setTicker('NONE'), 0.0);

        $exact = $compound($firm) - $compound($typical);
        $ledger = TimeSeries::fadedExcessIntegral(TimeSeries::lineMixExcessGrowth($firm, $typical, $drifts), 0.0, 30.0, $halfLife);
        $this->assertEqualsWithDelta($exact, $ledger, 0.01);
    }

    /** @return array<string, float> */
    private static function lineShares(AbstractBusinessModel $model, Stock $stock, float $years): array
    {
        self::assertTrue(method_exists($model, 'resolveLineShares'));

        return $model->resolveLineShares($stock, $years);
    }
}
