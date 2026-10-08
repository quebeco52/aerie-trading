<?php

declare(strict_types=1);

namespace App\Tests\Service\View;

use App\Data\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Market\Pricing\PolicyCapitalization;
use App\Service\Math\FinancialConstants;
use App\Service\View\LawExposureBuilder;
use PHPUnit\Framework\TestCase;

final class LawExposureBuilderTest extends TestCase
{
    /** The laws in force: a levy, middling extraction rules, the founding duty and a carbon price. */
    private const STANDING = [
        'corporateTax' => 0.0,
        'tariff' => 0.0,
        'laborGrowth' => 0.0,
        'mergerReviewLeniency' => 0.0,
        'greenBeltStringency' => 0.0,
        'carbonPrice' => 20.0,
        'extractionStringency' => 0.5,
        'stampDutyRate' => FinancialConstants::STAMP_DUTY_RATE,
        'bankLevyRate' => 0.001,
        'reserveDrawShare' => 0.0,
    ];

    public function testEachBankOwesTheLevyItsModelCharges(): void
    {
        $macro = new MacroStateDTO(bankLevyRate: self::STANDING['bankLevyRate']);
        $banks = [];
        for ($i = 0; $i < LawExposureBuilder::FIRMS_PER_LAW + 2; ++$i) {
            $banks[] = self::bank('BK' . $i, 1.0e9 * ($i + 1));
        }

        $levy = self::law(LawExposureBuilder::build($banks, $macro, self::STANDING, []), 'bankLevyRate');

        // Every bank is listed, not only the first few, and each owes what its own model charges it.
        $this->assertCount(count($banks), $levy['firms']);
        $this->assertSame(0, $levy['more']);
        foreach ($banks as $bank) {
            $row = self::row($levy, $bank->getTicker());
            $this->assertEqualsWithDelta(-Sectors::strategyFor($bank->getIndustry())->calculateAnnualBankLevy($bank, $macro), $row['now'], 1e-6);
            $this->assertEqualsWithDelta($row['now'] / (float) $bank->getTotalNetIncome(), $row['nowShare'], 1e-12);
        }
        $this->assertEqualsWithDelta(array_sum(array_column($levy['firms'], 'now')), $levy['total'], 1e-6);
        // Largest against its revenue first.
        $this->assertSame('BK' . (count($banks) - 1), $levy['firms'][0]['ticker']);
    }

    public function testOtherLawsListTheFirmsTheyMoveMostAgainstTheirRevenue(): void
    {
        $drillers = [];
        foreach ([0.30, 0.10, 0.50, 0.20, 0.40, 0.05, 0.60] as $i => $share) {
            $drillers[] = self::firm('DR' . $i, 'Oil & Gas E&P', [FinancialConstants::STATE_EXTRACTION_COST_SHARE => $share]);
        }

        $rules = self::law(LawExposureBuilder::build($drillers, new MacroStateDTO(), self::STANDING, []), 'extractionStringency');

        $this->assertSame(['DR6', 'DR2', 'DR4', 'DR0', 'DR3'], array_column($rules['firms'], 'ticker'));
        $this->assertSame(2, $rules['more']);
        // The rules in force cost every driller, and nothing is expected without a forecast.
        foreach ($rules['firms'] as $row) {
            $this->assertLessThan(0.0, $row['now']);
            $this->assertNull($row['change']);
        }
        $this->assertFalse($rules['expectedMoves']);
    }

    public function testTheExpectedLawMovesEarningsTheWayTheMarketPricesIt(): void
    {
        $macro = new MacroStateDTO();
        $broker = self::firm('BRKR', 'Brokerages', [FinancialConstants::STATE_STAMP_DUTY_TURNOVER_SHARE => 0.4]);
        $generator = self::firm('POWR', 'Utilities - Regulated Electric', [FinancialConstants::STATE_CARBON_POWER_EARNINGS_SHARE => 0.3]);
        $expected = ['stampDutyRate' => 2.0 * FinancialConstants::STAMP_DUTY_RATE, 'carbonPrice' => 40.0] + self::STANDING;

        $exposure = LawExposureBuilder::build([$broker, $generator], $macro, self::STANDING, $expected);

        // A higher duty thins the broker's trading; a higher carbon price lifts what the generator sells its power at.
        $duty = self::row(self::law($exposure, 'stampDutyRate'), 'BRKR');
        $this->assertLessThan(0.0, $duty['change']);
        $carbon = self::row(self::law($exposure, 'carbonPrice'), 'POWR');
        $this->assertGreaterThan(0.0, $carbon['now']);
        $this->assertGreaterThan(0.0, $carbon['change']);
        $this->assertTrue(self::law($exposure, 'carbonPrice')['expectedMoves']);

        // The change is the term the market prices into the firm's earnings (PolicyCapitalization::earningsGap()).
        $tax = Sectors::strategyFor('Brokerages')->getEffectiveTaxRate($macro->corporateTaxRate);
        $base = Sectors::strategyFor('Brokerages')->annualStampDutyTurnoverBase($broker);
        $gap = PolicyCapitalization::measure('stampDutyRate', $expected['stampDutyRate']) - PolicyCapitalization::measure('stampDutyRate', self::STANDING['stampDutyRate']);
        $this->assertEqualsWithDelta((1.0 - $tax) * $gap * $base, $duty['change'], 1e-6);
        $this->assertEqualsWithDelta($duty['change'] / (float) $broker->getTotalNetIncome(), $duty['changeShare'], 1e-12);
    }

    public function testALossMakingFirmHasNoShareOfEarnings(): void
    {
        $driller = self::firm('LOSS', 'Oil & Gas E&P', [FinancialConstants::STATE_EXTRACTION_COST_SHARE => 0.3])->setTotalNetIncome('-5000000');

        $row = self::row(self::law(LawExposureBuilder::build([$driller], new MacroStateDTO(), self::STANDING, ['extractionStringency' => 0.8] + self::STANDING), 'extractionStringency'), 'LOSS');

        $this->assertNull($row['nowShare']);
        $this->assertNull($row['changeShare']);
        $this->assertNotNull($row['change']);
    }

    public function testAFirmNoLawReachesIsInNoTable(): void
    {
        $exposure = LawExposureBuilder::build([self::firm('PLAIN', 'Oil & Gas E&P', [])], new MacroStateDTO(), self::STANDING, []);

        foreach ($exposure['laws'] as $law) {
            $this->assertSame([], $law['firms']);
        }
        $this->assertNull($exposure['corporateTax']);
    }

    public function testTheCorporateTaxScalesEveryFullyTaxedFirmsEarningsAlike(): void
    {
        $exposure = LawExposureBuilder::build([], new MacroStateDTO(), self::STANDING, ['corporateTax' => 0.02] + self::STANDING);

        $rate = MacroEngine::TARGET_CORPORATE_TAX_RATE;
        $this->assertEqualsWithDelta($rate, $exposure['corporateTax']['inForce'], 1e-12);
        $this->assertEqualsWithDelta($rate + 0.02, $exposure['corporateTax']['expected'], 1e-12);
        $this->assertEqualsWithDelta((1.0 - $rate - 0.02) / (1.0 - $rate) - 1.0, $exposure['corporateTax']['earnings'], 1e-12);
        $this->assertTrue($exposure['corporateTax']['moves']);
    }

    /**
     * @param array<string, float> $state
     */
    private static function firm(string $ticker, string $industry, array $state): Stock
    {
        return (new Stock())
            ->setTicker($ticker)
            ->setName($ticker . ' Holdings')
            ->setIndustry($industry)
            ->setTotalRevenue('100000000')
            ->setTotalNetIncome('10000000')
            ->setEarningsMomentumZ($state);
    }

    private static function bank(string $ticker, float $wholesale): Stock
    {
        return self::firm($ticker, 'Banks - Regional', [])
            ->setWholesaleDebt((string) $wholesale)
            ->setFloatingDebtRatio('0.5')
            ->setCustomerDeposits('2000000000')
            ->setRevolverDrawn('0');
    }

    /**
     * @param array{laws: list<array<string, mixed>>} $exposure
     * @return array<string, mixed>
     */
    private static function law(array $exposure, string $lever): array
    {
        foreach ($exposure['laws'] as $law) {
            if ($law['lever'] === $lever) {
                return $law;
            }
        }
        self::fail('No ' . $lever);
    }

    /**
     * @param array<string, mixed> $law
     * @return array<string, mixed>
     */
    private static function row(array $law, string $ticker): array
    {
        foreach ($law['firms'] as $row) {
            if ($row['ticker'] === $ticker) {
                return $row;
            }
        }
        self::fail('No ' . $ticker . ' under ' . $law['lever']);
    }
}
