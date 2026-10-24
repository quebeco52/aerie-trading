<?php

declare(strict_types=1);

namespace App\Tests\Data\Politics;

use App\Data\Politics\StanceWords;
use App\Service\Politics\Department\Exchequer;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\MonetaryAuthority;
use App\Service\Politics\Department\SecuritiesCommission;
use App\Service\Politics\Department\SovereignReserveFund;
use PHPUnit\Framework\TestCase;

/** The stance words: every stance a department can name has words in every form its pages print. */
final class StanceWordsTest extends TestCase
{
    /**
     * Each question's stance names, swept across the stance range the way its department names them, so a renamed or
     * added stance without words fails here rather than on a page.
     */
    public function testEveryNamedStanceHasWordsInEachFormItsPagesPrint(): void
    {
        $grid = array_map(static fn(int $i): float => -1.0 + ($i / 10.0), range(0, 20));
        $names = [
            StanceWords::MONEY => array_map(MonetaryAuthority::stanceName(...), $grid),
            StanceWords::BANKS => array_map(static fn(float $s): string => FinancialRegulator::stanceName(FinancialRegulator::requirement($s)), $grid),
            StanceWords::RESERVES => array_map(static fn(float $s): string => SovereignReserveFund::stanceName(SovereignReserveFund::equityShare($s)), $grid),
            StanceWords::DEBT => array_map(static fn(float $s): string => Exchequer::stanceName(Exchequer::averageMaturity($s)), $grid),
            StanceWords::COMPETITION => array_map(FairTradingBoard::stanceName(...), $grid),
            StanceWords::MARKETS => array_map(SecuritiesCommission::stanceName(...), $grid),
        ];

        foreach ($names as $question => $stances) {
            $stances = array_values(array_unique($stances));
            $this->assertCount(3, $stances, "The {$question} question names three stances across its range.");
            foreach (StanceWords::WORDS[$question] as $form => $words) {
                $this->assertEqualsCanonicalizing($stances, array_keys($words), "Every {$question} stance has {$form} words, and no other.");
            }
        }
    }

    /** The forms are what their places need: a cell and a sentence open with a capital, a clause and a noun do not. */
    public function testFormsAreCasedForWhereTheyStand(): void
    {
        foreach (StanceWords::WORDS as $question => $forms) {
            foreach ($forms as $form => $words) {
                foreach ($words as $stance => $text) {
                    $capital = in_array($form, [StanceWords::LABEL, StanceWords::SENTENCE], true);
                    $this->assertSame($capital, ctype_upper($text[0]), "{$question} {$form} words for {$stance}: \"{$text}\".");
                }
            }
        }
    }

    public function testAQuestionsWordsAndASwingVotesLean(): void
    {
        $this->assertSame('A light-touch regulator', StanceWords::for(StanceWords::BANKS, StanceWords::SENTENCE, 'light'));
        $this->assertSame('favouring short borrowing', StanceWords::for(StanceWords::DEBT, StanceWords::CLAUSE, 'short'));
        $this->assertSame('swing vote', StanceWords::for(StanceWords::MONEY, StanceWords::NOUN, 'swing'));
        $this->assertSame('leaning hawkish', StanceWords::leaning('hawk'));
        $this->assertNull(StanceWords::leaning(null));

        $this->expectException(\InvalidArgumentException::class);
        StanceWords::for(StanceWords::RESERVES, StanceWords::SENTENCE, 'cautious');
    }
}
