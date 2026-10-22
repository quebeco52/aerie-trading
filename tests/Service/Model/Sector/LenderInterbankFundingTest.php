<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Model\Sector\BrokerageBusinessModel;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\CreditServicesBusinessModel;
use App\Service\Model\Sector\CustodyBankBusinessModel;
use App\Service\Model\Sector\InvestmentBankBusinessModel;
use App\Service\Model\Sector\PrivateBankBusinessModel;
use App\Service\Model\Sector\ShadowBankBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A lender's floating wholesale funding resets to the interbank rate, so an interbank freeze costs it in proportion to
 * that funding, while deposits pay the rate they have repriced to. Its franchise is calibrated on the same rate.
 */
final class LenderInterbankFundingTest extends TestCase
{
    /** The live run's peak smoothed interbank spread. */
    private const FREEZE_SPREAD = 0.0117;

    private const EQUITY = 20e9;
    private const WHOLESALE = 60e9;
    private const DEPOSITS = 140e9;
    private const FLOATING_RATIO = 0.40;
    private const CREDIT_SPREAD = 0.012;

    /** @return array<string, array{class-string<CommercialBankBusinessModel|BrokerageBusinessModel>, bool}> */
    public static function lenders(): array
    {
        return [
            'commercial bank' => [CommercialBankBusinessModel::class, true],
            'credit services' => [CreditServicesBusinessModel::class, true],
            'shadow bank' => [ShadowBankBusinessModel::class, false],
            'custody bank' => [CustodyBankBusinessModel::class, true],
            'private bank' => [PrivateBankBusinessModel::class, true],
            'brokerage' => [BrokerageBusinessModel::class, false],
            'investment bank' => [InvestmentBankBusinessModel::class, false],
        ];
    }

    /**
     * The charge carries the interbank spread on the floating wholesale and on nothing else: at the baseline spread
     * it is today's charge plus floating wholesale x 15bp, and a freeze adds floating wholesale x its premium.
     *
     * @param class-string<CommercialBankBusinessModel|BrokerageBusinessModel> $class
     */
    #[DataProvider('lenders')]
    public function testFloatingWholesalePaysTheInterbankSpreadAndDepositsDoNot(string $class, bool $takesDeposits): void
    {
        $model = new $class();
        $stock = $this->lender($takesDeposits);
        $charge = fn(float $interbankSpread): float => $this->charge($model, $stock, new MacroStateDTO(interbankLiquiditySpreadEma: $interbankSpread));
        $floatingWholesale = self::WHOLESALE * self::FLOATING_RATIO;

        $this->assertEqualsWithDelta($floatingWholesale * MacroConstants::INTERBANK_BASELINE_SPREAD, $charge(MacroConstants::INTERBANK_BASELINE_SPREAD) - $charge(0.0), 1.0);
        $this->assertEqualsWithDelta($floatingWholesale * (self::FREEZE_SPREAD - MacroConstants::INTERBANK_BASELINE_SPREAD), $charge(self::FREEZE_SPREAD) - $charge(MacroConstants::INTERBANK_BASELINE_SPREAD), 1.0);
    }

    /**
     * Struck at the opening macro, the franchise earns its structural return when its funding is charged as the engine
     * charges it: the calibration assumes the rate the lender pays, interbank spread included.
     *
     * @param class-string<CommercialBankBusinessModel> $class
     */
    #[DataProvider('depositLenders')]
    public function testTheFranchiseIsCalibratedOnTheFundingItIsCharged(string $class): void
    {
        $model = new $class();
        $opening = new MacroStateDTO();
        $stock = $this->lender(true);
        $stock->setCorporateTreasury((string) $model->calculateTargetOperatingCash(self::EQUITY, self::DEPOSITS, self::WHOLESALE));
        $call = static fn(string $method, mixed ...$args): float => (float) (new \ReflectionMethod($model, $method))->invoke($model, ...$args);

        $earningAssets = $model->resolveEarningAssets($stock);
        $pretax = ($earningAssets * ($call('resolveCalibrationYield', $stock, $opening) - $call('resolveOperatingCostToEarningAssets', $stock)))
            - $model->resolveThroughTheCycleCreditProvision($stock, $earningAssets)
            - $this->charge($model, $stock, $opening)
            + $call('resolveTreasuryIncome', $stock, $opening);

        $this->assertEqualsWithDelta($call('resolveCalibrationRoe', $stock, $opening), $pretax * (1.0 - $opening->corporateTaxRate) / self::EQUITY, 1e-9);
    }

    /** @return array<string, array{class-string<CommercialBankBusinessModel>}> */
    public static function depositLenders(): array
    {
        return [
            'commercial bank' => [CommercialBankBusinessModel::class],
            'credit services' => [CreditServicesBusinessModel::class],
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
        $netInterest = fn(CommercialBankBusinessModel $model, Stock $stock, MacroStateDTO $macro): float => ($model->resolveEarningAssets($stock) * $model->resolveInterestYield($stock, $macro)) - $this->charge($model, $stock, $macro);
        $freezeEffect = static fn(CommercialBankBusinessModel $model, Stock $stock): float => $netInterest($model, $stock, $freeze) - $netInterest($model, $stock, $calm);

        $this->assertEqualsWithDelta(-$premiumCost, $freezeEffect(new ShadowBankBusinessModel(), $this->lender(false)), 1.0, 'a shadow bank pays the premium on its repo');
        $this->assertEqualsWithDelta(-$premiumCost, $freezeEffect(new CreditServicesBusinessModel(), $this->lender(true)), 1.0, 'a card lender pays the premium on its wholesale');

        $businessLender = new class extends CommercialBankBusinessModel {
            protected function resolveLoanBookMix(Stock $stock): array
            {
                return ['residential' => 0.0, 'consumer' => 0.0, 'commercial_real_estate' => 0.0, 'business' => 1.0];
            }
        };
        $this->assertGreaterThan(0.0, $freezeEffect($businessLender, $this->lender(true)), 'a deposit-funded bank lending at the interbank rate gains');
    }

    /** A lender funded by deposits and wholesale notes, or by wholesale alone. */
    private function lender(bool $takesDeposits): Stock
    {
        $stock = (new Stock())->setTicker('LEND');
        $stock->setTotalEquity((string) self::EQUITY);
        $stock->setWholesaleDebt((string) self::WHOLESALE);
        $stock->setCustomerDeposits($takesDeposits ? (string) self::DEPOSITS : '0');
        $stock->setCorporateTreasury('10000000000');
        $stock->setBaselineRoe('0.15');
        $stock->setOperatingMargin('0.40');
        $stock->setFloatingDebtRatio((string) self::FLOATING_RATIO);
        $stock->setHistoricalFixedRate('0.045');
        $stock->setCreditSpread((string) self::CREDIT_SPREAD);

        return $stock;
    }

    /**
     * The interest the lender is charged at a macro state, as DebtEngine prices it: the fixed notes at the market
     * coupon the calibration assumes, the floating at the policy rate plus the credit spread, on the funded book.
     */
    private function charge(CommercialBankBusinessModel|BrokerageBusinessModel $model, Stock $stock, MacroStateDTO $macro): float
    {
        $fundedDebt = (float) $stock->getWholesaleDebt() + (float) $stock->getCustomerDeposits();

        return $model->calculateInterestExpenseAndWholesaleRate(
            $stock,
            $macro->yield5yEma + self::CREDIT_SPREAD,
            $macro->policyRateEma + self::CREDIT_SPREAD,
            $macro->yield5yEma + self::CREDIT_SPREAD,
            $macro->policyRateEma,
            15.0,
            self::EQUITY,
            $fundedDebt,
            $macro,
        )->interestExpense;
    }
}
