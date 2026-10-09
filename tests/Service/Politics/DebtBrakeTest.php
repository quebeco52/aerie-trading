<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\Politics\AerieCouncil;
use App\Data\Politics\AerieDiet as Diet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Politics\CouncilAppointments as Appointments;
use App\Service\Politics\DebtBrake;
use App\Service\Politics\MonetaryAuthority;
use App\Service\Politics\PoliticsEngine as Politics;
use App\Service\Politics\PoliticsState;
use PHPUnit\Framework\TestCase;

class DebtBrakeTest extends TestCase
{
    private const DT = 1.0 / 52.0;

    private static function opened(int $seed): PoliticsState
    {
        mt_srand($seed);
        $state = new PoliticsState();
        $math = new MathUtility();
        Appointments::advance($state, $math);
        MonetaryAuthority::advance($state, new MacroStateDTO(totalTime: $state->totalTime, policyRate: 0.03), self::DT, $math);

        return $state;
    }

    /** A councillor believes one published estimate, each as likely; the median of the nine is 87%. */
    public function testACouncillorBelievesOnePublishedEstimate(): void
    {
        $thresholds = DebtBrake::PUBLISHED_THRESHOLDS;
        $sorted = $thresholds;
        sort($sorted);
        $this->assertSame($sorted, $thresholds);
        $this->assertSame(0.87, $thresholds[intdiv(count($thresholds), 2)]);

        $counts = array_fill(0, count($thresholds), 0);
        for ($i = 0; $i < 9000; ++$i) {
            $line = DebtBrake::drawLine(($i + 0.5) / 9000.0);
            $counts[(int) array_search($line, array_unique($thresholds), true)]++;
        }
        $this->assertSame(1000, $counts[0], 'The lowest estimate is drawn a ninth of the time.');
        $this->assertSame(0.95, DebtBrake::drawLine(1.0));
        $this->assertSame(0.30, DebtBrake::drawLine(0.0));
    }

    /** Every councillor holds a line, the Council's is its median member's, and a Council that has not formed holds the 90% line. */
    public function testTheCouncilsLineIsItsMedianMembers(): void
    {
        $this->assertSame(MacroEngine::SOVEREIGN_RISK_DEBT_THRESHOLD, DebtBrake::councilLine(new PoliticsStateDTO()));

        $lines = [];
        foreach (range(1, 40) as $seed) {
            $state = self::opened($seed);
            $this->assertCount(AerieCouncil::SEATS, $state->councilDebtLines);
            foreach ($state->councilDebtLines as $line) {
                $this->assertContains($line, DebtBrake::PUBLISHED_THRESHOLDS);
            }
            $this->assertSame(Appointments::median($state->councilDebtLines), DebtBrake::councilLine($state));
            $this->assertSame(DebtBrake::councilLine($state), DebtBrake::councilLine(PoliticsStateDTO::fromState($state)));
            $lines[] = DebtBrake::councilLine($state);
        }
        $this->assertGreaterThan(1, count(array_unique($lines)), 'Councils differ in where they hold the line.');
    }

    /** A Council saved before it held lines gains them on its next tick, drawn from its seats and names, and keeps them. */
    public function testASavedCouncilGainsItsLines(): void
    {
        $state = self::opened(5);
        $lines = $state->councilDebtLines;
        $state->councilDebtLines = [];
        Appointments::advance($state, new MathUtility());
        $this->assertSame($lines, $state->councilDebtLines);
    }

    /** Each seating records the line the Council held before it, so the news can say when a newcomer moves it; over decades the line moves. */
    public function testASeatingRecordsTheLineBeforeIt(): void
    {
        $state = self::opened(9);
        $math = new MathUtility();
        $seatings = 0;
        $lines = [DebtBrake::councilLine($state)];
        while ($state->totalTime < 60.0) {
            $before = DebtBrake::councilLine($state);
            $state->totalTime += self::DT;
            Appointments::advance($state, $math);
            if ($state->lastCouncillorSeatedAt === $state->totalTime) {
                ++$seatings;
                $this->assertSame($before, $state->debtLineBeforeSeating);
            }
            $lines[] = DebtBrake::councilLine($state);
        }
        $this->assertGreaterThan(10, $seatings);
        $this->assertGreaterThan(1, count(array_unique($lines)), 'Turnover moves the line.');
    }

    /** The brake reads the Council's line: debt between two lines is held by the stricter Council and not by the looser. */
    public function testTheBrakeReadsTheCouncilsLine(): void
    {
        $positions = Diet::HOME_POSITIONS;
        $standing = Politics::standingLevers(new PoliticsStateDTO());
        $budget = static fn(float $line): array => Politics::budget([Diet::CIVIC], [], Diet::SEED_SEATS, $positions, $standing, 0.88, $line);

        $this->assertTrue($budget(0.87)['councilGuards']);
        $this->assertTrue($budget(0.87)['councilHeld']['publicSpending'], 'The stricter Council holds a spending rise.');
        $this->assertFalse($budget(0.90)['councilGuards']);
        $this->assertGreaterThan($standing['publicSpending'], $budget(0.90)['levers']['publicSpending'], 'The looser one lets it pass.');
    }
}
