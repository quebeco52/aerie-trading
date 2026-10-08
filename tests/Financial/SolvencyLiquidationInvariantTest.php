<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Data\InitialMarket;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Entity\TradeOrder;
use App\Repository\TradeOrderRepository;
use App\Service\Corporate\DebtEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Corporate\FailureSweep;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Running out of cash is not the same thing as being worth less than you owe.
 *
 * A measured audit of the failure path found that 11 of 19 liquidations over 24,960 firm-years were payment
 * defaults rather than insolvencies, and 7 of those were scored Grey or Safe by the engine's own Altman test
 * at the moment it wiped them. The extreme case carried $851B of equity, $2.59T of retained earnings and a
 * $1,080 share price, and was liquidated at a -1.96% output gap because one quarter ended with an empty
 * treasury on the day a maturity landed. The flag that did it was set in one place and cleared in none.
 *
 * The property pinned here is the one a player experiences: a company whose balance sheet still covers the
 * claims on it is not wound up and its shareholders are not wiped, however badly its treasury is managed and
 * however long it has been missing payments. Liquidation is reserved for a business with no going-concern
 * value; an insolvent but viable one is reorganized. This runs the REAL failure path over every seeded
 * balance sheet rather than a stubbed score, so it also pins that the seed itself survives a liquidity crisis.
 */
#[AllowMockObjectsWithoutExpectations]
final class SolvencyLiquidationInvariantTest extends TestCase
{
    /** Quarters in default to test with: far past any cure period, so the grace clock cannot be what saves it. */
    private const UNCURED_QUARTERS = 8;

    private function buildOperator(): FailureSweep
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn(
            $this->createConfiguredStub(TradeOrderRepository::class, ['findOpenByTicker' => []])
        );
        $entityManager->method('getConnection')->willReturn($this->createStub(Connection::class));

        $publisher = $this->createStub(MarketEventPublisher::class);
        $publisher->method('publish')->willReturn([]);

        return new FailureSweep(
            $entityManager,
            $this->createStub(LoggerInterface::class),
            $publisher,
            new DebtEngine(new MathUtility(), CorporateMetrics::getInstance())
        );
    }

    /** The seed balance sheet, plus the structural parameters the solvency test reads. */
    private function seedFromRow(array $row): Stock
    {
        $stock = new Stock();
        $stock->setTicker($row['ticker']);
        $stock->setName($row['name']);
        $stock->setSector($row['sector']);
        $stock->setIndustry($row['industry']);
        $stock->setSharesOutstanding((string) $row['shares_outstanding']);
        $stock->setPrice('50.00');
        $stock->setVolatility((string) $row['volatility']);
        $stock->setCurrentVolatility((string) $row['volatility']);
        $stock->setBeta((string) $row['beta']);
        $stock->setOperatingMargin((string) $row['operating_margin']);
        $stock->setTotalEquity((string) $row['total_equity']);
        $stock->setWholesaleDebt((string) $row['wholesale_debt']);
        $stock->setCustomerDeposits((string) $row['customer_deposits']);
        $stock->setRetainedEarnings((string) $row['retained_earnings']);
        $stock->setCorporateTreasury((string) $row['corporate_treasury']);
        $stock->setFloatingDebtRatio((string) $row['floating_debt_ratio']);
        $stock->setHistoricalFixedRate((string) $row['historical_fixed_rate']);
        $stock->setCreditSpread((string) $row['credit_spread']);
        $stock->setDepreciationRate((string) $row['depreciation_rate']);
        $stock->setSamRatio((string) $row['sam_ratio']);

        // Revenue implied by the seed's own return and margin, so the Altman EBIT term is the firm's.
        $return = (float) ($row['baseline_roic'] ?? $row['baseline_roe'] ?? 0.10);
        $capital = (float) $row['total_equity'] + (float) $row['wholesale_debt'];
        $stock->setTotalRevenue((string) ($capital * $return / max(0.01, (float) $row['operating_margin'])));

        return $stock;
    }

    public function testAnEmptyTreasuryNeverLiquidatesASolventFirmAcrossInitialMarket(): void
    {
        $operator = $this->buildOperator();
        $macro = new MacroStateDTO();

        foreach (InitialMarket::STOCKS as $row) {
            $stock = $this->seedFromRow($row);

            // The worst liquidity position the engine can express: not a cent of cash, principal missed, and
            // the cure period long gone.
            $stock->setCorporateTreasury('0.00');
            $stock->setPaymentDefault(true);
            $stock->setQuartersInDefault(self::UNCURED_QUARTERS);

            $operator->sweep([$stock], $macro);

            $this->assertFalse(
                $stock->isBankrupt(),
                sprintf(
                    '%s was liquidated with $%.1fB of equity and $%.1fB of retained earnings because its treasury was empty',
                    $row['ticker'],
                    (float) $row['total_equity'] / 1e9,
                    (float) $row['retained_earnings'] / 1e9
                )
            );
            $this->assertGreaterThan(0.0, (float) $stock->getPrice(), "{$row['ticker']} had its equity wiped");
        }
    }

    /**
     * The other half of the invariant: the Chapter 7 path is intact. A business that no longer covers its cash
     * operating costs is still wound up once its default outlasts the cure period.
     */
    public function testABusinessThatNoLongerCoversItsCashCostsIsLiquidatedOnceItDefaults(): void
    {
        $stock = $this->buildInsolventShipper('GONE', '-0.40');
        $stock->setPaymentDefault(true);
        $stock->setQuartersInDefault(self::UNCURED_QUARTERS);

        $this->buildOperator()->sweep([$stock], new MacroStateDTO());

        $this->assertTrue($stock->isBankrupt(), 'a business with no going-concern value is liquidated');
        $this->assertSame('0.00000000', $stock->getPrice());
    }

    /**
     * Owing more than the business is worth is a reason to change who owns it, not to break it up. A viable
     * firm in uncured default is reorganized: it keeps trading, carries only the debt it can service, and the
     * rest of its creditors hold its equity.
     */
    public function testAnInsolventButViableFirmIsReorganizedNotWoundUp(): void
    {
        $stock = $this->buildInsolventShipper('SHIP', '0.08');
        $stock->setPaymentDefault(true);
        $stock->setQuartersInDefault(self::UNCURED_QUARTERS);

        $this->buildOperator()->sweep([$stock], MacroStateDTO::fromArray(['yield_5y_ema' => 0.04, 'policy_rate_ema' => 0.03, 'corporate_tax_rate' => 0.21]));

        $this->assertFalse($stock->isBankrupt(), 'a viable business is reorganized, not liquidated');
        $this->assertFalse($stock->isPaymentDefault(), 'confirmation cures the default');
        $this->assertLessThan(120_000_000_000.0, (float) $stock->getTotalDebt(), 'the debt it cannot carry was exchanged');
        $this->assertGreaterThan(0.0, (float) $stock->getTotalEquity(), 'the exchange recapitalized it');
        $this->assertGreaterThan(0.0, (float) $stock->getPrice(), 'and it keeps trading');
    }

    /**
     * A firm that is insolvent on paper but still paying what it owes is not wound up: an operating company
     * files when it cannot pay, and a negative Altman score is a forecast, not a verdict.
     */
    public function testAnInsolventFirmCurrentOnItsDebtsIsNotWoundUp(): void
    {
        $stock = $this->buildInsolventShipper('ZOMB', '-0.40');

        $this->buildOperator()->sweep([$stock], new MacroStateDTO());

        $this->assertFalse($stock->isBankrupt());
        $this->assertSame('0.20', $stock->getPrice());
    }

    private function buildInsolventShipper(string $ticker, string $margin): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setName('Shipping Holdings');
        $stock->setSector('Industrials');
        $stock->setIndustry('Marine Shipping');
        $stock->setSharesOutstanding('1000000000');
        $stock->setPrice('0.20');
        $stock->setOperatingMargin($margin);
        $stock->setTotalRevenue('10000000000.00');
        $stock->setTotalEquity('-40000000000.00');
        $stock->setRetainedEarnings('-90000000000.00');
        $stock->setWholesaleDebt('120000000000.00');
        $stock->setCorporateTreasury('0.00');
        $stock->setCreditSpread('0.02');

        return $stock;
    }
}
