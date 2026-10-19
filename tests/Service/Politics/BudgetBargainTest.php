<?php

declare(strict_types=1);

namespace App\Tests\Service\Politics;

use App\Data\Politics\AerieDiet as Diet;
use App\DTO\PoliticsStateDTO;
use App\Service\Politics\BudgetBargain;
use App\Service\Politics\PoliticsEngine as Politics;
use PHPUnit\Framework\TestCase;

class BudgetBargainTest extends TestCase
{
    /**
     * A question crossed end to end counts one, whichever question and however many levers carry it, so no question
     * outweighs another by the number of laws it touches.
     */
    public function testEachQuestionCrossedEndToEndCountsOne(): void
    {
        $planks = static fn(float $target, float $pace): array => ['debtTarget' => $target, 'debtPace' => $pace];
        $targets = array_column(Diet::DEBT_PLANKS, 'target');
        $paces = array_column(Diet::DEBT_PLANKS, 'pace');
        $low = $planks(min($targets), max($paces));
        $high = $planks(max($targets), min($paces));

        $this->assertEqualsWithDelta(1.0, BudgetBargain::separation(
            Politics::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::VANGUARD][Diet::AXIS_STATE]]) + $low,
            Politics::platform([Diet::AXIS_STATE => Diet::FIXED_POSITIONS[Diet::CIVIC][Diet::AXIS_STATE]]) + $high,
        ), 1e-12, 'The size of the state, its debt plank included.');
        foreach ([[Diet::AXIS_OPENNESS, -0.8, 0.8], [Diet::AXIS_COUNCIL, -0.8, 0.8], [Diet::AXIS_ENVIRONMENT, 0.0, 0.8]] as [$axis, $from, $to]) {
            $this->assertEqualsWithDelta(1.0, BudgetBargain::separation(
                Politics::platform([$axis => $from]) + $low,
                Politics::platform([$axis => $to]) + $low,
            ), 1e-12, $axis);
        }
    }

    /**
     * A cabinet without supporters, or whose supporters like its programme no less than the levers in force, enacts it
     * whole.
     */
    public function testAProgrammeEverySupporterPrefersPassesWhole(): void
    {
        [$standing, $proposal] = $this->standingAndProposal();

        $this->assertSame($proposal, BudgetBargain::winSetPoint($proposal, $standing, []));
        $this->assertSame($proposal, BudgetBargain::winSetPoint($proposal, $standing, [['programme' => $proposal, 'redLines' => []]]));
    }

    /** A supporter whose own programme is in force likes nothing better, so nothing passes. */
    public function testASupporterWhoseProgrammeIsInForceBlocksEverything(): void
    {
        [$standing, $proposal] = $this->standingAndProposal();

        $this->assertSame($standing, BudgetBargain::winSetPoint($proposal, $standing, [['programme' => $standing, 'redLines' => []]]));
    }

    /**
     * With one supporter the budget is the cabinet's programme drawn toward the supporter's until the supporter likes
     * it exactly as well as the levers in force: on the line between the two programmes, at the supporter's
     * indifference.
     */
    public function testOneSupporterGetsTheProgrammeDrawnToItsIndifference(): void
    {
        [$standing, $proposal] = $this->standingAndProposal();
        $supporter = $this->ordered(Politics::partyProgramme(Diet::CIVIC, Diet::HOME_POSITIONS));
        $this->assertGreaterThan(BudgetBargain::separation($standing, $supporter), BudgetBargain::separation($proposal, $supporter), 'The Civic Front likes the Vanguard\'s programme less than the founding levers.');

        $budget = BudgetBargain::winSetPoint($proposal, $standing, [['programme' => $supporter, 'redLines' => []]]);

        $this->assertEqualsWithDelta(BudgetBargain::separation($standing, $supporter), BudgetBargain::separation($budget, $supporter), 1e-9, 'The supporter is indifferent.');
        $this->assertEqualsWithDelta(
            BudgetBargain::separation($supporter, $proposal),
            BudgetBargain::separation($supporter, $budget) + BudgetBargain::separation($budget, $proposal),
            1e-9,
            'and the budget lies on the line between the two programmes.',
        );
    }

    /**
     * Two supporters on either side of the debt target in force freeze it lever by lever. Weighing the package, the one
     * that gains on the tax cut gives ground on debt for it, and the target moves the cabinet's way.
     */
    public function testSupportersOnBothSidesOfALeverTradeRatherThanFreezeIt(): void
    {
        $standing = Politics::standingLevers(new PoliticsStateDTO());
        $cut = 0.002;
        $proposal = ['corporateTax' => $standing['corporateTax'] - $cut, 'debtTarget' => $standing['debtTarget'] - 0.05] + $standing;
        $higherDebt = ['corporateTax' => $standing['corporateTax'] - (2.0 * $cut), 'debtTarget' => $standing['debtTarget'] + 0.05] + $standing;
        $lowerDebt = ['corporateTax' => $standing['corporateTax'] - (2.0 * $cut), 'debtTarget' => $standing['debtTarget'] - 0.05] + $standing;

        $budget = BudgetBargain::winSetPoint($proposal, $standing, [['programme' => $higherDebt, 'redLines' => []], ['programme' => $lowerDebt, 'redLines' => ['debtTarget']]]);

        $this->assertLessThan($standing['debtTarget'], $budget['debtTarget'], 'The target moves the cabinet\'s way.');
        $this->assertLessThan($standing['corporateTax'], $budget['corporateTax']);
        $this->assertLessThanOrEqual(BudgetBargain::separation($standing, $higherDebt) + 1e-12, BudgetBargain::separation($budget, $higherDebt), 'and the supporter wanting more debt is no worse off.');
    }

    /**
     * A supporter gives no ground on the question it owns however much the rest of the package offers it: the tax it
     * guards stays where it is in force, while the cabinet still gets what it gives on the rest.
     */
    public function testASupporterGivesNoGroundOnTheQuestionItOwns(): void
    {
        $standing = Politics::standingLevers(new PoliticsStateDTO());
        $proposal = ['corporateTax' => $standing['corporateTax'] - 0.004, 'carbonPrice' => $standing['carbonPrice'] + 40.0] + $standing;
        // It wants a higher tax and a much higher carbon price, so a package would trade the tax for the carbon price.
        $guard = ['corporateTax' => $standing['corporateTax'] + 0.002, 'carbonPrice' => $standing['carbonPrice'] + 80.0] + $standing;

        $traded = BudgetBargain::winSetPoint($proposal, $standing, [['programme' => $guard, 'redLines' => []]]);
        $this->assertLessThan($standing['corporateTax'], $traded['corporateTax'], 'Without a red line the tax is traded away.');

        $guarded = BudgetBargain::winSetPoint($proposal, $standing, [['programme' => $guard, 'redLines' => ['corporateTax']]]);
        $this->assertSame($standing['corporateTax'], $guarded['corporateTax'], 'On its own question it gives no ground.');
        $this->assertSame($proposal['carbonPrice'], $guarded['carbonPrice'], 'and the rest of the package passes.');
    }

    /**
     * A programme that asks a supporter for ground only on the question it owns, held to the red line, is the levers in
     * force, which the supporter accepts: every lever stays exactly where it is, with nothing left to work out.
     */
    public function testAProgrammeHeldToTheRedLinesAndAcceptedIsTheBudgetExactly(): void
    {
        $standing = Politics::standingLevers(new PoliticsStateDTO());
        $proposal = ['corporateTax' => $standing['corporateTax'] - 0.004] + $standing;
        $guard = ['corporateTax' => $standing['corporateTax'] + 0.002, 'carbonPrice' => $standing['carbonPrice'] + 80.0] + $standing;

        $this->assertSame(array_replace($proposal, $standing), BudgetBargain::winSetPoint($proposal, $standing, [['programme' => $guard, 'redLines' => ['corporateTax']]]));
    }

    /**
     * The levers in force stand, exactly, where the cabinet asks for the opposite of what its supporter wants, and
     * where two supporters pull opposite ways on what it asks: the only budget both accept is the one in force, which
     * no weighing of the supporters' terms reaches.
     */
    public function testAStandstillLeavesEveryLeverExactlyInForce(): void
    {
        $standing = Politics::standingLevers(new PoliticsStateDTO());
        $moved = static fn(float $carbon, float $tax): array => ['carbonPrice' => $standing['carbonPrice'] + $carbon, 'corporateTax' => $standing['corporateTax'] + $tax] + $standing;
        $supporter = static fn(float $carbon, float $tax): array => ['programme' => $moved($carbon, $tax), 'redLines' => []];

        $away = $moved(-40.0, 0.003);
        $this->assertSame(array_replace($away, $standing), BudgetBargain::winSetPoint($away, $standing, [$supporter(80.0, -0.006)]), 'The cabinet asks for the opposite.');

        $asked = $moved(40.0, -0.004);
        $this->assertSame(array_replace($asked, $standing), BudgetBargain::winSetPoint($asked, $standing, [$supporter(80.0, 0.0), $supporter(-30.0, 0.0)]), 'Its supporters pull opposite ways.');
    }

    /**
     * Over cabinets and supporters from all over the Diet, no supporter likes the budget less than the levers in force,
     * and the cabinet always gets at least as close to its programme as holding every lever inside every supporter's
     * range would leave it, a budget every supporter also accepts.
     */
    public function testNoSupporterIsWorseOffAndTheCabinetGetsNoLessThanLeverByLever(): void
    {
        mt_srand(7);
        $draw = static fn(): float => (mt_rand() / mt_getrandmax() * 2.0) - 1.0;
        for ($case = 0; $case < 200; $case++) {
            $position = static fn(): array => [Diet::AXIS_STATE => 0.7 * $draw(), Diet::AXIS_OPENNESS => 0.8 * $draw(), Diet::AXIS_COUNCIL => 0.8 * $draw(), Diet::AXIS_ENVIRONMENT => 0.8 * $draw()];
            $plank = static fn(): array => ['debtTarget' => 0.35 + (0.275 * ($draw() + 1.0)), 'debtPace' => 0.005 + (0.005 * $draw())];
            $proposal = $this->ordered(Politics::platform($position()) + $plank());
            $standing = $this->ordered(Politics::platform($position()) + $plank());
            $supporters = [];
            for ($s = 0, $n = 1 + ($case % 3); $s < $n; $s++) {
                $supporters[] = $this->ordered(Politics::platform($position()) + $plank());
            }

            $parties = array_map(static fn(array $programme, string $owner): array => ['programme' => $programme, 'redLines' => BudgetBargain::redLines($owner)], $supporters, array_slice(Diet::PARTIES, $case % 5, count($supporters)));
            $budget = BudgetBargain::winSetPoint($proposal, $standing, $parties);

            foreach ($parties as $party) {
                $this->assertLessThanOrEqual(BudgetBargain::separation($standing, $party['programme']) + 1e-9, BudgetBargain::separation($budget, $party['programme']), "Case {$case}: a supporter is no worse off.");
                foreach ($party['redLines'] as $lever) {
                    $this->assertLessThanOrEqual(abs($standing[$lever] - $party['programme'][$lever]) * (1.0 + 1e-12) + 1e-15, abs($budget[$lever] - $party['programme'][$lever]), "Case {$case}: {$lever} crosses no red line.");
                }
            }
            $this->assertLessThanOrEqual(BudgetBargain::separation($this->leverByLever($proposal, $standing, $supporters), $proposal) + 1e-9, BudgetBargain::separation($budget, $proposal), "Case {$case}: the package does no worse for the cabinet.");
        }
    }

    /**
     * Each lever held inside every supporter's range, a value no further from its own than the one in force: a budget
     * each supporter accepts, so never closer to the cabinet's programme than the package.
     *
     * @param array<string, float>       $proposal
     * @param array<string, float>       $standing
     * @param list<array<string, float>> $supporters
     * @return array<string, float>
     */
    private function leverByLever(array $proposal, array $standing, array $supporters): array
    {
        $budget = [];
        foreach ($proposal as $lever => $value) {
            $low = -INF;
            $high = INF;
            foreach ($supporters as $own) {
                $reach = abs($standing[$lever] - $own[$lever]);
                $low = max($low, $own[$lever] - $reach);
                $high = min($high, $own[$lever] + $reach);
            }
            $budget[$lever] = max($low, min($high, $value));
        }

        return $budget;
    }

    /**
     * @return array{0: array<string, float>, 1: array<string, float>} The founding levers and the Vanguard's programme.
     */
    private function standingAndProposal(): array
    {
        $standing = $this->ordered(Politics::standingLevers(new PoliticsStateDTO()));

        return [$standing, $this->ordered(Politics::partyProgramme(Diet::VANGUARD, Diet::HOME_POSITIONS))];
    }

    /**
     * @param array<string, float> $budget
     * @return array<string, float> Keyed in the order the Diet legislates.
     */
    private function ordered(array $budget): array
    {
        $ordered = [];
        foreach (array_keys(Politics::LEVER_FIELDS) as $lever) {
            $ordered[$lever] = $budget[$lever];
        }

        return $ordered;
    }
}
