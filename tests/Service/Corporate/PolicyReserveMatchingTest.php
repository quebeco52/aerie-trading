<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Data\Company\InitialMarket;
use App\DTO\EarningsSimulationContext;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Earnings\InvestmentMarkLedger;
use App\Service\Corporate\SecuritiesBookService;
use App\Service\Market\Bond\BondPricingEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Model\Sector\InsuranceBusinessModel;
use App\Service\Model\Sector\LifeInsurerBusinessModel;
use App\Service\Model\Sector\ReinsuranceBusinessModel;
use App\Service\Model\Sector\RetailInsuranceBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Bonds held against life policy reserves are carried at amortized cost, as the reserves are, so a rate move marks
 * neither side of the matched book; the rest of a float keeps the default split.
 */
#[Group('unit')]
final class PolicyReserveMatchingTest extends TestCase
{
    private function stock(string $ticker): Stock
    {
        return (new Stock())->setTicker($ticker);
    }

    public function testTheHeldToMaturityShareIsTheLifeReservesShareOfTheFloat(): void
    {
        $this->assertSame(FinancialConstants::DEFAULT_HTM_BOOK_SHARE, (new ReinsuranceBusinessModel())->getDefaultSecuritiesHtmShare($this->stock('SAFE')));
        $this->assertEqualsWithDelta(1.0, (new LifeInsurerBusinessModel())->getDefaultSecuritiesHtmShare($this->stock('WELL')), 1e-12);

        $composite = new RetailInsuranceBusinessModel();
        $ratios = $composite->resolveReserveRatios($this->stock('DOVE'));
        $lifeShare = $ratios['life'] / array_sum($ratios);
        $this->assertEqualsWithDelta($lifeShare + ((1.0 - $lifeShare) * FinancialConstants::DEFAULT_HTM_BOOK_SHARE), $composite->getDefaultSecuritiesHtmShare($this->stock('DOVE')), 1e-12);
        $this->assertGreaterThan(0.85, $composite->getDefaultSecuritiesHtmShare($this->stock('DOVE')), 'Most of a composite\'s float is life reserves.');
    }

    /** A selloff marks the held-to-maturity share into the disclosure and only the rest into equity. */
    public function testOnlyTheAvailableForSaleShareOfTheMarkReachesEquity(): void
    {
        $ledger = new InvestmentMarkLedger(new SecuritiesBookService(new BondPricingEngine()));
        $strategy = new InsuranceBusinessModel();

        $equityMoves = [];
        foreach ([FinancialConstants::DEFAULT_HTM_BOOK_SHARE, 0.90] as $htmShare) {
            $stock = (new Stock())->setTicker('INSR')->setIndustry('Insurance - Property & Casualty')->setSecuritiesDuration(7.0)->setSecuritiesHtmShare($htmShare);
            $stock->setTotalEquity('100000000000');
            $stock->setCorporateTreasury('400000000000');
            $stock->setCustomerDeposits('400000000000');

            $ledger->rollForwardSecuritiesBook(new EarningsSimulationContext($stock, self::curve(0.0425), $strategy, 'insurance'));
            $opening = (float) $stock->getTotalEquity();
            for ($quarter = 0; $quarter < 8; $quarter++) {
                $ledger->rollForwardSecuritiesBook(new EarningsSimulationContext($stock, self::curve(0.0725), $strategy, 'insurance'));
            }

            $mark = (float) $stock->getUnrealizedSecuritiesMark();
            $this->assertLessThan(0.0, $mark);
            $this->assertEqualsWithDelta($mark * $htmShare, $stock->getUnrecognizedSecuritiesMark(), 1.0);
            $this->assertEqualsWithDelta($mark * (1.0 - $htmShare), $stock->getRecognizedSecuritiesMark(), 1.0);
            $equityMoves[] = ((float) $stock->getTotalEquity() - $opening) / $mark;
        }

        $this->assertEqualsWithDelta((1.0 - 0.90) / (1.0 - FinancialConstants::DEFAULT_HTM_BOOK_SHARE), $equityMoves[1] / $equityMoves[0], 1e-6);
    }

    /** A life insurer's seed opens with its whole book matched. */
    public function testTheLifeInsurerIsSeededWithoutANamedShare(): void
    {
        $rows = array_values(array_filter(InitialMarket::STOCKS, static fn(array $row): bool => $row['ticker'] === 'WELL'));
        $this->assertCount(1, $rows);
        $this->assertArrayNotHasKey('securities_htm_share', $rows[0], 'The share is the model\'s rule, not a seeded number.');
    }

    private static function curve(float $level): MacroStateDTO
    {
        return new MacroStateDTO(policyRate: $level - 0.0125, policyRateEma: $level - 0.0125, yield10y: $level, yield10yEma: $level, nsLevel: $level, nsBeta1: -0.0125, nsBaseTermPremium: 0.0125);
    }
}
