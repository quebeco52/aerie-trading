<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\CustodyBankBusinessModel;
use PHPUnit\Framework\TestCase;

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
}
