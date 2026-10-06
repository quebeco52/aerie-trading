<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\AssetManagementBusinessModel;
use App\Service\Model\Sector\BrokerageBusinessModel;
use App\Service\Model\Sector\ClearingHouseBusinessModel;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\CreditServicesBusinessModel;
use App\Service\Model\Sector\HedgeFundBusinessModel;
use App\Service\Model\Sector\InvestmentBankBusinessModel;
use App\Service\Model\Sector\PrivateEquityBusinessModel;
use App\Service\Model\Sector\ShadowBankBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A balance-sheet firm's revenue capacity is struck at its structural return, never its trailing one. When
 * the trailing ROE was blended into the target, a loss lowered the next quarter's revenue target, which
 * deepened the loss: STRK's target fell from a 19.8% return to the 7.7% floor as its trailing ROE ran to
 * -50%, and it failed at the zero lower bound with a 13% capital ratio a year earlier.
 */
final class LenderRevenueCapacityTest extends TestCase
{
    /**
     * @return array<string, array{class-string<BusinessModelInterface>, string}>
     */
    public static function lenderProvider(): array
    {
        return [
            'commercial bank' => [CommercialBankBusinessModel::class, 'Banks - Diversified'],
            'credit services' => [CreditServicesBusinessModel::class, 'Credit Services'],
            'shadow bank'     => [ShadowBankBusinessModel::class, 'Mortgage Finance'],
            'investment bank' => [InvestmentBankBusinessModel::class, 'Investment Banking'],
            'brokerage'       => [BrokerageBusinessModel::class, 'Brokerages'],
            'asset manager'   => [AssetManagementBusinessModel::class, 'Asset Management'],
            'hedge fund'      => [HedgeFundBusinessModel::class, 'Hedge Fund'],
            'private equity'  => [PrivateEquityBusinessModel::class, 'Private Equity'],
            'clearing house'  => [ClearingHouseBusinessModel::class, 'Financial Clearinghouses'],
        ];
    }

    /**
     * @param class-string<BusinessModelInterface> $modelClass
     */
    #[DataProvider('lenderProvider')]
    public function testRevenueCapacityDoesNotFallWithTheTrailingReturn(string $modelClass, string $industry): void
    {
        $model = new $modelClass();
        $macro = MacroStateDTO::fromArray([
            'policy_rate' => 0.0,
            'policy_rate_ema' => 0.0,
            'equity_risk_premium' => 0.05,
            'yield_5y_ema' => 0.01,
            'nominal_gdp_index' => 1.0,
        ]);

        $capacity = function (float $baselineRoe, float $trailingRoe) use ($model, $macro, $industry): array {
            $stock = new Stock();
            $stock->setTicker('LEND');
            $stock->setIndustry($industry);
            $stock->setTotalEquity('100000000000');
            $stock->setWholesaleDebt('150000000000');
            $stock->setCustomerDeposits($model instanceof ShadowBankBusinessModel ? '0' : '500000000000'); // a shadow bank takes no deposits
            $stock->setCorporateTreasury('40000000000');
            $stock->setBaselineRoe((string) $baselineRoe);
            $stock->setOperatingMargin('0.35');
            $stock->setCreditSpread('0.0100');
            $stock->setFloatingDebtRatio('0.50');
            $stock->setSamRatio('0.10');
            $stock->setRoeTtm((string) $trailingRoe);

            return $model->getTargetMetrics($stock, $macro, new MathUtility());
        };

        $profitable = $capacity(0.45, 0.45);
        $collapsed = $capacity(0.45, -0.50);

        // The fixture sits above every floor: the structural return still moves capacity.
        $this->assertGreaterThan($capacity(0.35, 0.45)['baseline_roic'], $profitable['baseline_roic']);
        $this->assertEqualsWithDelta($profitable['baseline_roic'], $collapsed['baseline_roic'], 1e-12, 'A loss must not lower the return the next quarter\'s revenue is built on.');
        $this->assertEqualsWithDelta($profitable['invested_capital'], $collapsed['invested_capital'], 1e-3);
    }
}
