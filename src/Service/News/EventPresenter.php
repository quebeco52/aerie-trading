<?php

namespace App\Service\News;

use App\Data\District\DistrictCalendar;
use App\Entity\DistrictNews;
use App\Entity\EtfEvent;
use App\Entity\StockEvent;
use App\Service\Event\ShockEvent;

/**
 * Transforms raw corporate and market events into rich, structured presentation view models
 * for clean, highly readable financial card layouts in Twig and APIs.
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
        ShockEvent::AUTHORITY_PRESSED => 'MONETARY POLICY',
        ShockEvent::AUTHORITY_GIVES_GROUND => 'MONETARY POLICY',
        ShockEvent::AUTHORITY_MAJORITY_SHIFT => 'MONETARY POLICY',
        ShockEvent::MONETARY_DECISION => 'RATE DECISION',
        ShockEvent::PARTY_LEADER_CHANGED => 'PARTY LEADER',
        ShockEvent::COUNCILLOR_SEATED => 'COUNCIL SEAT',
    ];

    /** What a price shock's description said before it named the move. */
    private const LEGACY_SHOCK_TEXT = 'Sudden market shock detected.';

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
        $simTime = null;
        if ($event instanceof DistrictNews) {
            $rawType = $event->getDesk();
            $topic = $event->getTopic();
            $rawDesc = $event->getDescription();
            $changePct = $event->getChangePercent() !== null ? (float) $event->getChangePercent() : null;
            $recordedAt = $event->getRecordedAt();
            $simTime = $event->getSimTime();
        } elseif ($event instanceof StockEvent || $event instanceof EtfEvent) {
            $rawType = strtoupper((string) $event->getEventType());
            $rawDesc = (string) $event->getDescription();
            $changePct = $event->getChangePercent() !== null ? (float) $event->getChangePercent() : null;
            $recordedAt = $event->getRecordedAt();
            $simTime = $event->getSimTime();
        } else {
            $rawType = strtoupper((string) ($event['type'] ?? $event['eventType'] ?? 'EVENT'));
            $rawDesc = (string) ($event['description'] ?? '');
            $changePct = isset($event['change_percent']) ? (float) $event['change_percent'] : (isset($event['changePercent']) ? (float) $event['changePercent'] : null);
            $topic = isset($event['topic']) ? (string) $event['topic'] : null;
            $simTime = isset($event['sim_time']) ? (float) $event['sim_time'] : (isset($event['simTime']) ? (float) $event['simTime'] : null);
            $recordedAt = isset($event['recorded_at']) ? new \DateTime((string) $event['recorded_at']) : (isset($event['recordedAt']) ? ($event['recordedAt'] instanceof \DateTimeInterface ? $event['recordedAt'] : new \DateTime((string) $event['recordedAt'])) : new \DateTime());
        }

        $card = match (EventCategory::forType($rawType)) {
            'earnings' => $this->presentEarnings($rawType, $rawDesc, $changePct, $recordedAt),
            'shock' => $this->presentShock($rawType, $rawDesc, $changePct, $recordedAt),
            'split' => $this->presentSplit($rawType, $rawDesc, $changePct, $recordedAt),
            'debt' => $this->presentDebt($rawType, $rawDesc, $changePct, $recordedAt),
            'mna' => $this->presentMna($rawType, $rawDesc, $changePct, $recordedAt),
            'analyst' => $this->presentAnalyst($rawType, $rawDesc, $changePct, $recordedAt),
            'bankruptcy' => $this->presentBankruptcy($rawType, $rawDesc, $changePct, $recordedAt),
            'reorganization' => $this->presentReorganization($rawType, $rawDesc, $changePct, $recordedAt),
            'district' => $this->presentDistrict($rawType, $rawDesc, $changePct, $recordedAt),
            'index' => $this->presentIndex($rawType, $rawDesc, $changePct, $recordedAt),
            'income' => $this->presentDistribution($rawType, $rawDesc, $changePct, $recordedAt),
            'governance' => $this->presentSuccession($rawType, $rawDesc, $changePct, $recordedAt),
            'economy', 'government' => $this->presentDistrictStory($rawType, $topic, $rawDesc, $changePct, $recordedAt),
            default => $this->presentGeneral($rawType, $rawDesc, $changePct, $recordedAt),
        };

        // Dated in the District's calendar; a row from before events carried their simulation time keeps its clock time.
        $card['simTime'] = $simTime;
        $card['dateline'] = $simTime !== null ? DistrictCalendar::dateline($simTime) : $recordedAt->format('Y-m-d H:i');

        return $card;
    }

    /** A price shock as a wire line: "Hummock Foods shares jump 12.4% in a sudden move." */
    public static function shockHeadline(string $subject, float $changePercent): string
    {
        return sprintf('%s %s %s%% in a sudden move.', $subject, $changePercent >= 0.0 ? 'jump' : 'drop', number_format(abs($changePercent), 1));
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
     * @return array<string, mixed>
     */
    private function presentEarnings(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        $eps = null;
        $surpriseType = null; // 'beat', 'miss', 'met'
        $surpriseAmount = null;
        $eva = null;
        $evaPositive = true;
        $headline = 'Quarterly Earnings Report';
        $remainingText = $rawDesc;

        // Pattern matching: "Q-Earnings: $2.26 (Beat expectations by $0.01 | +$80.92B EVA)."
        $pattern = '/^Q-Earnings:\s*([+-]?\$?[\d,.]+(?:\s*[BMTk])?)\s*\((Beat expectations by|Missed expectations by|Met expectations(?: exactly)?)\s*([+-]?\$?[\d,.]+(?:\s*[BMTk])?)?\s*\|\s*([+-]?\$?[\d,.]+[BMTk]?\s*EVA)\)\.?/i';
        if (preg_match($pattern, $rawDesc, $matches)) {
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
            $remainingText = trim(substr($rawDesc, strlen($matches[0])));
        }

        $pills = $this->parseSubActionPills($remainingText);

        $badge = 'EARNINGS IN-LINE';
        $badgeClass = 'badge-accent';
        $borderClass = 'border-l-primary';
        $icon = 'equalizer';
        $iconClass = 'bg-primary/15 text-primary';
        $surpriseText = 'In-Line';

        if ($surpriseType === 'beat') {
            $badge = 'EARNINGS BEAT';
            $badgeClass = 'badge-up';
            $borderClass = 'border-l-secondary';
            $icon = 'trending_up';
            $iconClass = 'bg-secondary/15 text-secondary';
            $surpriseText = $surpriseAmount ? "Beat by +{$surpriseAmount}" : 'Beat Expectations';
        } elseif ($surpriseType === 'miss') {
            $badge = 'EARNINGS MISS';
            $badgeClass = 'badge-down';
            $borderClass = 'border-l-tertiary';
            $icon = 'trending_down';
            $iconClass = 'bg-tertiary/15 text-tertiary';
            $surpriseText = $surpriseAmount ? "Missed by {$surpriseAmount}" : 'Missed Expectations';
        }

        return [
            'type' => $type,
            'category' => 'earnings',
            'badge' => $badge,
            'badgeClass' => $badgeClass,
            'borderClass' => $borderClass,
            'icon' => $icon,
            'iconClass' => $iconClass,
            'isEarnings' => true,
            'headline' => $headline,
            'eps' => $eps,
            'surpriseType' => $surpriseType,
            'surpriseAmount' => $surpriseAmount,
            'surpriseText' => $surpriseText,
            'eva' => $eva,
            'evaPositive' => $evaPositive,
            'pills' => $pills,
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentShock(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        $isPositive = $changePct !== null ? $changePct >= 0 : true;
        // Shocks were once published as a bare log line; read with their move, they say what the shares did.
        $headline = match (true) {
            $rawDesc !== '' && $rawDesc !== self::LEGACY_SHOCK_TEXT => $rawDesc,
            $changePct !== null => self::shockHeadline('Shares', $changePct),
            default => 'Shares moved sharply.',
        };

        return [
            'type' => $type,
            'category' => 'shock',
            'badge' => 'MARKET SHOCK',
            'badgeClass' => $isPositive ? 'badge-warn' : 'badge-down',
            'borderClass' => $isPositive ? 'border-l-warning' : 'border-l-tertiary',
            'icon' => 'bolt',
            'iconClass' => $isPositive ? 'bg-warning/15 text-warning' : 'bg-tertiary/15 text-tertiary',
            'isEarnings' => false,
            'headline' => $headline,
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentSplit(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        $isReverse = in_array($type, ['REVERSE_SPLIT', 'REVSPLIT'], true);
        $badge = $isReverse ? 'REVERSE STOCK SPLIT' : 'STOCK SPLIT';
        $headline = !empty($rawDesc) ? $rawDesc : ($isReverse ? 'Reverse stock split executed.' : 'Stock split executed.');

        return [
            'type' => $type,
            'category' => 'split',
            'badge' => $badge,
            'badgeClass' => 'badge-neutral',
            'borderClass' => 'border-l-outline-variant',
            'icon' => 'call_split',
            'iconClass' => 'bg-surface-container-high text-on-surface-variant',
            'isEarnings' => false,
            'headline' => $headline,
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentDebt(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        $isUpgrade = in_array($type, ['RATING_UPGRADE', 'CREDIT_UPGRADE'], true) || ($type === 'DEBT' && str_contains(strtolower($rawDesc), 'upgrade'));
        $isDowngrade = in_array($type, ['RATING_DOWNGRADE', 'CREDIT_DOWNGRADE'], true);
        $badge = $isUpgrade ? 'RATING UPGRADE' : ($isDowngrade ? 'RATING DOWNGRADE' : 'CREDIT RATING');
        // The debt engine writes "[CREDIT DOWNGRADE] TICK: ..." for the log; the badge already says both.
        $rawDesc = (string) preg_replace('/^\[[A-Z ]+\]\s*[A-Z0-9.]+:\s*/', '', $rawDesc);
        $badgeClass = $isUpgrade ? 'badge-up' : 'badge-down';
        $borderClass = $isUpgrade ? 'border-l-secondary' : 'border-l-tertiary';
        $icon = $isUpgrade ? 'credit_score' : 'warning';
        $iconClass = $isUpgrade ? 'bg-secondary/15 text-secondary' : 'bg-tertiary/15 text-tertiary';

        return [
            'type' => $type,
            'category' => 'debt',
            'badge' => $badge,
            'badgeClass' => $badgeClass,
            'borderClass' => $borderClass,
            'icon' => $icon,
            'iconClass' => $iconClass,
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Credit rating event.',
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMna(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        // The deal type is already the headline a desk would print (STRATEGIC ACQUISITION, LEVERAGED BUYOUT…).
        return [
            'type' => $type,
            'category' => 'mna',
            'badge' => $type,
            'badgeClass' => 'badge-accent',
            'borderClass' => 'border-l-primary',
            'icon' => $type === 'DIVESTITURE' ? 'domain_disabled' : 'domain_add',
            'iconClass' => 'bg-primary/15 text-primary',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Corporate restructuring announcement.',
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * The sell side and management talking about the number rather than reporting it: a price-target
     * revision (StockTracker) or a guidance cut (EarningsEngine). A cut reads down; a raise reads up.
     *
     * @return array<string, mixed>
     */
    private function presentAnalyst(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        $isGuidance = $type === 'GUIDANCE';
        $isCut = $isGuidance || str_contains(strtolower($rawDesc), ' cut ');

        return [
            'type' => $type,
            'category' => 'analyst',
            'badge' => $isGuidance ? 'GUIDANCE CUT' : ($isCut ? 'TARGET CUT' : 'TARGET RAISED'),
            'badgeClass' => $isCut ? 'badge-down' : 'badge-up',
            'borderClass' => $isCut ? 'border-l-tertiary' : 'border-l-secondary',
            'icon' => $isGuidance ? 'campaign' : 'query_stats',
            'iconClass' => $isCut ? 'bg-tertiary/15 text-tertiary' : 'bg-secondary/15 text-secondary',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Analyst revision.',
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * A Glasswater Row roster change — promotion onto or eviction from the street at the quarterly
     * reconstitution (MarketTickerCommand via DistrictRoster). Not a price event: the change is
     * whatever the description says, and no move is implied.
     */
    private function presentDistrict(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        $evicted = str_contains(strtolower($rawDesc), 'lost');

        return [
            'type' => $type,
            'category' => 'district',
            'badge' => $evicted ? 'STREET EVICTION' : 'STREET PROMOTION',
            'badgeClass' => $evicted
                ? 'badge-warn'
                : 'badge-accent',
            'borderClass' => $evicted ? 'border-l-warning' : 'border-l-primary',
            'icon' => 'location_city',
            'iconClass' => $evicted ? 'bg-warning/20 text-warning' : 'bg-primary/20 text-primary',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Glasswater Row roster reconstituted.',
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * A district-wide story from the economy or government desk (SystemicEventReporter). The number is what the
     * benchmark did over the month before it, so it carries the sign colour and the badge only says what happened.
     *
     * @return array<string, mixed>
     */
    private function presentDistrictStory(string $type, ?string $topic, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        $caution = $topic !== null && in_array($topic, self::CAUTION_TOPICS, true);

        return [
            'type' => $type,
            'category' => $type === DistrictNews::DESK_GOVERNMENT ? 'government' : 'economy',
            'topic' => $topic,
            'badge' => self::TOPIC_BADGES[$topic ?? ''] ?? $type,
            'badgeClass' => $caution ? 'badge-warn' : 'badge-accent',
            'borderClass' => $caution ? 'border-l-warning' : 'border-l-primary',
            'icon' => $type === DistrictNews::DESK_GOVERNMENT ? 'account_balance' : 'public',
            'iconClass' => $caution ? 'bg-warning/15 text-warning' : 'bg-primary/10 text-primary',
            'isEarnings' => false,
            'headline' => $rawDesc,
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * An index reconstitution (App\Service\Market\Index\IndexCommittee): who was admitted and who was dropped. Not
     * a price event — the divisor is restated across the change, so the level itself does not move on it.
     *
     * @return array<string, mixed>
     */
    private function presentIndex(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        return [
            'type' => $type,
            'category' => 'index',
            'badge' => 'INDEX RECONSTITUTION',
            'badgeClass' => 'badge-accent',
            'borderClass' => 'border-l-primary',
            'icon' => 'checklist',
            'iconClass' => 'bg-primary/20 text-primary',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Index membership reconstituted.',
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * A fund distribution (App\Service\Market\Index\IndexFundAccountant): dividend cash the fund collected from
     * its constituents and passed on to its holders.
     *
     * Not a price event, though the price falls by the payment on the same tick. The holder has the cash
     * instead, so nothing was made or lost and the card must not read as a drop — which is exactly why it
     * carries its own presentation rather than falling through to the generic one.
     *
     * @return array<string, mixed>
     */
    private function presentDistribution(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        return [
            'type' => $type,
            'category' => 'income',
            'badge' => 'DISTRIBUTION',
            'badgeClass' => 'badge-up',
            'borderClass' => 'border-l-secondary',
            'icon' => 'payments',
            'iconClass' => 'bg-secondary/20 text-secondary',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Fund distribution paid.',
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * A change at the top (App\Service\Corporate\ManagementSuccessionEngine). Not a price event — the
     * value in it is the policy that follows, which the engines price through the dials the incoming
     * style moves, so the card states what changed and implies no move of its own.
     *
     * @return array<string, mixed>
     */
    private function presentSuccession(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        $dismissed = str_contains(strtolower($rawDesc), 'removed by the board');

        return [
            'type' => $type,
            'category' => 'governance',
            'badge' => $dismissed ? 'BOARD REMOVAL' : 'MANAGEMENT CHANGE',
            'badgeClass' => $dismissed
                ? 'badge-warn'
                : 'badge-neutral',
            'borderClass' => $dismissed ? 'border-l-warning' : 'border-l-primary',
            'icon' => $dismissed ? 'gavel' : 'badge',
            'iconClass' => $dismissed ? 'bg-warning/20 text-warning' : 'bg-primary/10 text-primary',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'The company named new management.',
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /** A Chapter 11 plan: the company keeps trading, so it reads as a restructuring rather than a death. */
    private function presentReorganization(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        return [
            'type' => $type,
            'category' => 'reorganization',
            'badge' => 'CHAPTER 11',
            'badgeClass' => 'badge-warn',
            'borderClass' => 'border-l-warning',
            'icon' => 'balance',
            'iconClass' => 'bg-warning/20 text-warning',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Confirmed a Chapter 11 plan of reorganization.',
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    private function presentBankruptcy(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        return [
            'type' => $type,
            'category' => 'bankruptcy',
            'badge' => 'BANKRUPTCY',
            'badgeClass' => 'badge-down',
            'borderClass' => 'border-l-tertiary',
            'icon' => 'gavel',
            'iconClass' => 'bg-tertiary/20 text-tertiary',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Filed for Chapter 7 bankruptcy liquidation.',
            'pills' => [],
            'changePercent' => $changePct ?? -100.0,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentGeneral(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        return [
            'type' => $type,
            'category' => 'general',
            'badge' => $type,
            'badgeClass' => 'badge-neutral',
            'borderClass' => 'border-l-primary',
            'icon' => 'campaign',
            'iconClass' => 'bg-primary/10 text-primary',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Corporate news announcement.',
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * Splits sub-actions (dividend, buyback, bond issuance, narrative lore) into individual pills.
     *
     * @return list<array{type: string, icon: string, label: string, text: string, pillClass: string}>
     */
    private function parseSubActionPills(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        // Split by newlines or bullet markers (•, -, *)
        $rawLines = preg_split('/(?:\r\n|\r|\n)+|\s*[•*·]\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY);
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
                $pills[] = [
                    'type' => 'dividend',
                    'icon' => 'payments',
                    'label' => 'Dividend',
                    'text' => $divText,
                    'pillClass' => 'badge-up',
                ];
                continue;
            }

            // 2. Buyback: e.g. "Bought back 410,288,930 shares."
            if (preg_match('/Bought\s+back\s+([\d,]+)\s+shares/i', $line, $bbMatch)) {
                $shareCount = $bbMatch[1];
                $cleanNum = (float) str_replace(',', '', $shareCount);
                $formattedCount = $this->formatShares($cleanNum);
                $pills[] = [
                    'type' => 'buyback',
                    'icon' => 'published_with_changes',
                    'label' => 'Buyback',
                    'text' => "Repurchased {$formattedCount} shares",
                    'pillClass' => 'badge-accent',
                ];
                continue;
            }

            // 3. Bonds / Debt: e.g. "Issued $18.31B in bonds for expansion."
            if (preg_match('/Issued\s+([+-]?\$?[\d,.]+[BMTk]?)\s+in\s+bonds(?:\s+for\s+([^.]+))?/i', $line, $debtMatch)) {
                $amount = $debtMatch[1];
                $purpose = !empty($debtMatch[2]) ? ' (' . trim($debtMatch[2]) . ')' : '';
                $pills[] = [
                    'type' => 'debt',
                    'icon' => 'receipt_long',
                    'label' => 'Bonds',
                    'text' => "Issued {$amount} bonds{$purpose}",
                    'pillClass' => 'badge-warn',
                ];
                continue;
            }

            // 4. Strategic Narrative / Lore / News highlight
            $pills[] = [
                'type' => 'lore',
                'icon' => 'feed',
                'label' => 'Corporate News',
                'text' => rtrim($line, '.'),
                'pillClass' => 'badge-accent',
            ];
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
