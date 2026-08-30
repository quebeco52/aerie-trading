<?php

namespace App\Service\Event;

use App\Entity\EtfEvent;
use App\Entity\StockEvent;

/**
 * Transforms raw corporate and market events into rich, structured presentation view models
 * for clean, highly readable financial card layouts in Twig and APIs.
 */
class EventPresenter
{
    /**
     * Presents an event entity or associative array into a structured view model.
     *
     * @param StockEvent|EtfEvent|array<string, mixed> $event
     * @return array<string, mixed>
     */
    public function present(StockEvent|EtfEvent|array $event): array
    {
        if ($event instanceof StockEvent || $event instanceof EtfEvent) {
            $rawType = strtoupper((string) $event->getEventType());
            $rawDesc = (string) $event->getDescription();
            $changePct = $event->getChangePercent() !== null ? (float) $event->getChangePercent() : null;
            $recordedAt = $event->getRecordedAt();
        } else {
            $rawType = strtoupper((string) ($event['type'] ?? $event['eventType'] ?? 'EVENT'));
            $rawDesc = (string) ($event['description'] ?? '');
            $changePct = isset($event['change_percent']) ? (float) $event['change_percent'] : (isset($event['changePercent']) ? (float) $event['changePercent'] : null);
            $recordedAt = isset($event['recorded_at']) ? new \DateTime((string) $event['recorded_at']) : (isset($event['recordedAt']) ? ($event['recordedAt'] instanceof \DateTimeInterface ? $event['recordedAt'] : new \DateTime((string) $event['recordedAt'])) : new \DateTime());
        }

        if ($rawType === 'EARNINGS') {
            return $this->presentEarnings($rawType, $rawDesc, $changePct, $recordedAt);
        }

        if ($rawType === 'SHOCK') {
            return $this->presentShock($rawType, $rawDesc, $changePct, $recordedAt);
        }

        if (in_array($rawType, ['SPLIT', 'REVERSE_SPLIT', 'REVSPLIT'], true)) {
            return $this->presentSplit($rawType, $rawDesc, $changePct, $recordedAt);
        }

        if (in_array($rawType, ['RATING_UPGRADE', 'RATING_DOWNGRADE', 'DEBT'], true)) {
            return $this->presentDebt($rawType, $rawDesc, $changePct, $recordedAt);
        }

        if (in_array($rawType, ['ACQUISITION', 'MERGER', 'DIVESTITURE'], true)) {
            return $this->presentMna($rawType, $rawDesc, $changePct, $recordedAt);
        }

        if ($rawType === 'BANKRUPTCY') {
            return $this->presentBankruptcy($rawType, $rawDesc, $changePct, $recordedAt);
        }

        return $this->presentGeneral($rawType, $rawDesc, $changePct, $recordedAt);
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
            $headline = "Q-Earnings: {$eps}";
            $remainingText = trim(substr($rawDesc, strlen($matches[0])));
        }

        $pills = $this->parseSubActionPills($remainingText);

        $badge = 'EARNINGS IN-LINE';
        $badgeClass = 'bg-primary/10 text-primary border-primary/20';
        $borderClass = 'border-l-primary';
        $icon = 'equalizer';
        $iconClass = 'bg-primary/15 text-primary';
        $surpriseText = 'In-Line';

        if ($surpriseType === 'beat') {
            $badge = 'EARNINGS BEAT';
            $badgeClass = 'bg-secondary/10 text-secondary border-secondary/30';
            $borderClass = 'border-l-secondary';
            $icon = 'trending_up';
            $iconClass = 'bg-secondary/15 text-secondary';
            $surpriseText = $surpriseAmount ? "Beat by +{$surpriseAmount}" : 'Beat Expectations';
        } elseif ($surpriseType === 'miss') {
            $badge = 'EARNINGS MISS';
            $badgeClass = 'bg-tertiary/10 text-tertiary border-tertiary/30';
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
        $headline = !empty($rawDesc) ? $rawDesc : 'Sudden market shock detected.';

        return [
            'type' => $type,
            'category' => 'shock',
            'badge' => 'MARKET SHOCK',
            'badgeClass' => $isPositive ? 'bg-amber-500/10 text-amber-300 border-amber-500/30' : 'bg-tertiary/10 text-tertiary border-tertiary/30',
            'borderClass' => $isPositive ? 'border-l-amber-400' : 'border-l-tertiary',
            'icon' => 'bolt',
            'iconClass' => $isPositive ? 'bg-amber-500/15 text-amber-300' : 'bg-tertiary/15 text-tertiary',
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
            'badgeClass' => 'bg-purple-500/10 text-purple-300 border-purple-500/30',
            'borderClass' => 'border-l-purple-400',
            'icon' => 'call_split',
            'iconClass' => 'bg-purple-500/15 text-purple-300',
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
        $isUpgrade = $type === 'RATING_UPGRADE' || (str_contains(strtolower($rawDesc), 'upgrade'));
        $badge = $isUpgrade ? 'RATING UPGRADE' : ($type === 'RATING_DOWNGRADE' ? 'RATING DOWNGRADE' : 'CREDIT RATING');
        $badgeClass = $isUpgrade ? 'bg-secondary/10 text-secondary border-secondary/30' : 'bg-tertiary/10 text-tertiary border-tertiary/30';
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
        $badge = match ($type) {
            'ACQUISITION' => 'ACQUISITION',
            'DIVESTITURE' => 'DIVESTITURE',
            'MERGER' => 'MERGER',
            default => 'M&A EVENT'
        };

        return [
            'type' => $type,
            'category' => 'mna',
            'badge' => $badge,
            'badgeClass' => 'bg-cyan-500/10 text-cyan-300 border-cyan-500/30',
            'borderClass' => 'border-l-cyan-400',
            'icon' => 'domain_add',
            'iconClass' => 'bg-cyan-500/15 text-cyan-300',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Corporate restructuring announcement.',
            'pills' => [],
            'changePercent' => $changePct,
            'recordedAt' => $recordedAt,
            'rawDescription' => $rawDesc,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBankruptcy(string $type, string $rawDesc, ?float $changePct, \DateTimeInterface $recordedAt): array
    {
        return [
            'type' => $type,
            'category' => 'bankruptcy',
            'badge' => 'BANKRUPTCY',
            'badgeClass' => 'bg-red-500/15 text-red-400 border-red-500/40',
            'borderClass' => 'border-l-red-500',
            'icon' => 'gavel',
            'iconClass' => 'bg-red-500/20 text-red-400',
            'isEarnings' => false,
            'headline' => !empty($rawDesc) ? $rawDesc : 'Filed for Chapter 11 bankruptcy liquidation.',
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
            'badgeClass' => 'bg-surface-container-high text-on-surface-variant border-outline-variant/30',
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
                    'pillClass' => 'bg-emerald-500/10 text-emerald-300 border-emerald-500/25',
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
                    'pillClass' => 'bg-cyan-500/10 text-cyan-300 border-cyan-500/25',
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
                    'pillClass' => 'bg-amber-500/10 text-amber-300 border-amber-500/25',
                ];
                continue;
            }

            // 4. Strategic Narrative / Lore / News highlight
            $pills[] = [
                'type' => 'lore',
                'icon' => 'feed',
                'label' => 'Corporate News',
                'text' => rtrim($line, '.'),
                'pillClass' => 'bg-primary/10 text-primary border-primary/20',
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
