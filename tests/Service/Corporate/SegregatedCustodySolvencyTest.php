<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\MathUtility;
use PHPUnit\Framework\TestCase;

/**
 * Client money does not belong in the denominator of a capital ratio.
 *
 * A clearinghouse holds member initial and variation margin, invests it and keeps the spread, but the pool
 * belongs to the members: the statutory default waterfall spends a defaulter's margin, then the guaranty
 * fund, before the CCP's own equity is ever exposed. It is neither capital the firm can lose nor a claim
 * its capital has to answer for. Leaving it in measured ACC's solvency against the size of its members'
 * margin pool — which SWELLS with volatility — so the score fell hardest in exactly the conditions the
 * clearinghouse exists to survive. A bank is the opposite case and the control below: it lends its deposits
 * out and owes them back out of its own estate, so they stay in the denominator.
 */
final class SegregatedCustodySolvencyTest extends TestCase
{
    private DebtEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new DebtEngine(new MathUtility(), new CorporateMetrics());
    }

    private function buildFirm(string $ticker, string $industry, string $customerLiabilities): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setIndustry($industry);
        $stock->setTotalEquity('50000000000');
        $stock->setWholesaleDebt('10000000000');
        $stock->setCustomerDeposits($customerLiabilities);
        $stock->setCorporateTreasury('20000000000');
        $stock->setRetainedEarnings('30000000000');
        $stock->setSharesOutstanding('1000000000');

        return $stock;
    }

    /**
     * The whole pool drops out of the denominator: a CCP carrying $1.3T of member margin scores exactly as
     * one carrying none, because in both cases the only assets its equity answers for are its own.
     */
    public function testAClearingHouseCapitalRatioExcludesTheMemberMarginPool(): void
    {
        $withPool = $this->buildFirm('ACC', 'Financial Clearinghouses', '1300000000000');
        $withoutPool = $this->buildFirm('ACC0', 'Financial Clearinghouses', '0');

        $pooled = $this->engine->calculateAltmanZScore($withPool, 4_000_000_000.0, 8_000_000_000.0, 60.0);
        $bare = $this->engine->calculateAltmanZScore($withoutPool, 4_000_000_000.0, 8_000_000_000.0, 60.0);

        $this->assertEqualsWithDelta(
            $bare['z_score'],
            $pooled['z_score'],
            0.0001,
            'member margin is bankruptcy-remote, so it may not move the CCP\'s capital ratio at all'
        );
        $this->assertSame('Safe', $pooled['zone']);
        $this->assertFalse($pooled['is_bankrupt']);
    }

    /**
     * The failure this fix was written for: margin requirements are raised INTO a panic, so the pool grows
     * exactly when the clearinghouse is most needed. Under the old denominator that growth alone impaired
     * the firm's measured solvency without a dollar of its own capital being lost.
     */
    public function testAVolatilitySpikeThatDoublesTheMarginPoolDoesNotImpairTheClearingHouse(): void
    {
        $calm = $this->buildFirm('ACC', 'Financial Clearinghouses', '1300000000000');
        $panic = $this->buildFirm('ACC', 'Financial Clearinghouses', '2600000000000');

        $calmScore = $this->engine->calculateAltmanZScore($calm, 4_000_000_000.0, 8_000_000_000.0, 60.0);
        $panicScore = $this->engine->calculateAltmanZScore($panic, 4_000_000_000.0, 8_000_000_000.0, 60.0);

        $this->assertEqualsWithDelta($calmScore['z_score'], $panicScore['z_score'], 0.0001);
        $this->assertFalse($panicScore['is_bankrupt'], 'a bigger margin pool is not a solvency event');
    }

    /**
     * Control: a deposit is not custody. The bank lends it out and owes it back out of its own estate, so
     * it belongs in the balance sheet its capital ratio is struck on and leverage still has to register.
     */
    public function testABankCapitalRatioStillCountsItsDeposits(): void
    {
        $funded = $this->buildFirm('BNK', 'Banks - Diversified', '1300000000000');
        $unfunded = $this->buildFirm('BNK0', 'Banks - Diversified', '0');

        $fundedScore = $this->engine->calculateAltmanZScore($funded, 4_000_000_000.0, 8_000_000_000.0, 60.0);
        $unfundedScore = $this->engine->calculateAltmanZScore($unfunded, 4_000_000_000.0, 8_000_000_000.0, 60.0);

        $this->assertLessThan(
            $unfundedScore['z_score'],
            $fundedScore['z_score'],
            'deposit funding is leverage for a bank and must dilute its capital ratio'
        );
        $this->assertSame('Distress', $fundedScore['zone'], '$50B of equity against $1.3T of deposits is a thin bank');
        $this->assertSame('Safe', $unfundedScore['zone'], 'the same equity carrying no deposit book is not');
    }
}
