<?php

declare(strict_types=1);

namespace App\Tests\Service\Corporate;

use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * Negative earnings pre-announcements (Kasznik & Lev 1995, "To Warn or Not to Warn").
 *
 * The asymmetry is the finding: firms heading into a large negative surprise warn, firms heading into a
 * large positive one stay quiet. What management may honestly know before the close is what the firm is
 * already carrying — an accrual reversal owed back and cost that has reached the base but not yet the
 * price — both of which are persisted state, not a peek at draws that have not been made.
 */
#[AllowMockObjectsWithoutExpectations]
final class EarningsPreAnnouncementTest extends TestCase
{
    private const TICKS_PER_YEAR = 252;

    private EarningsEngine $engine;
    /** @var list<array{string, float}> */
    private array $published = [];

    protected function setUp(): void
    {
        $this->published = [];

        $publisher = $this->createStub(MarketEventPublisher::class);
        $publisher->method('publish')->willReturnCallback(
            function (mixed $asset, string $type, string $description, float $changePercent): array {
                $this->published[] = [$type, $changePercent];

                // Mirrors the real publisher: one event shape, which the caller wraps into a list.
                return ['type' => $type, 'ticker' => 'ZZZZ', 'description' => $description, 'change_percent' => $changePercent];
            }
        );

        $this->engine = (new ReflectionClass(EarningsEngine::class))->newInstanceWithoutConstructor();
        $marketEvent = new ReflectionProperty(EarningsEngine::class, 'marketEvent');
        $marketEvent->setValue($this->engine, $publisher);
    }

    private function makeStock(float $accrualBank = 0.0, float $costLevel = 0.0, float $recovered = 0.0): Stock
    {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setIndustry('Conglomerates');
        $stock->setTotalRevenue('4000000000');   // $1bn a quarter
        $stock->setTotalNetIncome('400000000');  // $100m of structural quarterly earnings
        $stock->setManagedAccrualBank($accrualBank);
        $stock->setEarningsMomentumZ([
            FinancialConstants::STATE_INPUT_COST_LEVEL => $costLevel,
            FinancialConstants::STATE_INPUT_COST_RECOVERY => $recovered,
        ]);

        return $stock;
    }

    private function warningTick(Stock $stock): int
    {
        return EarningsEngine::resolvePreAnnouncementTick($stock->getTicker(), self::TICKS_PER_YEAR);
    }

    /** A firm carrying a large unrecovered cost squeeze warns before the report. */
    public function testMaterialKnownShortfallProducesAWarning(): void
    {
        // 5% of quarterly revenue ($50m) unrecovered against $100m of quarterly earnings: a 50% shortfall.
        $stock = $this->makeStock(costLevel: 0.05, recovered: 0.0);

        $events = $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR);

        $this->assertNotEmpty($events, 'A shortfall this size is known before the close and gets warned.');
        $this->assertSame('GUIDANCE', $this->published[0][0]);
        $this->assertLessThan(0.0, $this->published[0][1], 'A warning is bad news and must move the price down.');
    }

    /** A small squeeze is absorbed silently rather than guided. */
    public function testImmaterialShortfallIsNotWarned(): void
    {
        $stock = $this->makeStock(costLevel: 0.002, recovered: 0.0);

        $this->assertSame([], $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR));
        $this->assertSame(0.0, $stock->getPreAnnouncedShortfall());
    }

    /** The asymmetry: good news is never pre-announced, only bad. */
    public function testFavourableConditionsAreNeverPreAnnounced(): void
    {
        // Selling prices have run well ahead of costs — a large positive surprise brewing.
        $stock = $this->makeStock(costLevel: 0.01, recovered: 0.10);

        $this->assertSame(
            [],
            $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR),
            'Kasznik & Lev: firms warn on bad news, not good.'
        );
    }

    /** Accrual reversals owed to earlier quarters are foreknowledge in their own right. */
    public function testOwedAccrualReversalAloneCanTriggerAWarning(): void
    {
        // A bank whose quarterly reversal exceeds the warning threshold against $100m of earnings.
        $bank = 100_000_000.0 / FinancialConstants::EARNINGS_MANAGEMENT_REVERSAL_RATE * 0.5;
        $stock = $this->makeStock(accrualBank: $bank);

        $this->assertNotEmpty(
            $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR),
            'A firm that papered over earlier quarters knows the reversal is coming.'
        );
    }

    /** Warnings only land in the pre-announcement window, not on arbitrary ticks. */
    public function testWarningOnlyFiresInItsWindow(): void
    {
        $stock = $this->makeStock(costLevel: 0.05);
        $offTick = ($this->warningTick($stock) + 5) % (int) (self::TICKS_PER_YEAR / 4);

        $this->assertSame([], $this->engine->evaluatePreAnnouncement($stock, $offTick, self::TICKS_PER_YEAR));
    }

    /** The warning lands before the report it refers to. */
    public function testWarningPrecedesTheReport(): void
    {
        $this->assertLessThan(
            EarningsEngine::resolveReportingTick('ZZZZ', self::TICKS_PER_YEAR),
            EarningsEngine::resolvePreAnnouncementTick('ZZZZ', self::TICKS_PER_YEAR),
            'A pre-announcement that arrives after the report is not a pre-announcement.'
        );
    }

    /** Analysts take the guided shortfall out of their estimate, which is the point of warning. */
    public function testWarningIsHandedToConsensus(): void
    {
        $stock = $this->makeStock(costLevel: 0.05);
        $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR);

        $this->assertGreaterThan(0.0, $stock->getPreAnnouncedShortfall(), 'The guided shortfall must reach the consensus estimate.');
    }

    /** A bankrupt shell issues no guidance. */
    public function testBankruptFirmIssuesNoGuidance(): void
    {
        $stock = $this->makeStock(costLevel: 0.05);
        $stock->setIsBankrupt(true);

        $this->assertSame([], $this->engine->evaluatePreAnnouncement($stock, $this->warningTick($stock), self::TICKS_PER_YEAR));
    }
}
