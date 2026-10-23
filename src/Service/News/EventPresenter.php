<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Data\District\DistrictCalendar;
use App\Entity\DistrictNews;
use App\Entity\EtfEvent;
use App\Entity\StockEvent;
use App\Service\Event\ShockEvent;

/**
 * Turns a published event into the card the stock page, the newswire, the live feed and the district map all render:
 * its badge, tone, icon and headline, by the card its type routes to (NewsCard).
 */
class EventPresenter
{
    // --- District Stories ---

    /** The badge a district story carries for the event that made it; a topic not listed reads as its desk. */
    private const TOPIC_BADGES = [
        ShockEvent::BANKING_CRISIS => 'BANKING CRISIS',
        ShockEvent::TITAN_INTERVENTION => 'ASSET PURCHASES',
        ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT => 'FUND REBALANCE',
        ShockEvent::SOVEREIGN_WEALTH_TRIM => 'FUND REBALANCE',
        ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE => 'FUNDING STRESS',
        ShockEvent::CREDIT_MARKET_SEIZURE => 'CREDIT STRESS',
        ShockEvent::SOVEREIGN_DOWNGRADE => 'SOVEREIGN DEBT',
        ShockEvent::RECESSION_DECLARED => 'RECESSION',
        ShockEvent::YIELD_CURVE_INVERSION_ALARM => 'YIELD CURVE',
        ShockEvent::HOUSEHOLD_DELEVERAGING => 'HOUSEHOLDS',
        ShockEvent::NATURAL_CATASTROPHE => 'CATASTROPHE',
        ShockEvent::ELECTION_HELD => 'ELECTION',
        ShockEvent::GOVERNMENT_FELL => 'GOVERNMENT FALLS',
        ShockEvent::GOVERNMENT_FORMED => 'NEW GOVERNMENT',
        ShockEvent::PRIME_MINISTER_CHANGED => 'PRIME MINISTER',
        ShockEvent::BUDGET_ENACTED => 'BUDGET',
        ShockEvent::GOVERNOR_APPOINTED => 'APPOINTMENT',
        ShockEvent::REGULATOR_APPOINTED => 'APPOINTMENT',
        ShockEvent::FUND_HEAD_APPOINTED => 'APPOINTMENT',
        ShockEvent::EXCHEQUER_HEAD_APPOINTED => 'APPOINTMENT',
        ShockEvent::FAIR_TRADING_HEAD_APPOINTED => 'APPOINTMENT',
        ShockEvent::SECURITIES_HEAD_APPOINTED => 'APPOINTMENT',
        ShockEvent::SECURITY_DIRECTOR_APPOINTED => 'APPOINTMENT',
        ShockEvent::COAST_GUARD_COMMANDANT_APPOINTED => 'APPOINTMENT',
        ShockEvent::STATE_GUARD_COMMANDER_APPOINTED => 'APPOINTMENT',
        ShockEvent::AUTHORITY_MAJORITY_SHIFT => 'MONETARY POLICY',
        ShockEvent::MONETARY_DECISION => 'RATE DECISION',
        ShockEvent::PARTY_LEADER_CHANGED => 'PARTY LEADER',
        ShockEvent::COUNCILLOR_SEATED => 'COUNCIL SEAT',
    ];

    /** District stories that warn of stress rather than report a fall, so they carry the caution tone. */
    private const CAUTION_TOPICS = [
        ShockEvent::BANKING_CRISIS,
        ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE,
        ShockEvent::CREDIT_MARKET_SEIZURE,
        ShockEvent::SOVEREIGN_DOWNGRADE,
        ShockEvent::RECESSION_DECLARED,
        ShockEvent::YIELD_CURVE_INVERSION_ALARM,
        ShockEvent::HOUSEHOLD_DELEVERAGING,
        ShockEvent::NATURAL_CATASTROPHE,
        ShockEvent::GOVERNMENT_FELL,
    ];

    /**
     * Presents an event entity or associative array into a structured view model.
     *
     * @param StockEvent|EtfEvent|DistrictNews|array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function present(StockEvent|EtfEvent|DistrictNews|array $event): array
    {
        $topic = null;
        if ($event instanceof DistrictNews) {
            $type = $event->getDesk();
            $topic = $event->getTopic();
            $description = $event->getDescription();
            $changePct = $event->getChangePercent() !== null ? (float) $event->getChangePercent() : null;
            $recordedAt = $event->getRecordedAt();
            $simTime = $event->getSimTime();
        } elseif ($event instanceof StockEvent || $event instanceof EtfEvent) {
            $type = strtoupper((string) $event->getEventType());
            $description = (string) $event->getDescription();
            $changePct = $event->getChangePercent() !== null ? (float) $event->getChangePercent() : null;
            $recordedAt = $event->getRecordedAt();
            $simTime = $event->getSimTime();
        } else {
            $type = strtoupper((string) ($event['type'] ?? 'EVENT'));
            $description = (string) ($event['description'] ?? '');
            $changePct = isset($event['change_percent']) ? (float) $event['change_percent'] : null;
            $topic = isset($event['topic']) ? (string) $event['topic'] : null;
            $simTime = isset($event['sim_time']) ? (float) $event['sim_time'] : null;
            $recordedAt = isset($event['recorded_at']) ? new \DateTime((string) $event['recorded_at']) : new \DateTime();
        }

        $card = NewsCard::forType($type);
        $story = match ($card) {
            NewsCard::Earnings => $this->earnings($description),
            NewsCard::Shock => self::story(
                'MARKET SHOCK',
                $changePct === null || $changePct >= 0.0 ? Tone::Warn : Tone::Down,
                'bolt',
                // Read with their move, a shock says what the shares did.
                match (true) {
                    $description !== '' => $description,
                    $changePct !== null => self::shockHeadline('Shares', $changePct),
                    default => 'Shares moved sharply.',
                },
            ),
            NewsCard::Split => $type === 'REVERSE_SPLIT'
                ? self::story('REVERSE STOCK SPLIT', Tone::Neutral, 'call_split', $description ?: 'Reverse stock split executed.')
                : self::story('STOCK SPLIT', Tone::Neutral, 'call_split', $description ?: 'Stock split executed.'),
            NewsCard::Rating => self::rating($type, $description),
            // The deal type is already the headline a desk would print (STRATEGIC ACQUISITION, LEVERAGED BUYOUT…).
            NewsCard::Deal => self::story($type, Tone::Accent, $type === 'DIVESTITURE' ? 'domain_disabled' : 'domain_add', $description ?: 'Corporate restructuring announcement.'),
            NewsCard::Analyst => self::analyst($type, $description),
            NewsCard::Bankruptcy => self::story('BANKRUPTCY', Tone::Down, 'gavel', $description ?: 'Filed for Chapter 7 bankruptcy liquidation.'),
            // The company keeps trading, so a Chapter 11 plan reads as a restructuring rather than a death.
            NewsCard::Reorganization => self::story('CHAPTER 11', Tone::Warn, 'balance', $description ?: 'Confirmed a Chapter 11 plan of reorganisation.'),
            NewsCard::Street => self::street($description),
            // The divisor is restated across a reconstitution, so the level itself does not move on it.
            NewsCard::Index => self::story('INDEX RECONSTITUTION', Tone::Accent, 'checklist', $description ?: 'Index membership reconstituted.'),
            // The price falls by the payment, but the holder has the cash, so it must not read as a drop.
            NewsCard::Distribution => self::story('DISTRIBUTION', Tone::Up, 'payments', $description ?: 'Fund distribution paid.'),
            NewsCard::Succession => self::succession($description),
            NewsCard::Economy, NewsCard::Government => self::districtStory($card, $type, $topic, $description),
            NewsCard::General => self::story($type, Tone::Neutral, 'campaign', $description ?: 'Corporate news announcement.'),
        };

        $tone = $story['tone'];

        return [
            'type' => $type,
            'category' => $card->value,
            'badge' => $story['badge'],
            'tone' => $tone->value,
            'badgeClass' => $tone->badgeClass(),
            'icon' => $story['icon'],
            'iconClass' => $tone->iconClass(),
            'isEarnings' => $card === NewsCard::Earnings,
            'headline' => $story['headline'],
            'pills' => $story['pills'],
            'changePercent' => $card === NewsCard::Bankruptcy ? ($changePct ?? -100.0) : $changePct,
            'recordedAt' => $recordedAt,
            // Dated in the District's calendar; a row from before events carried their simulation time keeps its
            // clock time.
            'dateline' => $simTime !== null ? DistrictCalendar::dateline($simTime) : $recordedAt->format('Y-m-d H:i'),
            'rawDescription' => $description,
        ] + $story['earnings'];
    }

    /** A price shock as a wire line: "Hummock Foods shares jump 12.4% in a sudden move." */
    public static function shockHeadline(string $subject, float $changePercent): string
    {
        return sprintf('%s %s %s%% in a sudden move.', $subject, $changePercent >= 0.0 ? 'jump' : 'drop', number_format(abs($changePercent), 1));
    }

    /**
     * The card as the live feed receives it: the same array with the timestamp already printed the way the
     * page prints it, since a DateTime does not survive json_encode in a form the browser can read.
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function presentForWire(array $event): array
    {
        $card = $this->present($event);
        $card['recordedAt'] = $card['recordedAt'] instanceof \DateTimeInterface
            ? $card['recordedAt']->format('Y-m-d H:i')
            : (string) $card['recordedAt'];

        return $card;
    }

    /**
     * What a card says: its badge, tone, icon and headline, plus the pills under it and, for earnings, the figures.
     *
     * @param  list<array{kind: string, text: string}> $pills
     * @param  array<string, mixed>                    $earnings
     * @return array{badge: string, tone: Tone, icon: string, headline: string, pills: list<array{kind: string, text: string}>, earnings: array<string, mixed>}
     */
    private static function story(string $badge, Tone $tone, string $icon, string $headline, array $pills = [], array $earnings = []): array
    {
        return ['badge' => $badge, 'tone' => $tone, 'icon' => $icon, 'headline' => $headline, 'pills' => $pills, 'earnings' => $earnings];
    }

    /**
     * A quarter's results as a sentence: "Quarterly earnings of $1.42 a share beat forecasts by $0.11." A negative
     * figure is a loss.
     */
    private static function earningsHeadline(string $eps, ?string $surpriseType, ?string $surpriseAmount): string
    {
        $isLoss = str_starts_with($eps, '-');
        $result = sprintf('Quarterly %s of %s a share', $isLoss ? 'loss' : 'earnings', ltrim($eps, '+-'));
        $by = $surpriseAmount !== null ? ' by ' . ltrim($surpriseAmount, '+-') : '';
        $verb = $isLoss ? 'was' : 'were';

        return match ($surpriseType) {
            'beat' => "{$result} beat forecasts{$by}.",
            'miss' => "{$result} missed forecasts{$by}.",
            default => "{$result} {$verb} in line with forecasts.",
        };
    }

    /**
     * @return array{badge: string, tone: Tone, icon: string, headline: string, pills: list<array{kind: string, text: string}>, earnings: array<string, mixed>}
     */
    private function earnings(string $description): array
    {
        $eps = null;
        $surpriseType = null; // 'beat', 'miss', 'met'
        $surpriseAmount = null;
        $eva = null;
        $evaPositive = true;
        $headline = 'Quarterly Earnings Report';
        $remainingText = $description;

        // Pattern matching: "Q-Earnings: $2.26 (Beat expectations by $0.01 | +$80.92B EVA).". When special items part
        // the two, the figure reads "$2.26 adjusted, $1.80 reported"; the beat or miss is on the adjusted one.
        $pattern = '/^Q-Earnings:\s*([+-]?\$?[\d,.]+(?:\s*[BMTk])?)(?:\s*adjusted,\s*[+-]?\$?[\d,.]+\s*reported)?\s*\((Beat expectations by|Missed expectations by|Met expectations(?: exactly)?)\s*([+-]?\$?[\d,.]+(?:\s*[BMTk])?)?\s*\|\s*([+-]?\$?[\d,.]+[BMTk]?\s*EVA)\)\.?/i';
        if (preg_match($pattern, $description, $matches)) {
            $eps = trim($matches[1]);
            $verbiage = strtolower($matches[2]);
            if (str_contains($verbiage, 'beat')) {
                $surpriseType = 'beat';
            } elseif (str_contains($verbiage, 'missed')) {
                $surpriseType = 'miss';
            } else {
                $surpriseType = 'met';
            }
            $rawSurprise = !empty($matches[3]) ? trim($matches[3]) : null;
            $surpriseVal = $rawSurprise !== null ? (float) preg_replace('/[^\d.]/', '', $rawSurprise) : 0.0;

            // Sub-cent difference (< 0.005) is reported as In-Line
            if ($surpriseVal < 0.005) {
                $surpriseType = 'met';
                $surpriseAmount = null;
            } else {
                $surpriseAmount = $rawSurprise;
            }

            $eva = trim($matches[4]);
            $evaPositive = !str_starts_with($eva, '-');
            $headline = self::earningsHeadline($eps, $surpriseType, $surpriseAmount);
            $remainingText = trim(substr($description, strlen($matches[0])));
        }

        [$badge, $tone, $icon, $surpriseText] = match ($surpriseType) {
            'beat' => ['EARNINGS BEAT', Tone::Up, 'trending_up', $surpriseAmount ? "Beat by +{$surpriseAmount}" : 'Beat Expectations'],
            'miss' => ['EARNINGS MISS', Tone::Down, 'trending_down', $surpriseAmount ? "Missed by {$surpriseAmount}" : 'Missed Expectations'],
            default => ['EARNINGS IN-LINE', Tone::Accent, 'equalizer', 'In-Line'],
        };

        return self::story($badge, $tone, $icon, $headline, $this->parseSubActionPills($remainingText), [
            'eps' => $eps,
            'surpriseType' => $surpriseType,
            'surpriseAmount' => $surpriseAmount,
            'surpriseText' => $surpriseText,
            'eva' => $eva,
            'evaPositive' => $evaPositive,
        ]);
    }

    /**
     * @return array{badge: string, tone: Tone, icon: string, headline: string, pills: list<array{kind: string, text: string}>, earnings: array<string, mixed>}
     */
    private static function rating(string $type, string $description): array
    {
        // The debt engine writes "[CREDIT DOWNGRADE] TICK: ..." for the log; the badge already says both.
        $headline = (string) preg_replace('/^\[[A-Z ]+\]\s*[A-Z0-9.]+:\s*/', '', $description);

        return $type === 'CREDIT_UPGRADE'
            ? self::story('RATING UPGRADE', Tone::Up, 'credit_score', $headline ?: 'Credit rating event.')
            : self::story('RATING DOWNGRADE', Tone::Down, 'warning', $headline ?: 'Credit rating event.');
    }

    /**
     * The sell side and management talking about the number rather than reporting it: a price-target
     * revision (StockTracker) or a guidance cut (EarningsEngine). A cut reads down; a raise reads up.
     *
     * @return array{badge: string, tone: Tone, icon: string, headline: string, pills: list<array{kind: string, text: string}>, earnings: array<string, mixed>}
     */
    private static function analyst(string $type, string $description): array
    {
        $headline = $description ?: 'Analyst revision.';

        return match (true) {
            $type === 'GUIDANCE' => self::story('GUIDANCE CUT', Tone::Down, 'campaign', $headline),
            str_contains(strtolower($description), ' cut ') => self::story('TARGET CUT', Tone::Down, 'query_stats', $headline),
            default => self::story('TARGET RAISED', Tone::Up, 'query_stats', $headline),
        };
    }

    /**
     * A Glasswater Row roster change — promotion onto or eviction from the street at the quarterly
     * reconstitution (MarketTickerCommand via DistrictRoster). Not a price event: no move is implied.
     *
     * @return array{badge: string, tone: Tone, icon: string, headline: string, pills: list<array{kind: string, text: string}>, earnings: array<string, mixed>}
     */
    private static function street(string $description): array
    {
        $headline = $description ?: 'Glasswater Row roster reconstituted.';

        return str_contains(strtolower($description), 'lost')
            ? self::story('STREET EVICTION', Tone::Warn, 'location_city', $headline)
            : self::story('STREET PROMOTION', Tone::Accent, 'location_city', $headline);
    }

    /**
     * A change at the top (App\Service\Corporate\ManagementSuccessionEngine). Not a price event — the value in it is
     * the policy that follows, which the engines price through the dials the incoming style moves.
     *
     * @return array{badge: string, tone: Tone, icon: string, headline: string, pills: list<array{kind: string, text: string}>, earnings: array<string, mixed>}
     */
    private static function succession(string $description): array
    {
        $headline = $description ?: 'The company named new management.';

        return str_contains(strtolower($description), 'removed by the board')
            ? self::story('BOARD REMOVAL', Tone::Warn, 'gavel', $headline)
            : self::story('MANAGEMENT CHANGE', Tone::Neutral, 'badge', $headline);
    }

    /**
     * A district-wide story from the economy or government desk (SystemicEventReporter). The number is what the
     * benchmark did over the month before it, so it carries the sign colour and the badge only says what happened.
     *
     * @return array{badge: string, tone: Tone, icon: string, headline: string, pills: list<array{kind: string, text: string}>, earnings: array<string, mixed>}
     */
    private static function districtStory(NewsCard $card, string $desk, ?string $topic, string $description): array
    {
        return self::story(
            self::TOPIC_BADGES[$topic ?? ''] ?? $desk,
            in_array($topic, self::CAUTION_TOPICS, true) ? Tone::Warn : Tone::Accent,
            $card === NewsCard::Government ? 'account_balance' : 'public',
            $description,
        );
    }

    /**
     * Splits sub-actions (dividend, buyback, bond issuance, narrative lore) into individual pills.
     *
     * @return list<array{kind: string, text: string}>
     */
    private function parseSubActionPills(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        // Split by newlines or bullet markers (•, -, *)
        $rawLines = preg_split('/(?:\r\n|\r|\n)+|\s*[•*·]\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $pills = [];

        foreach ($rawLines as $line) {
            $line = trim($line, " \t\n\r\0\x0B•*·-");
            if ($line === '') {
                continue;
            }

            // 1. Dividend: e.g. "Paid $0.61/share div ($27.64B total, 1.97% yield)."
            if (preg_match('/Paid\s+([+-]?\$?[\d,.]+)\/share\s+div(?:\s*\(([^)]+)\))?/i', $line, $divMatch)) {
                $divPerShare = $divMatch[1];
                $rawDetails = $divMatch[2] ?? '';
                if (preg_match('/([\d,.]+)%\s*yield/i', $rawDetails, $yMatch)) {
                    $divText = "Paid {$divPerShare}/sh · {$yMatch[1]}% yield";
                } elseif (!empty($rawDetails)) {
                    $divText = "Paid {$divPerShare}/sh ({$rawDetails})";
                } else {
                    $divText = "Paid {$divPerShare}/sh";
                }
                $pills[] = ['kind' => 'dividend', 'text' => $divText];
                continue;
            }

            // 2. Buyback: e.g. "Bought back 410,288,930 shares."
            if (preg_match('/Bought\s+back\s+([\d,]+)\s+shares/i', $line, $bbMatch)) {
                $shareCount = $bbMatch[1];
                $cleanNum = (float) str_replace(',', '', $shareCount);
                $formattedCount = $this->formatShares($cleanNum);
                $pills[] = ['kind' => 'buyback', 'text' => "Repurchased {$formattedCount} shares"];
                continue;
            }

            // 3. Bonds / Debt: e.g. "Issued $18.31B in bonds for expansion."
            if (preg_match('/Issued\s+([+-]?\$?[\d,.]+[BMTk]?)\s+in\s+bonds(?:\s+for\s+([^.]+))?/i', $line, $debtMatch)) {
                $amount = $debtMatch[1];
                $purpose = !empty($debtMatch[2]) ? ' (' . trim($debtMatch[2]) . ')' : '';
                $pills[] = ['kind' => 'bonds', 'text' => "Issued {$amount} bonds{$purpose}"];
                continue;
            }

            // 4. Strategic Narrative / Lore / News highlight
            $pills[] = ['kind' => 'note', 'text' => rtrim($line, '.')];
        }

        return $pills;
    }

    private function formatShares(float $num): string
    {
        if ($num >= 1_000_000_000) {
            return number_format($num / 1_000_000_000, 2) . 'B';
        }
        if ($num >= 1_000_000) {
            return number_format($num / 1_000_000, 2) . 'M';
        }
        if ($num >= 1_000) {
            return number_format($num / 1_000, 1) . 'K';
        }

        return number_format($num);
    }
}
