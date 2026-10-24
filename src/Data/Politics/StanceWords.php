<?php

declare(strict_types=1);

namespace App\Data\Politics;

/**
 * The words the government's pages use for a stance, one table for every question an appointee holds a view on: money
 * at the Monetary Authority, the banks at the Financial Regulator, the reserves at the Sovereign Reserve Fund, the debt
 * at the Exchequer, competition at the Fair Trading Board and the markets at the Securities Commission.
 *
 * Each question's words come in the forms its pages print: a table cell, the sentence on a head's own page, the clause
 * after their name on the government's front page, and a bare noun for a candidate in brackets. The stance names are
 * the ones each department's stanceName() returns.
 */
final class StanceWords
{
    // --- Questions ---
    /** The Monetary Authority's and the Council's question: hawk, swing vote or dove. */
    public const MONEY = 'money';
    /** The Financial Regulator's: light-touch, moderate or strict. */
    public const BANKS = 'banks';
    /** The Sovereign Reserve Fund's: cautious, balanced or bold. */
    public const RESERVES = 'reserves';
    /** The Exchequer's: borrowing short, a middle course or long. */
    public const DEBT = 'debt';
    /** The Fair Trading Board's: letting firms consolidate, a middle course or strict enforcement. */
    public const COMPETITION = 'competition';
    /** The Securities Commission's: light-touch, a middle course or strict. */
    public const MARKETS = 'markets';

    // --- Forms ---
    /** A table cell: "Hawk", "Light-touch". */
    public const LABEL = 'label';
    /** Opens the sentence about a head on their department's page: "A light-touch regulator". */
    public const SENTENCE = 'sentence';
    /** Follows a name mid-sentence, as on the government's front page: "Head Leontine Ashby, light-touch". */
    public const CLAUSE = 'clause';
    /** A candidate's stance in brackets after their name: "(hawk, 52)". */
    public const NOUN = 'noun';

    // --- Words ---
    /** Each question's words by form and stance name. */
    public const WORDS = [
        self::MONEY => [
            self::LABEL => ['hawk' => 'Hawk', 'swing' => 'Swing', 'dove' => 'Dove'],
            self::SENTENCE => ['hawk' => 'A hawk', 'swing' => 'A swing vote', 'dove' => 'A dove'],
            self::CLAUSE => ['hawk' => 'a hawk', 'swing' => 'a swing vote', 'dove' => 'a dove'],
            self::NOUN => ['hawk' => 'hawk', 'swing' => 'swing vote', 'dove' => 'dove'],
        ],
        self::BANKS => [
            self::LABEL => ['light' => 'Light-touch', 'middle' => 'Moderate', 'strict' => 'Strict'],
            self::SENTENCE => ['light' => 'A light-touch regulator', 'middle' => 'A moderate regulator', 'strict' => 'A strict regulator'],
            self::CLAUSE => ['light' => 'light-touch', 'middle' => 'moderate', 'strict' => 'strict'],
        ],
        self::RESERVES => [
            self::LABEL => ['cautious' => 'Cautious', 'balanced' => 'Balanced', 'bold' => 'Bold'],
            self::CLAUSE => ['cautious' => 'a cautious investor', 'balanced' => 'a balanced investor', 'bold' => 'a bold investor'],
        ],
        self::DEBT => [
            self::SENTENCE => ['short' => 'Favours borrowing short', 'balanced' => 'Takes a middle course', 'long' => 'Favours locking in long-term rates'],
            self::CLAUSE => ['short' => 'favouring short borrowing', 'balanced' => 'taking a middle course', 'long' => 'favouring long borrowing'],
        ],
        self::COMPETITION => [
            self::SENTENCE => ['light' => 'Would let firms consolidate', 'balanced' => 'Takes a middle course', 'strict' => 'A strict enforcer against concentration'],
            self::CLAUSE => ['light' => 'who would let firms consolidate', 'balanced' => 'taking a middle course', 'strict' => 'a strict enforcer'],
        ],
        self::MARKETS => [
            self::SENTENCE => ['light' => 'A light-touch overseer of the markets', 'balanced' => 'Takes a middle course', 'strict' => 'A strict overseer of the markets'],
            self::CLAUSE => ['light' => 'light-touch', 'balanced' => 'taking a middle course', 'strict' => 'strict'],
        ],
    ];

    /** The camp a swing vote on money leans to, after their stance: "A swing vote leaning hawkish". */
    public const LEANING = ['hawk' => 'leaning hawkish', 'dove' => 'leaning dovish'];

    /** A question's words for a stance in one form; a form or stance the table lacks is a programming error. */
    public static function for(string $question, string $form, string $stance): string
    {
        return self::WORDS[$question][$form][$stance] ?? throw new \InvalidArgumentException("No {$form} words for the {$stance} stance on {$question}.");
    }

    /** The words for a swing vote's lean, or null for no lean. */
    public static function leaning(?string $camp): ?string
    {
        return $camp === null ? null : self::LEANING[$camp] ?? throw new \InvalidArgumentException("No lean called {$camp}.");
    }
}
