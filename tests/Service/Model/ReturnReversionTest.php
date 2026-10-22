<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use App\Service\Math\TimeSeries;
use App\Service\Model\Sector\BaseFinancialBusinessModel;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\PrivateEquityBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Operating companies and underwriters revert their return through the one trailing-return update in
 * StandardBaseModelTrait, since their revenue targets read it; lenders and funds, whose targets are struck on their
 * structural ROE, carry the return they are measured to earn.
 */
class ReturnReversionTest extends TestCase
{
    private function lender(float $trailingRoe): Stock
    {
        $stock = new Stock();
        $stock->setTotalEquity('10000000000');
        $stock->setRoeTtm((string) $trailingRoe);

        return $stock;
    }

    /** @return iterable<string, array{BaseFinancialBusinessModel}> */
    public static function measuredStateProvider(): iterable
    {
        yield 'a bank' => [new CommercialBankBusinessModel()];
        yield 'private equity' => [new PrivateEquityBusinessModel()];
    }

    /**
     * A lender's or a fund's revenue is struck on its structural ROE, not on this state, so the state is the return it
     * is measured to earn: the quarter folded in at the shared weight, and no pull toward cost of equity plus moat.
     */
    #[DataProvider('measuredStateProvider')]
    public function testAFinancialsReturnStateIsTheReturnItEarns(BaseFinancialBusinessModel $model): void
    {
        $stock = $this->lender(0.10);

        // 750m on 10bn of equity is 30% annualised.
        $model->updateDynamicRoic($stock, 750_000_000.0, 0.0, 0.0, 0.21, 0.08, 0.11);

        $weight = FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT;
        $this->assertEqualsWithDelta((0.30 * $weight) + (0.10 * (1.0 - $weight)), (float) $stock->getRoeTtm(), 1e-9);

        // Held at 25% for ten years, the state is 25%: no fade its profits never make.
        $steady = $this->lender(0.25);
        for ($quarter = 0; $quarter < 40; $quarter++) {
            $model->updateDynamicRoic($steady, 625_000_000.0, 0.0, 0.0, 0.21, 0.08, 0.11);
        }
        $this->assertEqualsWithDelta(0.25, (float) $steady->getRoeTtm(), 1e-9);
    }

    /**
     * An underwriter's premium target reads the state, so it keeps the reversion: the quarter blended in, then pulled
     * toward cost of equity plus moat.
     */
    public function testAnUnderwritersReturnStateRevertsTowardItsRequiredReturn(): void
    {
        $model = new RetailInsuranceBusinessModel();
        $stock = $this->lender(0.10);
        $costOfEquity = 0.11;

        $model->updateDynamicRoic($stock, 750_000_000.0, 0.0, 0.0, 0.21, 0.08, $costOfEquity);

        $blended = (0.30 * FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT) + (0.10 * (1.0 - FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT));
        $expected = $blended + TimeSeries::calculateReversionPull($blended, $costOfEquity, $model->getReversionSpeed() / $model::TTM_ROE_WEIGHT, $model->getMoatSpread());

        $this->assertEqualsWithDelta($expected, (float) $stock->getRoeTtm(), 1e-9);
        $this->assertLessThan($blended, (float) $stock->getRoeTtm());
    }

    /** @return iterable<string, array{string}> */
    public static function financialModelProvider(): iterable
    {
        foreach (array_keys(Sectors::BUSINESS_MODELS) as $identifier) {
            if (Sectors::isFinancial($identifier)) {
                yield $identifier => [$identifier];
            }
        }
    }

    /** A quarter's return on a thin equity base is reported within the same bounds by every balance-sheet model. */
    #[DataProvider('financialModelProvider')]
    public function testEveryFinancialReportsItsReturnWithinTheSharedBounds(string $identifier): void
    {
        $model = Sectors::getBusinessModelStrategy($identifier);

        foreach ([[50_000_000_000.0, FinancialConstants::MAX_REPORTED_RETURN], [-50_000_000_000.0, FinancialConstants::MIN_REPORTED_RETURN]] as [$netIncome, $bound]) {
            $stock = $this->lender(0.10);
            $reported = $model->updateDynamicRoic($stock, $netIncome, 0.0, 0.0, 0.21);

            $this->assertSame($bound, $reported, "{$identifier} reports an unbounded return.");
            $this->assertSame($bound, (float) $stock->getCurrentRoe());
        }
    }

    /**
     * A fine or a write-down is one quarter's cost, not a lower return to come: the quarter reports it, and the return
     * state blends the quarter before it, as an operating company's blends EBIT before its special charges.
     */
    public function testAOneOffChargeIsReportedButNotCarriedIntoTheReturnState(): void
    {
        $model = new CommercialBankBusinessModel();
        $clean = $this->lender(0.12);
        $fined = $this->lender(0.12);
        $charge = 100_000_000.0;

        $model->updateDynamicRoic($clean, 300_000_000.0, 0.0, 0.0, 0.21, 0.08, 0.11);
        $model->updateDynamicRoic($fined, 300_000_000.0 - $charge, 0.0, 0.0, 0.21, 0.08, 0.11, null, 0.0, $charge);

        $this->assertSame($clean->getRoeTtm(), $fined->getRoeTtm());
        $this->assertEqualsWithDelta(0.04, (float) $clean->getCurrentRoe() - (float) $fined->getCurrentRoe(), 1e-12, 'the quarter reports the charge, 1% of equity annualised');
    }

    /**
     * A financial grown far past its market earns on the equity it has no use for what idle equity earns, the
     * money-market yield after tax, not its cost of equity, which no asset pays it.
     */
    public function testASaturatedFinancialEarnsWhatIdleEquityEarns(): void
    {
        $macro = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, nominalGdpIndex: 1.0);
        $lender = new Stock();
        $lender->setTicker('SATR');
        $lender->setIndustry('Credit Services');
        $lender->setBaselineRoe('0.15');
        $lender->setSamRatio('0.10');
        $lender->setSystemicImportance('default');
        $lender->setTotalEquity((string) (3.0 * CorporateMetrics::BASELINE_SECTOR_TAM * 0.10));

        $model = Sectors::strategyFor('Credit Services');
        $this->assertInstanceOf(BaseFinancialBusinessModel::class, $model);
        $structural = (new \ReflectionMethod($model, 'resolveStructuralTargetRoe'))->invoke($model, $lender, $macro);

        $this->assertEqualsWithDelta($macro->moneyMarketYield() * (1.0 - 0.21), $structural, 1e-12);
        $this->assertLessThan($macro->marketCostOfEquity(), $structural);
    }

    /** A bank grown far past its market gives back yield on its book down to what its equity would earn idle. */
    public function testASaturatedBankGivesBackYieldDownToWhatIdleEquityEarns(): void
    {
        $macro = new MacroStateDTO(policyRateEma: 0.04, corporateTaxRate: 0.21, nominalGdpIndex: 1.0);
        $bank = new Stock();
        $bank->setTicker('SATB');
        $bank->setIndustry('Banks - Regional');
        $bank->setBaselineRoe('0.15');
        $bank->setSamRatio('0.10');
        $bank->setSystemicImportance('default');
        $bank->setTotalEquity((string) (3.0 * CorporateMetrics::BASELINE_SECTOR_TAM * 0.10));
        $bank->setCustomerDeposits('2000000000000');
        $bank->setCorporateTreasury('100000000000');

        $model = new CommercialBankBusinessModel();
        $yield = (new \ReflectionMethod($model, 'resolveSaturationYield'))->invoke($model, $bank, $macro);
        $headroom = 0.15 - ($macro->moneyMarketYield() * (1.0 - 0.21));

        $this->assertEqualsWithDelta($headroom * $bank->getTangibleEquity() / ((1.0 - 0.21) * $model->resolveEarningAssets($bank)), $yield, 1e-12);
    }
}
