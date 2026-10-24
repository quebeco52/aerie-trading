<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\BrokerageBusinessModel;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\CreditServicesBusinessModel;
use App\Service\Model\Sector\CustodyBankBusinessModel;
use App\Service\Model\Sector\InvestmentBankBusinessModel;
use App\Service\Model\Sector\PrivateBankBusinessModel;
use App\Service\Model\Sector\ShadowBankBusinessModel;
use App\Tests\Support\OpeningCreditAtTrend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A lender's floating wholesale funding resets to the interbank rate, as every firm's floating debt does, so an
 * interbank freeze costs it in proportion to that funding, while deposits pay the rate they have repriced to. Its
 * franchise is struck on the funding DebtEngine charges it through the cycle: the fixed notes at the coupon they carry,
 * the floating at the reference rate plus its own spread at the credit trend.
 */
#[Group('unit')]
final class LenderInterbankFundingTest extends TestCase
{
    /** The live run's peak smoothed interbank spread. */
    private const FREEZE_SPREAD = 0.0117;

    private const EQUITY = 20e9;
    private const WHOLESALE = 60e9;
    private const DEPOSITS = 140e9;
    private const FLOATING_RATIO = 0.40;
    private const CREDIT_SPREAD = 0.012;
    /** A legacy coupon well under what the notes would roll to at the opening macro (the five-year plus ~1.2%). */
    private const LEGACY_COUPON = 0.030;

    /** @return array<string, array{class-string<CommercialBankBusinessModel|BrokerageBusinessModel>, string, bool}> */
    public static function lenders(): array
    {
        return [
            'commercial bank' => [CommercialBankBusinessModel::class, 'Banks - Regional', true],
            'credit services' => [CreditServicesBusinessModel::class, 'Credit Services', true],
            'shadow bank' => [ShadowBankBusinessModel::class, 'Mortgage Finance', false],
            'custody bank' => [CustodyBankBusinessModel::class, 'Custody Banks', true],
            'private bank' => [PrivateBankBusinessModel::class, 'Private Banks', true],
            'brokerage' => [BrokerageBusinessModel::class, 'Brokerages', false],
            'investment bank' => [InvestmentBankBusinessModel::class, 'Investment Banking', false],
        ];
    }

    /**
     * DebtEngine charges the interbank spread on the floating wholesale and on nothing else: at the baseline spread it
     * is the policy-rate charge plus floating wholesale x 15bp, and a freeze adds floating wholesale x its premium.
     */
    #[DataProvider('lenders')]
    public function testFloatingWholesalePaysTheInterbankSpreadAndDepositsDoNot(string $class, string $industry, bool $takesDeposits): void
    {
        $stock = $this->lender($industry, $takesDeposits);
        $charge = fn(float $interbankSpread): float => $this->engine()->calculateInterestExpense($stock, new MacroStateDTO(interbankLiquiditySpreadEma: $interbankSpread))->interestExpense;
        $floatingWholesale = self::WHOLESALE * self::FLOATING_RATIO;

        $this->assertEqualsWithDelta($floatingWholesale * MacroConstants::INTERBANK_BASELINE_SPREAD, $charge(MacroConstants::INTERBANK_BASELINE_SPREAD) - $charge(0.0), 1.0);
        $this->assertEqualsWithDelta($floatingWholesale * (self::FREEZE_SPREAD - MacroConstants::INTERBANK_BASELINE_SPREAD), $charge(self::FREEZE_SPREAD) - $charge(MacroConstants::INTERBANK_BASELINE_SPREAD), 1.0);
    }

    /**
     * Struck at the opening macro, the franchise earns its structural return when DebtEngine charges its funding there:
     * the fixed notes at the legacy coupon they carry, the floating at the reference rate plus its own spread.
     *
     * @param class-string<CommercialBankBusinessModel> $class
     */
    #[DataProvider('depositLenders')]
    public function testTheFranchiseIsCalibratedOnTheFundingItIsCharged(string $class, string $industry): void
    {
        $model = new $class();
        $stock = $this->lender($industry, true);
        $stock->setCorporateTreasury((string) $model->calculateTargetOperatingCash(self::EQUITY, self::DEPOSITS, self::WHOLESALE));
        $call = static fn(string $method, mixed ...$args): float => (float) (new \ReflectionMethod($model, $method))->invoke($model, ...$args);
        $opening = OpeningCreditAtTrend::macro();

        $earningAssets = $model->resolveEarningAssets($stock);
        $pretax = ($earningAssets * ($call('resolveCalibrationYield', $stock, $opening) - $call('resolveOperatingCostToEarningAssets', $stock)))
            - $model->resolveThroughTheCycleCreditProvision($stock, $earningAssets)
            - $this->engine()->calculateInterestExpense($stock, $opening)->interestExpense
            + $call('resolveTreasuryIncome', $stock, $opening);

        $this->assertEqualsWithDelta($call('resolveCalibrationRoe', $stock, $opening), $pretax * (1.0 - $opening->corporateTaxRate) / self::EQUITY, 1e-9);
    }

    /**
     * The legacy notes roll to the market coupon and the book matched to them runs off at the same pace, so a lender
     * that opens on its return target stays on it at the opening macro: struck on the legacy coupon alone, it would
     * sink below target by the coupon gap as the notes rolled.
     *
     * @param class-string<CommercialBankBusinessModel> $class
     */
    #[DataProvider('depositLenders')]
    public function testTheFranchiseHoldsItsReturnAsTheLegacyNotesRoll(string $class, string $industry): void
    {
        $model = new $class();
        $engine = $this->engine();
        $stock = $this->lender($industry, true);
        $stock->setCorporateTreasury((string) $model->calculateTargetOperatingCash(self::EQUITY, self::DEPOSITS, self::WHOLESALE));
        $opening = OpeningCreditAtTrend::macro();
        $call = static fn(string $method, mixed ...$args): float => (float) (new \ReflectionMethod($model, $method))->invoke($model, ...$args);
        $roe = function () use ($model, $engine, $stock, $opening, $call): float {
            $earningAssets = $model->resolveEarningAssets($stock);
            $feeYield = (new \ReflectionMethod($model, 'resolveFranchisePricing'))->invoke($model, $stock)['fee_yield'];
            $grossYield = $model->resolveInterestYield($stock, $opening) + $feeYield;
            $pretax = ($earningAssets * ($grossYield - $call('resolveOperatingCostToEarningAssets', $stock)))
                - $model->resolveThroughTheCycleCreditProvision($stock, $earningAssets)
                - $engine->calculateInterestExpense($stock, $opening)->interestExpense
                + $call('resolveTreasuryIncome', $stock, $opening);

            return $pretax * (1.0 - $opening->corporateTaxRate) / self::EQUITY;
        };
        $target = $call('resolveCalibrationRoe', $stock, $opening);
        $marketCoupon = $engine->calculateInterestExpense($stock, $opening)->currentMarketRate;

        $this->assertEqualsWithDelta($target, $roe(), 1e-9, 'opens on target');
        for ($quarter = 1; $quarter <= 20; $quarter++) {
            $result = $model->computeActualFinancials($stock, 1e9, 0.50, 2e8, 0.0, $opening, new MathUtility());
            $stock->setEarningsMomentumZ($result->streamZ);
            $engine->rollMaturities($stock, $engine->analyzeDebtHealth($stock, $opening), 0.0);
        }

        $this->assertLessThan(0.5 * ($marketCoupon - self::LEGACY_COUPON), $marketCoupon - (float) $stock->getHistoricalFixedRate(), 'most of the legacy gap has rolled');
        $this->assertEqualsWithDelta($target, $roe(), 1e-6, 'and the return has held');
    }

    /** @return array<string, array{class-string<CommercialBankBusinessModel>, string}> */
    public static function depositLenders(): array
    {
        return [
            'commercial bank' => [CommercialBankBusinessModel::class, 'Banks - Regional'],
            'credit services' => [CreditServicesBusinessModel::class, 'Credit Services'],
            'custody bank' => [CustodyBankBusinessModel::class, 'Custody Banks'],
            'private bank' => [PrivateBankBusinessModel::class, 'Private Banks'],
        ];
    }

    /**
     * What a freeze does to a lender follows its funding mix and what its book reprices at. A card book (prime) and a
     * shadow bank's direct loans (SOFR) do not reprice with the interbank spread, so the lender loses its floating
     * wholesale x the premium. A deposit-funded bank whose business loans reprice at the interbank rate gains.
     */
    public function testAFreezeCostsALenderByItsFundingMix(): void
    {
        $calm = new MacroStateDTO();
        $freeze = new MacroStateDTO(interbankLiquiditySpread: self::FREEZE_SPREAD, interbankLiquiditySpreadEma: self::FREEZE_SPREAD);
        $premiumCost = self::WHOLESALE * self::FLOATING_RATIO * (self::FREEZE_SPREAD - MacroConstants::INTERBANK_BASELINE_SPREAD);
        $netInterest = fn(CommercialBankBusinessModel $model, Stock $stock, MacroStateDTO $macro): float => ($model->resolveEarningAssets($stock) * $model->resolveInterestYield($stock, $macro)) - $this->engine()->calculateInterestExpense($stock, $macro)->interestExpense;
        $freezeEffect = static fn(CommercialBankBusinessModel $model, Stock $stock): float => $netInterest($model, $stock, $freeze) - $netInterest($model, $stock, $calm);

        $this->assertEqualsWithDelta(-$premiumCost, $freezeEffect(new ShadowBankBusinessModel(), $this->lender('Mortgage Finance', false)), 1.0, 'a shadow bank pays the premium on its repo');
        $this->assertEqualsWithDelta(-$premiumCost, $freezeEffect(new CreditServicesBusinessModel(), $this->lender('Credit Services', true)), 1.0, 'a card lender pays the premium on its wholesale');

        $businessLender = new class extends CommercialBankBusinessModel {
            protected function resolveLoanBookMix(Stock $stock): array
            {
                return ['residential' => 0.0, 'consumer' => 0.0, 'commercial_real_estate' => 0.0, 'business' => 1.0];
            }
        };
        $this->assertGreaterThan(0.0, $freezeEffect($businessLender, $this->lender('Banks - Regional', true)), 'a deposit-funded bank lending at the interbank rate gains');
    }

    /**
     * A lender funded by deposits and wholesale notes, or by wholesale alone, its fixed notes on a legacy coupon. It is
     * rated a class below the one its seeded spread sits in, so DebtEngine charges it a spread other than the seeded
     * one (1.79% against 1.20%), as the seed's re-rating does to BNTH.
     */
    private function lender(string $industry, bool $takesDeposits): Stock
    {
        $stock = (new Stock())->setTicker('LEND');
        $stock->setIndustry($industry);
        $stock->setTotalEquity((string) self::EQUITY);
        $stock->setWholesaleDebt((string) self::WHOLESALE);
        $stock->setCustomerDeposits($takesDeposits ? (string) self::DEPOSITS : '0');
        $stock->setCorporateTreasury('10000000000');
        $stock->setBaselineRoe('0.15');
        $stock->setOperatingMargin('0.40');
        $stock->setFloatingDebtRatio((string) self::FLOATING_RATIO);
        $stock->setHistoricalFixedRate((string) self::LEGACY_COUPON);
        $stock->setCreditSpread((string) self::CREDIT_SPREAD);
        $stock->setCreditRating('BBB');
        $stock->setVolatility('0.20');

        return $stock;
    }

    private function engine(): DebtEngine
    {
        return new DebtEngine(new MathUtility(), CorporateMetrics::getInstance());
    }
}
