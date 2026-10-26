<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Entity\DistrictNews;
use App\Entity\EtfEvent;
use App\Entity\StockEvent;
use App\Service\Event\ShockEvent;
use App\Twig\Extension\UiExtension;

/**
 * Turns a published event into the card the stock page, the newswire, the live feed and the district map all render:
 * its badge, tone, icon and headline, by the card its type routes to (NewsCard).
 *
 * @phpstan-type Earnings array{eps?: string|null, surpriseType?: string|null, surpriseAmount?: string|null, surpriseText?: string|null, eva?: string|null, evaPositive?: bool}
 * @phpstan-type Story array{badge: string, tone: Tone, icon: string, headline: string, pills: list<array{kind: string, text: string}>, earnings: Earnings}
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

    /** The card a published event renders as, whether a stored row or a story about to go on the wire. */
    public function present(StockEvent|EtfEvent|DistrictNews|PresentableEvent $event): EventCard
    {
        $event = $event instanceof PresentableEvent ? $event : PresentableEvent::of($event);
        $type = strtoupper($event->type);
        $description = $event->description;
        $facts = $event->facts;
        $changePct = $event->changePercent;

        $card = NewsCard::forType($type);
        $story = match ($card) {
            NewsCard::Earnings => self::earnings($facts),
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
            NewsCard::Rating => $type === 'CREDIT_UPGRADE'
                ? self::story('RATING UPGRADE', Tone::Up, 'credit_score', $description ?: 'Credit rating upgraded.')
                : self::story('RATING DOWNGRADE', Tone::Down, 'warning', $description ?: 'Credit rating downgraded.'),
            // The deal type is already the headline a desk would print (STRATEGIC ACQUISITION, LEVERAGED BUYOUT…).
            NewsCard::Deal => self::story($type, Tone::Accent, in_array($type, ['DIVESTITURE', 'MARKET EXIT'], true) ? 'domain_disabled' : 'domain_add', $description ?: 'Corporate restructuring announcement.'),
            NewsCard::Analyst => self::analyst($type, $description, $facts),
            NewsCard::Bankruptcy => self::story('BANKRUPTCY', Tone::Down, 'gavel', $description ?: 'Filed for Chapter 7 bankruptcy liquidation.'),
            // The company keeps trading, so a Chapter 11 plan reads as a restructuring rather than a death.
            NewsCard::Reorganization => self::story('CHAPTER 11', Tone::Warn, 'balance', $description ?: 'Confirmed a Chapter 11 plan of reorganisation.'),
            // Not a price event: the roster changes at the quarterly reconstitution and no move is implied.
            NewsCard::Street => ($facts['promoted'] ?? true)
                ? self::story('STREET PROMOTION', Tone::Accent, 'location_city', $description ?: 'Promoted to Glasswater Row.')
                : self::story('STREET EVICTION', Tone::Warn, 'location_city', $description ?: 'Lost its frontage on Glasswater Row.'),
            // The divisor is restated across a reconstitution, so the level itself does not move on it.
            NewsCard::Index => self::story('INDEX RECONSTITUTION', Tone::Accent, 'checklist', $description ?: 'Index membership reconstituted.'),
            // The price falls by the payment, but the holder has the cash, so it must not read as a drop.
            NewsCard::Distribution => self::story('DISTRIBUTION', Tone::Up, 'payments', $description ?: 'Fund distribution paid.'),
            // Not a price event: the value in it is the policy that follows, which the engines price through the dials
            // the incoming style moves.
            NewsCard::Succession => ($facts['forced'] ?? false)
                ? self::story('BOARD REMOVAL', Tone::Warn, 'gavel', $description ?: 'The board removed the chief executive.')
                : self::story('MANAGEMENT CHANGE', Tone::Neutral, 'badge', $description ?: 'The company named new management.'),
            // Not a price event: a revised outlook reaches the price through what the company is worth.
            NewsCard::Outlook => (float) ($facts['revision'] ?? 0.0) < 0.0
                ? self::story('OUTLOOK CUT', Tone::Down, 'insights', $description ?: 'Industry demand outlook cut.')
                : self::story('OUTLOOK RAISED', Tone::Up, 'insights', $description ?: 'Industry demand outlook raised.'),
            NewsCard::Economy, NewsCard::Government => self::districtStory($card, $type, $event->topic, $description),
            NewsCard::General => self::story($type, Tone::Neutral, 'campaign', $description ?: 'Corporate news announcement.'),
        };

        return new EventCard(
            type: $type,
            card: $card,
            badge: $story['badge'],
            tone: $story['tone'],
            icon: $story['icon'],
            headline: $story['headline'],
            changePercent: $card === NewsCard::Bankruptcy ? ($changePct ?? -100.0) : $changePct,
            recordedAt: $event->recordedAt,
            simTime: $event->simTime,
            facts: $facts,
            pills: $story['pills'],
            eps: $story['earnings']['eps'] ?? null,
            surpriseType: $story['earnings']['surpriseType'] ?? null,
            surpriseAmount: $story['earnings']['surpriseAmount'] ?? null,
            surpriseText: $story['earnings']['surpriseText'] ?? null,
            eva: $story['earnings']['eva'] ?? null,
            evaPositive: $story['earnings']['evaPositive'] ?? true,
        );
    }

    /** A price shock as a wire line: "Hummock Foods shares jump 12.4% in a sudden move." */
    public static function shockHeadline(string $subject, float $changePercent): string
    {
        return sprintf('%s %s %s%% in a sudden move.', $subject, $changePercent >= 0.0 ? 'jump' : 'drop', number_format(abs($changePercent), 1));
    }

    /**
     * What a card says: its badge, tone, icon and headline, plus the pills under it and, for earnings, the figures.
     *
     * @param  list<array{kind: string, text: string}> $pills
     * @param  Earnings                                $earnings
     * @return Story
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
     * A quarterly report from its facts (EarningsEngine): adjusted earnings a share and the surprise against consensus,
     * both rounded to the cent, the quarter's economic profit, and the actions that came with it. A surprise under a
     * cent is in line.
     *
     * @param  array<string, mixed> $facts
     * @return Story
     */
    private static function earnings(array $facts): array
    {
        $eps = isset($facts['eps']) ? UiExtension::money((float) $facts['eps']) : null;
        $surprise = (float) ($facts['surprise'] ?? 0.0);
        $surpriseType = match (true) {
            $eps === null => null,
            $surprise >= 0.01 => 'beat',
            $surprise <= -0.01 => 'miss',
            default => 'met',
        };
        $surpriseAmount = in_array($surpriseType, ['beat', 'miss'], true) ? UiExtension::money(abs($surprise)) : null;
        $economicProfit = isset($facts['economicProfit']) ? (float) $facts['economicProfit'] : null;

        [$badge, $tone, $icon, $surpriseText] = match ($surpriseType) {
            'beat' => ['EARNINGS BEAT', Tone::Up, 'trending_up', "Beat by +{$surpriseAmount}"],
            'miss' => ['EARNINGS MISS', Tone::Down, 'trending_down', "Missed by {$surpriseAmount}"],
            'met' => ['EARNINGS IN-LINE', Tone::Accent, 'equalizer', 'In-Line'],
            // A report stored before stories carried their facts says only that it was a report.
            default => ['EARNINGS', Tone::Neutral, 'equalizer', null],
        };

        return self::story(
            $badge,
            $tone,
            $icon,
            $eps !== null ? self::earningsHeadline($eps, $surpriseType, $surpriseAmount) : 'Quarterly earnings report.',
            self::pills(is_array($facts['actions'] ?? null) ? $facts['actions'] : []),
            [
                'eps' => $eps,
                'surpriseType' => $surpriseType,
                'surpriseAmount' => $surpriseAmount,
                'surpriseText' => $surpriseText,
                'eva' => $economicProfit !== null ? WireFormat::money($economicProfit, true) . ' EVA' : null,
                'evaPositive' => $economicProfit === null || $economicProfit >= 0.0,
            ],
        );
    }

    /**
     * The sell side and management talking about the number rather than reporting it: a price-target
     * revision (StockTracker, with the old and new target as facts) or a guidance cut (EarningsEngine). A cut reads
     * down; a raise reads up.
     *
     * @param array<string, mixed> $facts
     *
     * @return Story
     */
    private static function analyst(string $type, string $description, array $facts): array
    {
        $headline = $description ?: 'Analyst revision.';

        return match (true) {
            $type === 'GUIDANCE' => self::story('GUIDANCE CUT', Tone::Down, 'campaign', $headline),
            (float) ($facts['to'] ?? 0.0) < (float) ($facts['from'] ?? 0.0) => self::story('TARGET CUT', Tone::Down, 'query_stats', $headline),
            default => self::story('TARGET RAISED', Tone::Up, 'query_stats', $headline),
        };
    }

    /**
     * A district-wide story from the economy or government desk (SystemicEventReporter). The number is what the
     * benchmark did over the month before it, so it carries the sign colour and the badge only says what happened.
     *
     * @return Story
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
     * The actions that came with a report, one line each: a dividend, buyback or bond issue from its figures, anything
     * else as the note the engine wrote.
     *
     * @param  array<mixed>                            $actions
     * @return list<array{kind: string, text: string}>
     */
    private static function pills(array $actions): array
    {
        $pills = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }

            $kind = (string) ($action['kind'] ?? 'note');
            $text = match ($kind) {
                'dividend' => sprintf('Paid %s/sh · %s yield', UiExtension::money((float) $action['perShare']), UiExtension::percent((float) $action['yield'])),
                'buyback' => sprintf('Repurchased %s shares', WireFormat::shares((float) $action['shares'])),
                'bonds' => sprintf('Issued %s bonds (%s)', WireFormat::money((float) $action['amount']), (string) $action['purpose']),
                default => rtrim((string) ($action['text'] ?? ''), '.'),
            };
            if ($text !== '') {
                $pills[] = ['kind' => $kind, 'text' => $text];
            }
        }

        return $pills;
    }
}
