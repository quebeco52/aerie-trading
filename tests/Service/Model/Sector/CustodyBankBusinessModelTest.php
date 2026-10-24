<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\CustodyBankBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class CustodyBankBusinessModelTest extends TestCase
{
    private CustodyBankBusinessModel $model;

    protected function setUp(): void
    {
        $this->model = new CustodyBankBusinessModel();
    }

    private function custodian(string $ticker = 'GEN_CUSTODY'): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);

        return $stock;
    }

    private function invoke(object $model, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($model, $method))->invoke($model, ...$args);
    }

    public function testServicingFeesAreMarkedToTheEquityMarket(): void
    {
        $rally = new MacroStateDTO(equityWealthRatio: 1.10, equityWealthTrend: 1.0);
        $slump = new MacroStateDTO(equityWealthRatio: 0.80, equityWealthTrend: 1.0);

        // A 10% rally lifts servicing fees by the fee elasticity, 3.3%, and a 20% fall takes 6.6% off them.
        $this->assertEqualsWithDelta(0.033, $this->invoke($this->model, 'resolveFeeVolumeShift', $this->custodian(), $rally), 1e-12);
        $this->assertEqualsWithDelta(-0.066, $this->invoke($this->model, 'resolveFeeVolumeShift', $this->custodian(), $slump), 1e-12);
        $this->assertSame(0.0, $this->invoke($this->model, 'resolveFeeVolumeShift', $this->custodian(), new MacroStateDTO()), 'no market reading, no mark');
    }

    public function testTheFundsBookIsMarkedToTheFundsSize(): void
    {
        $custodian = $this->custodian('EGRT');
        $share = $this->model->resolveFundCustodyShare($custodian);
        $this->assertGreaterThan(0.0, $share, 'the District custodian holds part of the fund');

        // The fund shrinking to 0.8x its opening size against the economy, with markets at trend.
        $drawn = new MacroStateDTO(equityWealthRatio: 1.0, equityWealthTrend: 1.0, sovereignFundToGdp: 0.8 * MacroConstants::OPENING_FUND_TO_GDP);
        $this->assertEqualsWithDelta($share * -0.2, $this->invoke($this->model, 'resolveFeeVolumeShift', $custodian, $drawn), 1e-12);
    }

    public function testInstitutionalCashRepricesAtTheCustodyBeta(): void
    {
        // The retail system's beta is ignored: institutional cash is paid its own.
        $this->assertEqualsWithDelta(0.05 * 0.75 * 0.823, $this->invoke($this->model, 'resolveDepositRate', 0.05, 0.30), 1e-12);
        $this->assertSame(CommercialBankBusinessModel::MIN_DEPOSIT_RATE, $this->invoke($this->model, 'resolveDepositRate', 0.0, 0.30));
    }

    public function testTheBookIsMostlySecuritiesSoTheCreditCycleBarelyReachesIt(): void
    {
        $bank = new CommercialBankBusinessModel();

        $this->assertSame(0.18, $this->model->getLoanShareOfEarningAssets());
        $this->assertEqualsWithDelta(
            $bank->getThroughTheCycleCreditLossRate() * 0.18 / $bank->getLoanShareOfEarningAssets(),
            $this->model->getThroughTheCycleCreditLossRate(),
            1e-12,
            'the same loan book, at the custodian\'s loan share',
        );
    }

    public function testLoanOriginationDoesNotMoveCustodyInterestIncome(): void
    {
        $tight = new MacroStateDTO(sloosTighteningIndexEma: 0.5, housingStartsIndexEma: 70.0);

        $this->assertLessThan(0.0, $this->invoke(new CommercialBankBusinessModel(), 'resolveInterestVolumeShift', $tight));
        $this->assertSame(0.0, $this->invoke($this->model, 'resolveInterestVolumeShift', $tight));
    }

    /** An EGRT-shaped custodian: institutional deposits, a tenth of them in cash, a thin wholesale layer. */
    private function egrtShaped(): Stock
    {
        return $this->custodian('EGRT')
            ->setTotalEquity('120000000000')
            ->setCustomerDeposits('870000000000')
            ->setWholesaleDebt('60000000000')
            ->setCorporateTreasury('87000000000')
            ->setBaselineRoe('0.13')
            ->setOperatingMargin('0.25')
            ->setFloatingDebtRatio('0.5');
    }

    /**
     * A custodian's securities are a short liquid book: a move at the long end alone leaves the rate they reprice at
     * where it was, while a deposit bank's mortgage-linked book, priced between the 5- and 10-year points, takes most
     * of it.
     */
    public function testTheSecuritiesSleeveRepricesAtTheCustodyTenor(): void
    {
        $opening = new MacroStateDTO();
        $longEnd = new MacroStateDTO(yield10yEma: $opening->yield10yEma + 0.01, yield30yEma: $opening->yield30yEma + 0.01);
        $bank = new CommercialBankBusinessModel();
        $securities = fn(CommercialBankBusinessModel $model, MacroStateDTO $macro): float => $this->invoke($model, 'resolveRepricingRates', $this->custodian(), $macro)['securities'];

        $this->assertEqualsWithDelta(0.0, $securities($this->model, $longEnd) - $securities($this->model, $opening), 1e-12);
        $this->assertEqualsWithDelta(
            0.01 * (CommercialBankBusinessModel::SECURITIES_REPRICING_YEARS - 5.0) / 5.0,
            $securities($bank, $longEnd) - $securities($bank, $opening),
            1e-12,
        );
    }

    /**
     * Its institutional cash reprices fast, so a custodian holds a book that does too: at its tenor the
     * deposit-matching line is met with some of its loans floating and some fixed. At the aggregate's 8.4 years not
     * even an all-floating loan book met it, and the struck loan spread went to -4%.
     */
    public function testTheCustodyBookMeetsTheMatchingLineWithAnInteriorFloatingShare(): void
    {
        $pricing = $this->invoke($this->model, 'resolveFranchisePricing', $this->egrtShaped());

        $this->assertGreaterThan(0.0, $pricing['floating_share']);
        $this->assertLessThan(1.0, $pricing['floating_share']);
        $this->assertGreaterThanOrEqual(0.0, $pricing['loan_spread']);
    }

    /**
     * Pricing a live book carried from before its tenors were stored was struck on the deposit bank's 8.4-year
     * securities; a custodian strikes it again, once, on its own, and keeps it from then on.
     */
    public function testPricingStruckOnTheDepositBanksTenorsIsStruckAgainOnce(): void
    {
        $legacy = [
            CommercialBankBusinessModel::STATE_FLOATING_LOAN_SHARE => 1.0,
            CommercialBankBusinessModel::STATE_LOAN_SPREAD => -0.0414,
            CommercialBankBusinessModel::STATE_FEE_YIELD => CustodyBankBusinessModel::NON_INTEREST_INCOME_TO_EARNING_ASSETS,
        ];
        $stock = $this->egrtShaped()->setEarningsMomentumZ($legacy);
        $restruck = $this->invoke($this->model, 'resolveFranchisePricing', $stock);
        $this->assertGreaterThanOrEqual(0.0, $restruck['loan_spread'], 'struck again on its own tenor');

        $stock->setEarningsMomentumZ($legacy + [
            CommercialBankBusinessModel::STATE_SECURITIES_REPRICING_YEARS => CustodyBankBusinessModel::SECURITIES_REPRICING_YEARS,
            CommercialBankBusinessModel::STATE_RESIDENTIAL_REPRICING_YEARS => CommercialBankBusinessModel::RESIDENTIAL_REPRICING_YEARS,
        ]);
        $this->assertSame(-0.0414, $this->invoke($this->model, 'resolveFranchisePricing', $stock)['loan_spread'], 'pricing carried with its tenors is kept');

        $bank = new CommercialBankBusinessModel();
        $this->assertSame(-0.0414, $this->invoke($bank, 'resolveFranchisePricing', $this->egrtShaped()->setTicker('DEPOSIT')->setEarningsMomentumZ($legacy))['loan_spread'], 'a deposit bank\'s was struck on its own tenors');
    }
}
