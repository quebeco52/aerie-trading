<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Politics\AerieDiet;
use App\Entity\DietElection;
use App\Service\Politics\Policy\Platforms;

/**
 * The Diet's two drawings: the chamber, seat by seat, and the compass that places each party on the policy questions.
 * The geometry is computed here so the template only draws.
 */
final class DietChamberDrawing
{
    // --- Hemicycle Geometry ---
    /** Rows of seats in the chamber drawing. */
    private const HEMICYCLE_ROWS = 8;
    /** Innermost row's radius, as a share of the outermost. */
    private const HEMICYCLE_INNER_RADIUS = 0.40;

    // --- Compass Geometry ---
    /**
     * The drawing at each screen width: the half-length of a line running -1 to +1 and the margin either side, in SVG
     * units, and whether the end words sit beside the line (the margin then fits 'Technocratic') or beneath it (half
     * the longest label). A phone gets the shorter line, so its labels stay legible.
     */
    private const COMPASS_LAYOUTS = [
        'wide' => [150.0, 56.0, true],
        'narrow' => [80.0, 24.0, false],
    ];
    /** The words at either end of each question's line, short enough for the margin. */
    private const COMPASS_ROW_ENDS = [
        AerieDiet::AXIS_STATE => ['Small state', 'Big state'],
        AerieDiet::AXIS_OPENNESS => ['Closed', 'Open'],
        AerieDiet::AXIS_COUNCIL => ['Populist', 'Technocratic'],
        AerieDiet::AXIS_ENVIRONMENT => ['Growth', 'Environment'],
    ];
    /** The levers as a question's heading lists them. */
    private const LEVER_SHORT_NAMES = [
        'corporateTax' => 'corporate tax',
        'publicSpending' => 'public spending',
        'tariff' => 'tariffs',
        'laborGrowth' => 'immigration',
        'foreignBuyerTax' => 'foreign buyers',
        'greenBeltStringency' => 'green belts',
        'carbonPrice' => 'carbon price',
        'extractionStringency' => 'extraction rules',
        'stampDutyRate' => 'stamp duty',
        'bankLevyRate' => 'bank levy',
        'reserveDrawShare' => 'reserve draw',
        'consolidationTaxShare' => 'tax or cuts',
        'reserveHomeShare' => 'reserves at home',
        'utilityRateGrant' => 'utility rates',
        'dividendTax' => 'dividend tax',
        'debtTarget' => 'debt target',
        'debtPace' => 'debt pace',
    ];
    /** Font size of the end words and party labels, in SVG units. */
    private const COMPASS_FONT_SIZE = 7.0;
    /** Advance of a bold label's average character as a share of its font size, to size a label's box. */
    public const COMPASS_CHARACTER_WIDTH = 0.6;
    /** Gap kept between a label and its dot, or another label, in SVG units. */
    private const COMPASS_LABEL_GAP = 1.5;
    /** Least clearance between the outermost dot or ring and a row of labels beyond the label gap, in SVG units. */
    private const COMPASS_LABEL_LIFT = 2.0;
    /** Least rise of a leader line per unit it runs sideways, so a leader never lies along the line. */
    private const COMPASS_LEADER_SLOPE = 0.4;
    /** Gap left between a leader line's ends and the dot or label it joins, in SVG units. */
    private const COMPASS_LEADER_GAP = 0.6;
    /** Radius of the smallest party's dot, in SVG units. */
    private const COMPASS_DOT_RADIUS = 2.5;
    /** Seats that add one SVG unit to a party's dot radius. */
    private const COMPASS_SEATS_PER_RADIUS = 25.0;
    /** Past votes whose positions are drawn behind each party, the oldest faintest. */
    private const COMPASS_TRAIL_VOTES = 6;
    /** Faintest and strongest opacity of a past position, the oldest drawn and the last vote's. */
    private const COMPASS_TRAIL_OPACITY = [0.10, 0.40];
    /** How far the mark where a coalition governs from reaches past the ring of its largest party, in SVG units. */
    private const COMPASS_HUB_PAD = 2.5;
    /** Gap between a party's dot and the ring round it, solid in the cabinet and dotted in support, in SVG units. */
    private const COMPASS_RING_GAP = 1.8;
    /** Margin kept above and below each question's drawing, in SVG units. */
    private const COMPASS_ROW_PAD = 2.0;

    /**
     * The seats of the chamber drawing, bloc by bloc, each running from the party wanting the largest state to the
     * smallest.
     *
     * Rows hold seats in proportion to their radius so the spacing is even, and seats are dealt out by angle so each
     * party takes a wedge.
     *
     * @param array<string, int>          $seats   Seats by party.
     * @param list<array<string, mixed>>  $parties Party rows, each marked governing or supporting.
     * @return list<array{x: float, y: float, color: string, governing: bool, supporting: bool}>
     */
    public static function hemicycle(array $seats, array $parties): array
    {
        $total = array_sum($seats);
        if ($total <= 0) {
            return [];
        }

        $radii = [];
        for ($row = 0; $row < self::HEMICYCLE_ROWS; ++$row) {
            $radii[] = self::HEMICYCLE_INNER_RADIUS + ((1.0 - self::HEMICYCLE_INNER_RADIUS) * $row / (self::HEMICYCLE_ROWS - 1));
        }
        $radiusSum = array_sum($radii);
        $perRow = array_map(static fn(float $radius): int => (int) round($total * $radius / $radiusSum), $radii);
        $perRow[self::HEMICYCLE_ROWS - 1] += $total - array_sum($perRow);

        $slots = [];
        foreach ($radii as $row => $radius) {
            $count = $perRow[$row];
            for ($i = 0; $i < $count; ++$i) {
                $angle = $count === 1 ? M_PI / 2.0 : M_PI * (1.0 - ($i / ($count - 1)));
                $slots[] = ['angle' => $angle, 'radius' => $radius];
            }
        }
        usort($slots, static fn(array $a, array $b): int => [$b['angle'], $a['radius']] <=> [$a['angle'], $b['radius']]);

        // Each bloc sits together, the one wanting the larger state on the left; within a bloc, the larger state
        // leftmost.
        $blocState = [];
        foreach ($parties as $party) {
            $blocState[$party['blocKey']][] = [$seats[$party['key']], $party['state']];
        }
        $blocState = array_map(static function (array $members): float {
            $weight = array_sum(array_column($members, 0));

            return $weight > 0
                ? array_sum(array_map(static fn(array $member): float => $member[0] * $member[1], $members)) / $weight
                : array_sum(array_column($members, 1)) / count($members);
        }, $blocState);

        $order = $parties;
        usort($order, static fn(array $a, array $b): int => [$blocState[$b['blocKey']], $a['blocKey'], $b['state']]
            <=> [$blocState[$a['blocKey']], $b['blocKey'], $a['state']]);

        $drawn = [];
        $slot = 0;
        foreach ($order as $party) {
            for ($i = 0; $i < $seats[$party['key']]; ++$i, ++$slot) {
                $drawn[] = [
                    'x' => round(cos($slots[$slot]['angle']) * $slots[$slot]['radius'], 4),
                    'y' => round(sin($slots[$slot]['angle']) * $slots[$slot]['radius'], 4),
                    'color' => $party['color'],
                    'governing' => $party['governing'],
                    'supporting' => $party['supporting'],
                ];
            }
        }

        return $drawn;
    }

    /**
     * Where the parties stand: one line per question, from its small-state, closed, populist or growth end on the left,
     * drawn at each width the page offers. Each party is a dot sized by its seats with its last few positions fading
     * behind it, and its label set above or below where it crowds nothing, a leader line joining any label moved
     * sideways off its dot. The cabinet's parties are ringed solid and its supporters dotted; a coalition also has a
     * mark across the line where it governs from (its parties weighted by seats), drawn behind the dots so it hides
     * none of them.
     *
     * @param non-empty-list<array<string, mixed>> $parties    Party rows.
     * @param list<DietElection>                   $history    Votes, oldest first.
     * @param array{state: float, openness: float, council: float, environment: float} $government The cabinet's seat-weighted position.
     * @param list<string>                         $coalition  The cabinet.
     * @return array<string, mixed>
     */
    public static function compass(array $parties, array $history, array $government, array $coalition): array
    {
        [$faintest, $strongest] = self::COMPASS_TRAIL_OPACITY;
        $recent = array_values(array_slice($history, -self::COMPASS_TRAIL_VOTES));

        $dots = [];
        foreach ($parties as $party) {
            $past = [];
            foreach ($recent as $k => $election) {
                if (isset($election->getPositions()[$party['key']])) {
                    $past[] = [
                        'position' => AerieDiet::position($party['key'], $election->getPositions()),
                        'opacity' => round($faintest + (($strongest - $faintest) * ($k + 1) / count($recent)), 3),
                    ];
                }
            }
            $dots[] = $party + ['r' => self::COMPASS_DOT_RADIUS + ($party['seats'] / self::COMPASS_SEATS_PER_RADIUS), 'past' => $past];
        }

        $members = array_values(array_filter($dots, static fn(array $dot): bool => in_array($dot['key'], $coalition, true)));
        $coalitionMark = count($members) > 1;
        $hubReach = ($members === [] ? 0.0 : max(array_column($members, 'r'))) + self::COMPASS_RING_GAP + self::COMPASS_HUB_PAD;
        $outermost = max(array_map(static fn(array $dot): float => $dot['r'] + ($dot['governing'] || $dot['supporting'] ? self::COMPASS_RING_GAP : 0.0), $dots));

        $layouts = [];
        foreach (self::COMPASS_LAYOUTS as $name => [$scale, $margin, $endsBeside]) {
            $rows = [];
            foreach (AerieDiet::AXES as $axis) {
                $rows[] = self::compassRow($axis, $dots, $coalitionMark ? $government[$axis] : null, $scale, $margin, $endsBeside, $hubReach, $outermost);
            }
            $layouts[$name] = [
                'halfWidth' => $scale,
                'endsBeside' => $endsBeside,
                // How far in from the drawing's edge the line ends, so end words set beneath it line up with its ends.
                'endInset' => round(100.0 * $margin / (2.0 * ($scale + $margin)), 2),
                'rows' => $rows,
            ];
        }

        return [
            'fontSize' => self::COMPASS_FONT_SIZE,
            'hubReach' => $hubReach,
            'ringGap' => self::COMPASS_RING_GAP,
            'coalitionMark' => $coalitionMark,
            'layouts' => $layouts,
        ];
    }

    /**
     * One question's line at one width: every party placed and labelled, the mark where a coalition governs from, and
     * the drawing's extent.
     *
     * @param list<array<string, mixed>> $dots     The parties, with their radius and past positions.
     * @param float|null                 $hub      Where the cabinet governs from on this question; null when one party
     *                                             governs alone.
     * @param float                      $hubReach How far the mark reaches either side of the line, in SVG units.
     * @return array<string, mixed>
     */
    private static function compassRow(string $axis, array $dots, ?float $hub, float $scale, float $margin, bool $endsBeside, float $hubReach, float $outermost): array
    {
        $size = self::COMPASS_FONT_SIZE;
        [$low, $high] = self::COMPASS_ROW_ENDS[$axis];
        $signed = static fn(float $value): string => sprintf('%+.2f', $value);

        $placed = [];
        foreach ($dots as $i => $dot) {
            $placed[$i] = $dot + [
                'x' => round($dot[$axis] * $scale, 2),
                'trail' => array_map(static fn(array $past): array => ['x' => round($past['position'][$axis] * $scale, 2), 'opacity' => $past['opacity']], $dot['past']),
            ];
            unset($placed[$i]['past']);
        }

        $boxes = [];
        foreach (self::placeRowLabels($placed, $outermost, $scale, $margin, $endsBeside) as $i => $label) {
            $placed[$i] += $label;
            $boxes[] = self::labelBox($placed[$i]['label'], $label['labelX'], $label['labelY'], 'middle');
        }

        $reach = max($outermost, $hub === null ? 0.0 : $hubReach);
        $top = min(-$reach, ...array_column($boxes, 1)) - self::COMPASS_ROW_PAD;
        $bottom = max($reach, ...array_column($boxes, 3)) + self::COMPASS_ROW_PAD;

        return [
            'axis' => $axis,
            'name' => DietStyle::AXIS_NAMES[$axis]['name'],
            'laws' => array_map(static fn(string $lever): string => self::LEVER_SHORT_NAMES[$lever], array_keys(Platforms::LEVER_AXES, $axis, true)),
            'summary' => sprintf(
                '%s, from %s on the left to %s on the right: %s%s',
                DietStyle::AXIS_NAMES[$axis]['name'],
                strtolower($low),
                strtolower($high),
                implode(', ', array_map(static fn(array $dot): string => $dot['name'] . ($dot['governing'] ? ' (in the cabinet)' : ($dot['supporting'] ? ' (supporting it)' : '')) . ' at ' . $signed($dot[$axis]), $dots)),
                $hub === null ? '' : '; the cabinet at ' . $signed($hub),
            ),
            'ends' => [
                ['text' => $low, 'x' => -$scale - 4.0, 'y' => 0.35 * $size, 'anchor' => 'end'],
                ['text' => $high, 'x' => $scale + 4.0, 'y' => 0.35 * $size, 'anchor' => 'start'],
            ],
            'hub' => $hub === null ? null : round($hub * $scale, 2),
            'parties' => array_values($placed),
            'viewBox' => [-$scale - $margin, round($top, 2), 2.0 * ($scale + $margin), round($bottom - $top, 2)],
        ];
    }

    /**
     * Where each party's label goes on a question's line: in one row above the line or one below, the larger parties
     * choosing first and taking the row above unless a label already there is in the way, the side with less in the
     * way when both are crowded; a row too long for its labels sends its smallest parties across where they fit. Each
     * row is spread so no label crowds the next, and set back from the line far enough that the leader joining a dot
     * to a label moved sideways off it rises at least COMPASS_LEADER_SLOPE. No label is centred past the end of its
     * line when the end words sit beside it, so no leader crosses them.
     *
     * @param array<int, array<string, mixed>> $dots       The parties, with their place on the line, radius, label and
     *                                                     part in the government.
     * @param float                            $outermost  Furthest any dot or ring reaches from the line, in SVG units.
     * @param float                            $scale      Half the line's length, in SVG units.
     * @param float                            $margin     The drawing's margin either side of the line, in SVG units.
     * @param bool                             $endsBeside Whether the end words sit beside the line's ends.
     * @return array<int, array{labelX: float, labelY: float, leader: list<float>|null}> By the dots' index.
     */
    private static function placeRowLabels(array $dots, float $outermost, float $scale, float $margin, bool $endsBeside): array
    {
        $gap = self::COMPASS_LABEL_GAP;
        $size = self::COMPASS_FONT_SIZE;
        $order = self::bySeats($dots);

        // Each label wants to sit centred over its dot; its own centre stays where its edge is inside the drawing, and
        // short of the line's end when the end words sit beside it.
        $items = [];
        foreach ($order as $i) {
            $width = self::labelWidth($dots[$i]['label']);
            $limit = $endsBeside ? $scale : $scale + $margin - ($width / 2.0);
            $items[$i] = ['centre' => (float) $dots[$i]['x'], 'width' => $width, 'lo' => -$limit, 'hi' => $limit];
        }
        $inTheWay = static function (array $item, array $row) use ($gap): float {
            $crowding = 0.0;
            foreach ($row as $other) {
                $crowding += max(0.0, ($item['width'] + $other['width']) / 2.0 + $gap - abs($item['centre'] - $other['centre']));
            }

            return $crowding;
        };
        // Whether a row's labels fit, in order, between the limits of its first and its last.
        $fits = static function (array $row) use ($gap): bool {
            if ($row === []) {
                return true;
            }
            uasort($row, static fn(array $a, array $b): int => $a['centre'] <=> $b['centre']);
            $first = $row[array_key_first($row)];
            $last = $row[array_key_last($row)];

            return array_sum(array_column($row, 'width')) + ($gap * (count($row) - 1))
                <= ($last['hi'] + ($last['width'] / 2.0)) - ($first['lo'] - ($first['width'] / 2.0));
        };

        // -1 is the row above the line, +1 the row below.
        $rows = [-1 => [], 1 => []];
        foreach ($order as $i) {
            $above = $inTheWay($items[$i], $rows[-1]);
            $rows[$above <= 0.0 || $above <= $inTheWay($items[$i], $rows[1]) ? -1 : 1][$i] = $items[$i];
        }
        foreach ([-1, 1] as $side) {
            foreach (array_reverse($order) as $i) {
                if ($fits($rows[$side])) {
                    break;
                }
                if (isset($rows[$side][$i]) && $fits($rows[-$side] + [$i => $rows[$side][$i]])) {
                    $rows[-$side][$i] = $rows[$side][$i];
                    unset($rows[$side][$i]);
                }
            }
        }

        $labels = [];
        foreach ($rows as $side => $row) {
            uasort($row, static fn(array $a, array $b): int => $a['centre'] <=> $b['centre']);
            $centres = self::packRow($row);
            $lift = self::COMPASS_LABEL_LIFT;
            $moved = [];
            foreach ($centres as $i => $centre) {
                $shift = abs($centre - $row[$i]['centre']);
                if ($shift > $row[$i]['width'] / 4.0) {
                    $moved[$i] = true;
                    $lift = max($lift, self::COMPASS_LEADER_SLOPE * $shift);
                }
            }
            // The labels' edge nearest the line, and their baseline.
            $edge = $side * ($outermost + $gap + $lift);
            $baseline = $side < 0 ? $edge - (0.2 * $size) : $edge + (0.8 * $size);
            foreach ($centres as $i => $centre) {
                $leader = null;
                if (isset($moved[$i])) {
                    $x = $row[$i]['centre'];
                    $endY = $edge - ($side * self::COMPASS_LEADER_GAP);
                    $length = hypot($centre - $x, $endY);
                    $reach = $dots[$i]['r'] + ($dots[$i]['governing'] || $dots[$i]['supporting'] ? self::COMPASS_RING_GAP : 0.0) + self::COMPASS_LEADER_GAP;
                    $leader = [round($x + (($centre - $x) * $reach / $length), 2), round($endY * $reach / $length, 2), round($centre, 2), round($endY, 2)];
                }
                $labels[$i] = ['labelX' => round($centre, 2), 'labelY' => round($baseline, 2), 'leader' => $leader];
            }
        }

        return $labels;
    }

    /**
     * Spreads a row of labels so none comes within the label gap of the next, each as near its wanted centre as the
     * others allow: labels that would crowd each other are merged into a run set where its labels' wanted centres put
     * it on average (least squares), held within every label's limits, and runs that then crowd merge in turn.
     *
     * @param array<int, array{centre: float, width: float, lo: float, hi: float}> $items Labels in order of their wanted centres, each with the range its centre may take.
     * @return array<int, float> Each label's centre.
     */
    private static function packRow(array $items): array
    {
        $gap = self::COMPASS_LABEL_GAP;
        $left = static fn(array $run): float => max($run['lo'], min($run['hi'], $run['sum'] / count($run['keys'])));
        // A run's 'sum' adds each label's wanted left edge less its offset in the run, so its best left edge is their
        // mean; 'lo' and 'hi' bound that edge so every label keeps within its own limits.
        $runs = [];
        foreach ($items as $key => $item) {
            $half = $item['width'] / 2.0;
            $run = ['keys' => [$key], 'width' => $item['width'], 'sum' => $item['centre'] - $half, 'lo' => $item['lo'] - $half, 'hi' => $item['hi'] - $half];
            while ($runs !== [] && $left($runs[array_key_last($runs)]) + $runs[array_key_last($runs)]['width'] + $gap > $left($run)) {
                $last = array_pop($runs);
                $offset = $last['width'] + $gap;
                $run = [
                    'keys' => [...$last['keys'], ...$run['keys']],
                    'width' => $offset + $run['width'],
                    'sum' => $last['sum'] + $run['sum'] - ($offset * count($run['keys'])),
                    'lo' => max($last['lo'], $run['lo'] - $offset),
                    'hi' => min($last['hi'], $run['hi'] - $offset),
                ];
            }
            $runs[] = $run;
        }

        $centres = [];
        foreach ($runs as $run) {
            $at = $left($run);
            foreach ($run['keys'] as $key) {
                $centres[$key] = $at + ($items[$key]['width'] / 2.0);
                $at += $items[$key]['width'] + $gap;
            }
        }

        return $centres;
    }

    /**
     * The dots' indexes from the most seats to the fewest, ties in party order.
     *
     * @param array<int, array<string, mixed>> $dots
     * @return list<int>
     */
    private static function bySeats(array $dots): array
    {
        $order = array_keys($dots);
        usort($order, static fn(int $a, int $b): int => [$dots[$b]['seats'], $a] <=> [$dots[$a]['seats'], $b]);

        return $order;
    }

    /**
     * The box a label covers: its width from its length, from the cap height above its baseline to the descenders.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} Left, top, right, bottom.
     */
    private static function labelBox(string $text, float $x, float $baseline, string $anchor): array
    {
        $width = self::labelWidth($text);
        $left = match ($anchor) {
            'start' => $x,
            'end' => $x - $width,
            default => $x - ($width / 2.0),
        };

        return [$left, $baseline - (0.8 * self::COMPASS_FONT_SIZE), $left + $width, $baseline + (0.2 * self::COMPASS_FONT_SIZE)];
    }

    /**
     * A label's width at the compass font, from its length.
     */
    private static function labelWidth(string $text): float
    {
        return mb_strlen($text) * self::COMPASS_FONT_SIZE * self::COMPASS_CHARACTER_WIDTH;
    }
}
