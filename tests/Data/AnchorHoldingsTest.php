<?php

declare(strict_types=1);

namespace App\Tests\Data;

use App\Data\AnchorHoldings;
use App\Data\AnchorStake;
use App\Data\InitialMarket;
use App\Data\Sectors;
use App\Service\Model\Sector\InvestmentCompanyBusinessModel;
use PHPUnit\Framework\TestCase;

/**
 * The stake list is the one input the whole permanent-capital layer derives from, and it is edited by hand
 * in a file that knows nothing about the balance sheets, floats or business models it must agree with. NAV,
 * plant, stream mix and eleven other listings' floats all follow from it silently, so every way of getting
 * it wrong is checked here rather than discovered as a market that seeded strangely.
 *
 * Three have already happened: a retuned stake orphaned five float cuts, a declared sleeve claimed 60% of
 * book against stakes worth 51.5%, and a sphere carried a portfolio nobody had measured.
 */
class AnchorHoldingsTest extends TestCase
{
    /** Market-to-book at seed, used to size the portfolio without replaying it. The holdings run 2.5x-8.1x, average 3.98x. Seed and reset check the REAL prices, so they are the authority and this is the early warning. */
    private const REFERENCE_MARKET_TO_BOOK = 4.0;

    /** @return array<string, array<string, mixed>> ticker => seed row */
    private function seedRows(): array
    {
        $rows = [];

        foreach (InitialMarket::STOCKS as $row) {
            $rows[$row['ticker']] = $row;
        }

        return $rows;
    }

    /** A typo, or a retired company, silently stops the whole layer: the mark refuses and nothing says so. */
    public function testEveryDeclaredHoldingIsACompanyThatExists(): void
    {
        $rows = $this->seedRows();

        foreach (AnchorHoldings::STAKES as $holder => $stakes) {
            $this->assertArrayHasKey($holder, $rows, "{$holder} declares stakes but is not a listed company.");

            foreach (array_keys($stakes) as $held) {
                $this->assertArrayHasKey($held, $rows, "{$holder} declares a stake in {$held}, which is not listed.");
            }
        }
    }

    /** A sphere holding itself or another sphere needs look-through: a cap that already contains the mark is circular. */
    public function testNoSphereHoldsItselfOrAnotherSphere(): void
    {
        foreach (AnchorHoldings::STAKES as $holder => $stakes) {
            $this->assertArrayNotHasKey($holder, $stakes, "{$holder} holds itself.");

            foreach (array_keys($stakes) as $held) {
                $this->assertArrayNotHasKey(
                    $held,
                    AnchorHoldings::STAKES,
                    "{$holder} holds {$held}, which is itself a sphere — that needs look-through valuation."
                );
            }
        }
    }

    /** Only InvestmentCompanyBusinessModel reads these stakes; on any other firm they would be inert data. */
    public function testEveryHolderIsRunByTheModelThatReadsItsStakes(): void
    {
        $rows = $this->seedRows();

        foreach (array_keys(AnchorHoldings::STAKES) as $holder) {
            $industry = $rows[$holder]['industry'] ?? 'General';
            $strategy = Sectors::getBusinessModelStrategy(
                Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none'
            );

            $this->assertInstanceOf(
                InvestmentCompanyBusinessModel::class,
                $strategy,
                "{$holder} declares anchor stakes but is run by " . $strategy::class . ', which never reads them.'
            );
        }
    }

    /** Ownership and `public_float` are one fact, and the float is derived so they cannot drift. What is left to guard is that the subtraction leaves a real company behind. */
    public function testEveryHoldingKeepsATradableFloatAfterTheAnchorBlocksAreRemoved(): void
    {
        $anchored = [];

        foreach (InitialMarket::STOCKS as $row) {
            $ticker = $row['ticker'];
            $declared = (float) ($row['public_float'] ?? 0.90);
            $closelyHeld = AnchorHoldings::closelyHeldShare($ticker);
            $tradable = AnchorHoldings::tradableFloat($ticker, $declared);

            if ($closelyHeld <= 0.0) {
                $this->assertSame($declared, $tradable, "{$ticker} holds no anchor block, so its float must be untouched.");
                continue;
            }

            $anchored[] = $ticker;

            // Checked BEFORE the derivation, because tradableFloat() clamps at zero: past the declared
            // float the two stop agreeing, and reporting that would name the clamp, not the stake list.
            $this->assertLessThanOrEqual(
                $declared,
                $closelyHeld + AnchorHoldings::MIN_TRADABLE_FLOAT,
                sprintf(
                    'The anchor blocks in %s take %.0f%% of a company whose declared float is %.0f%%.',
                    $ticker,
                    100.0 * $closelyHeld,
                    100.0 * $declared
                )
            );
            $this->assertGreaterThanOrEqual(
                AnchorHoldings::MIN_TRADABLE_FLOAT,
                $tradable,
                "{$ticker} has almost no float left once its anchor blocks are removed."
            );
            $this->assertEqualsWithDelta($declared - $closelyHeld, $tradable, 1e-9, $ticker);
        }

        // A stake in a ticker the float loop never sees locks away nothing, which is how a list starts lying.
        $declaredHoldings = [];

        foreach (AnchorHoldings::STAKES as $stakes) {
            $declaredHoldings = [...$declaredHoldings, ...array_keys($stakes)];
        }

        sort($declaredHoldings);
        sort($anchored);
        $this->assertSame(array_values(array_unique($declaredHoldings)), $anchored);
    }

    /**
     * A stake list large enough to swallow a sphere's balance sheet leaves its subsidiaries nothing and
     * their stream is never drawn — a trust that quietly stops having factories. Nothing in a tick can
     * cause it, since a mark moves capital employed and the stakes alike; only editing this file can.
     */
    public function testEveryDeclaredPortfolioFitsInsideItsSpheresOwnBalanceSheet(): void
    {
        $rows = $this->seedRows();

        foreach (AnchorHoldings::STAKES as $holder => $_) {
            $portfolio = 0.0;

            foreach (AnchorHoldings::forHolder($holder) as $held => $fraction) {
                $portfolio += $fraction * (float) ($rows[$held]['total_equity'] ?? 0.0) * self::REFERENCE_MARKET_TO_BOOK;
            }

            $investedCapital = \App\Service\Math\CorporateMetrics::getInstance()->calculateLiveInvestedCapital(
                (float) ($rows[$holder]['total_equity'] ?? 0.0),
                (float) ($rows[$holder]['wholesale_debt'] ?? 0.0) + (float) ($rows[$holder]['customer_deposits'] ?? 0.0),
                (float) ($rows[$holder]['corporate_treasury'] ?? 0.0)
            );

            $this->assertGreaterThan(0.0, $portfolio, "{$holder} declares stakes worth nothing.");
            $this->assertGreaterThanOrEqual(
                AnchorHoldings::MIN_CONSOLIDATED_SHARE,
                AnchorHoldings::consolidatedShare($portfolio, $investedCapital),
                sprintf(
                    '%s declares a portfolio worth %s against %s of capital employed; trim its stakes or raise its balance sheet.',
                    $holder,
                    number_format($portfolio / 1e9, 0) . 'B',
                    number_format($investedCapital / 1e9, 0) . 'B'
                )
            );
        }
    }

    /** The tier is the declaration; the fraction is derived from it, so the two can never disagree. */
    public function testEveryHoldingIsDeclaredAsAConvictionThatResolvesToItsFraction(): void
    {
        foreach (AnchorHoldings::STAKES as $holder => $_) {
            $tiers = AnchorHoldings::tiersForHolder($holder);
            $fractions = AnchorHoldings::forHolder($holder);

            $this->assertSame(array_keys($tiers), array_keys($fractions));
            $this->assertNotEmpty($tiers);

            foreach ($tiers as $ticker => $tier) {
                $this->assertSame($tier->fraction(), $fractions[$ticker], $ticker);
                // Past a half a holding would be consolidated line by line and stop being a stake at all.
                $this->assertLessThanOrEqual(AnchorStake::Control->fraction(), $tier->fraction(), $ticker);
                $this->assertGreaterThan(0.0, $tier->fraction(), $ticker);
            }
        }
    }

    /** A firm that holds nothing is the overwhelming majority, and every accessor has to say so plainly. */
    public function testAFirmThatHoldsNothingDeclaresNothing(): void
    {
        $this->assertSame([], AnchorHoldings::forHolder('HUMM'));
        $this->assertSame([], AnchorHoldings::tiersForHolder('HUMM'));
        $this->assertSame(0.0, AnchorHoldings::closelyHeldShare('HUMM'));
        $this->assertSame(0.90, AnchorHoldings::tradableFloat('HUMM', 0.90));
    }

    /** The residual is a share of capital employed, and a firm with none has no sleeve to report. */
    public function testTheConsolidatedShareIsBoundedAndSafeOnAnEmptyBalanceSheet(): void
    {
        $this->assertSame(0.0, AnchorHoldings::consolidatedShare(100.0, 0.0));
        $this->assertSame(0.0, AnchorHoldings::consolidatedShare(200.0, 100.0));
        $this->assertSame(0.5, AnchorHoldings::consolidatedShare(50.0, 100.0));
        $this->assertSame(1.0, AnchorHoldings::consolidatedShare(0.0, 100.0));
    }
}
