<?php

declare(strict_types=1);

namespace App\Data;

/**
 * The names the District's public figures are drawn from: a given name and a family name, each picked from a hash of
 * the game and the vacancy (App\Service\Politics\MonetaryAuthority::candidate()), so every candidate for the Council,
 * the governorship and the rate committee is someone.
 */
final class AerieNames
{
    // --- Traditions ---
    /** American tradition. */
    public const TRADITION_AMERICAN = 'american';
    /** Anglo-Saxon tradition. */
    public const TRADITION_ANGLO_SAXON = 'anglo_saxon';
    /** Western European tradition. */
    public const TRADITION_WESTERN_EUROPEAN = 'western_european';
    /** Japanese tradition. */
    public const TRADITION_JAPANESE = 'japanese';
    /** Swedish tradition. */
    public const TRADITION_SWEDISH = 'swedish';

    /** All supported naming traditions. */
    public const TRADITIONS = [
        self::TRADITION_AMERICAN,
        self::TRADITION_ANGLO_SAXON,
        self::TRADITION_WESTERN_EUROPEAN,
        self::TRADITION_JAPANESE,
        self::TRADITION_SWEDISH,
    ];

    // --- Gender Balance ---
    /** Minimum target share of male given names in the pool (72% realized). */
    public const MALE_SHARE_TARGET = 0.70;

    // --- Pools ---
    /** Given and family names organized by tradition, with given names tilted at least 70% male. */
    public const POOLS = [
        self::TRADITION_AMERICAN => [
            'given' => [
                // Male (18 = 72%)
                'Alexander', 'Benjamin', 'Calvin', 'Clifford', 'Elliott',
                'Franklin', 'Graham', 'Grant', 'Harrison', 'Jackson',
                'Malcolm', 'Nathaniel', 'Russell', 'Samuel', 'Theodore',
                'Thomas', 'Walter', 'Warren',
                // Female (7 = 28%)
                'Abigail', 'Clara', 'Eleanor', 'Evelyn', 'Grace',
                'Louisa', 'Lydia',
            ],
            'family' => [
                'Adams', 'Alden', 'Bradford', 'Brookfield', 'Calloway',
                'Carver', 'Chase', 'Davis', 'Emerson', 'Hamilton',
                'Hayes', 'Lowell', 'Madison', 'Marshall', 'Mercer',
                'Morgan', 'Palmer', 'Prescott', 'Reed', 'Rockefeller',
                'Sterling', 'Sumner', 'Vance', 'Vanderbilt', 'Winslow',
            ],
        ],
        self::TRADITION_ANGLO_SAXON => [
            'given' => [
                // Male (18 = 72%)
                'Alistair', 'Ambrose', 'Arthur', 'Desmond', 'Dorian',
                'Edmund', 'Edward', 'Gareth', 'Godfrey', 'Jasper',
                'Julian', 'Laurence', 'Percival', 'Piers', 'Roland',
                'Rupert', 'Sebastian', 'Silas',
                // Female (7 = 28%)
                'Beatrice', 'Charlotte', 'Constance', 'Florence', 'Penelope',
                'Rosalind', 'Victoria',
            ],
            'family' => [
                'Arkwright', 'Ashby', 'Blackwood', 'Crawford', 'Crewe',
                'Farrow', 'Finch', 'Gresham', 'Harrington', 'Hastings',
                'Holt', 'Kincaid', 'Langford', 'Montague', 'Ormerod',
                'Ravenscroft', 'Selwyn', 'Stanhope', 'Strickland', 'Thackeray',
                'Thornton', 'Waverly', 'Wexford', 'Whitlock', 'Wilde',
            ],
        ],
        self::TRADITION_WESTERN_EUROPEAN => [
            'given' => [
                // Male (18 = 72%)
                'Augustin', 'Benedikt', 'Christoph', 'Etienne', 'Fabian',
                'Florian', 'Guillaume', 'Henri', 'Leopold', 'Luc',
                'Ludwig', 'Marcel', 'Matthias', 'Nicolas', 'Philippe',
                'Stefan', 'Valentin', 'Xavier',
                // Female (7 = 28%)
                'Delphine', 'Genevieve', 'Isabelle', 'Juliette', 'Marguerite',
                'Sabine', 'Sophie',
            ],
            'family' => [
                'Beaumont', 'Brandt', 'Castel', 'De Jong', 'De Vries',
                'Delacroix', 'Dubois', 'Dupont', 'Eberhardt', 'Fontaine',
                'Hoffmann', 'Keller', 'Laurent', 'Marchand', 'Meyer',
                'Moreau', 'Müller', 'Renaud', 'Richter', 'Roche',
                'Schneider', 'Sorel', 'Van Dijk', 'Vogel', 'Weber',
            ],
        ],
        self::TRADITION_JAPANESE => [
            'given' => [
                // Male (18 = 72%)
                'Daiki', 'Haruto', 'Hiroshi', 'Kaito', 'Kazuki',
                'Kenji', 'Makoto', 'Masashi', 'Ren', 'Ryota',
                'Satoshi', 'Shin', 'Shota', 'Taichi', 'Takahiro',
                'Takumi', 'Yasuhiro', 'Yuto',
                // Female (7 = 28%)
                'Emi', 'Hana', 'Keiko', 'Mei', 'Naomi',
                'Sakura', 'Yoko',
            ],
            'family' => [
                'Fujimoto', 'Hayashi', 'Inoue', 'Ishikawa', 'Ito',
                'Kato', 'Kobayashi', 'Matsuda', 'Matsui', 'Mori',
                'Nakamura', 'Ogawa', 'Saito', 'Sato', 'Shimizu',
                'Suzuki', 'Takahashi', 'Tanaka', 'Taniguchi', 'Watanabe',
                'Yamada', 'Yamaguchi', 'Yamamoto', 'Yamazaki', 'Yoshida',
            ],
        ],
        self::TRADITION_SWEDISH => [
            'given' => [
                // Male (18 = 72%)
                'Anders', 'Arvid', 'Björn', 'Emil', 'Erik',
                'Fredrik', 'Gunnar', 'Gustav', 'Henrik', 'Johan',
                'Karl', 'Lars', 'Magnus', 'Nils', 'Oskar',
                'Sven', 'Torsten', 'Viktor',
                // Female (7 = 28%)
                'Astrid', 'Ebba', 'Elsa', 'Freja', 'Ingrid',
                'Linnea', 'Sigrid',
            ],
            'family' => [
                'Berg', 'Blomqvist', 'Dahlberg', 'Ekström', 'Engström',
                'Hedlund', 'Holm', 'Larsson', 'Lindberg', 'Lindgren',
                'Lindqvist', 'Lindström', 'Lund', 'Norberg', 'Nyberg',
                'Nyström', 'Olofsson', 'Persson', 'Quist', 'Sandberg',
                'Sjöberg', 'Ström', 'Sundqvist', 'Söderberg', 'Wallin',
            ],
        ],
    ];

    // --- Aggregates ---
    /** All given names across traditions. */
    public const GIVEN = [
        ...self::POOLS[self::TRADITION_AMERICAN]['given'],
        ...self::POOLS[self::TRADITION_ANGLO_SAXON]['given'],
        ...self::POOLS[self::TRADITION_WESTERN_EUROPEAN]['given'],
        ...self::POOLS[self::TRADITION_JAPANESE]['given'],
        ...self::POOLS[self::TRADITION_SWEDISH]['given'],
    ];

    /** All family names across traditions. */
    public const FAMILY = [
        ...self::POOLS[self::TRADITION_AMERICAN]['family'],
        ...self::POOLS[self::TRADITION_ANGLO_SAXON]['family'],
        ...self::POOLS[self::TRADITION_WESTERN_EUROPEAN]['family'],
        ...self::POOLS[self::TRADITION_JAPANESE]['family'],
        ...self::POOLS[self::TRADITION_SWEDISH]['family'],
    ];

    /**
     * Picks a coherent given and family name from a single tradition.
     *
     * @param float $traditionDraw Uniform on [0, 1) to select the cultural tradition.
     * @param float $givenDraw     Uniform on [0, 1) to select the given name within that tradition.
     * @param float $familyDraw    Uniform on [0, 1) to select the family name within that tradition.
     */
    public static function pick(float $traditionDraw, float $givenDraw, float $familyDraw): string
    {
        $traditions = self::TRADITIONS;
        $traditionIndex = (int) floor(max(0.0, min(0.999999999999999, $traditionDraw)) * count($traditions));
        $tradition = $traditions[$traditionIndex];
        $pool = self::POOLS[$tradition];

        $givenIndex = (int) floor(max(0.0, min(0.999999999999999, $givenDraw)) * count($pool['given']));
        $familyIndex = (int) floor(max(0.0, min(0.999999999999999, $familyDraw)) * count($pool['family']));

        return $pool['given'][$givenIndex] . ' ' . $pool['family'][$familyIndex];
    }
}
