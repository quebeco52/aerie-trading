<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Company\Sectors;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\TimeSeries;
use App\Service\Model\Sector\BaseFinancialBusinessModel;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\PrivateEquityBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every model's return reverts through the one trailing-return update in StandardBaseModelTrait; what differs between
 * models is only what the return is struck on, what it must earn, and the weight a new quarter carries.
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

    /** @return iterable<string, array{BaseFinancialBusinessModel, float}> */
    public static function quarterWeightProvider(): iterable
    {
        yield 'a bank folds the quarter in at the shared weight' => [new CommercialBankBusinessModel(), FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT];
        yield 'private equity folds it in at the same weight' => [new PrivateEquityBusinessModel(), FinancialConstants::TTM_SMOOTHING_NEW_WEIGHT];
    }

    /** The quarter is blended into the trailing ROE at the model's weight, then pulled toward cost of equity plus moat. */
    #[DataProvider('quarterWeightProvider')]
    public function testTheTrailingReturnTakesTheQuarterAtTheModelsWeight(BaseFinancialBusinessModel $model, float $quarterWeight): void
    {
        $stock = $this->lender(0.10);
        $costOfEquity = 0.11;

        // 750m on 10bn of equity is 30% annualised.
        $model->updateDynamicRoic($stock, 750_000_000.0, 0.0, 0.0, 0.21, 0.08, $costOfEquity);

        $blended = (0.30 * $quarterWeight) + (0.10 * (1.0 - $quarterWeight));
        $expected = $blended + TimeSeries::calculateReversionPull($blended, $costOfEquity, $model->getReversionSpeed() / $model::TTM_ROE_WEIGHT, $model->getMoatSpread());

        $this->assertEqualsWithDelta($expected, (float) $stock->getRoeTtm(), 1e-9);
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
}
