<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

use App\DTO\MacroStateDTO;
use App\Entity\Etf;
use App\Entity\Stock;
use App\Service\Event\MarketEventPublisher;
use App\Service\Market\Flow\OrderFlowStoreInterface;
use App\Service\Market\Index\AuthorizedParticipant;
use App\Service\Market\Index\EtfTracker;
use App\Service\Market\Index\IndexCommittee;
use App\Service\Market\Index\IndexFundAccountant;
use App\Service\Market\Index\MarketIndex;
use App\Service\Math\FinancialConstants;

/**
 * The tick's index-fund phase, run after the board is priced: the quarterly reconstitution, the quarterly
 * distribution, each fund struck against its constituents' float capitalisation, and the creation basket an
 * authorized participant sends into the names, which lands on them next tick like any other order.
 */
final class IndexFundPhase
{
    // --- Index Reconstitution ---
    /** Tickers named per side of a reconstitution event before the rest are counted; an event description holds 255 characters. */
    public const RECONSTITUTION_TICKERS_SHOWN = 8;

    public function __construct(
        private readonly IndexCommittee $indexCommittee,
        private readonly IndexFundAccountant $fundAccountant,
        private readonly EtfTracker $etfTracker,
        /** Splits a creation basket over the names it is made of. */
        private readonly AuthorizedParticipant $authorizedParticipant,
        /** Where a creation basket is posted, so it lands on the constituents next tick like any other order. */
        private readonly OrderFlowStoreInterface $orderFlow,
        private readonly MarketEventPublisher $marketEvent,
    ) {}

    /**
     * @param array<string, Etf> $indexFunds The fund behind each published index, keyed by ticker.
     * @param array<int, Stock>  $stocks
     * @param array{updates: array<mixed>, float_caps: array<string, float>, half_spreads: array<string, float>, fund_flow: array<string, float>, dividend_points: array<string, float>} $board The stock phase's result.
     * @return array{updates: list<array{ticker: string, price: float, nav: float, premium: float, creation_value: float, name: string, is_etf: bool}>, events: list<array<string, mixed>>, log: list<string>}
     */
    public function run(
        array $indexFunds,
        array $stocks,
        array $board,
        MacroStateDTO $macroState,
        int $tickCount,
        int $ticksPerYear,
        float $dt,
        bool $isHistoryTick,
    ): array {
        $events = [];
        $log = [];

        if (IndexCommittee::isReconstitutionTick($tickCount, $ticksPerYear)) {
            foreach (MarketIndex::cases() as $index) {
                $fund = $indexFunds[$index->value] ?? null;
                $reconstitution = $this->indexCommittee->reconstitute(
                    $index,
                    $stocks,
                    $tickCount,
                    // The index level itself, excluding the fund's fee drag and accrued income.
                    $fund?->getIndexLevel()
                );

                // The rebalance is traded whether or not membership changed: reweighting alone turns the book.
                if ($fund !== null) {
                    $this->fundAccountant->chargeRebalance($fund, $reconstitution['trading_cost'], $reconstitution['level']);
                }

                if ($reconstitution['added'] === [] && $reconstitution['deleted'] === []) {
                    continue;
                }

                $log[] = sprintf(
                    '<info>%s reconstitution: +%s / -%s</info>',
                    $index->value,
                    implode(',', $reconstitution['added']) ?: 'none',
                    implode(',', $reconstitution['deleted']) ?: 'none'
                );

                if ($fund !== null) {
                    $events[] = $this->marketEvent->publish(
                        $fund,
                        'INDEX',
                        self::describeReconstitution($reconstitution['added'], $reconstitution['deleted']),
                        0.0
                    );
                }
            }
        }

        $isDistributionTick = $tickCount > 0
            && $tickCount % max(1, intdiv($ticksPerYear, FinancialConstants::FUND_DISTRIBUTIONS_PER_YEAR)) === 0;

        // Prices as the tick has just published them, for splitting a creation basket into shares.
        $memberPrices = [];
        foreach ($board['updates'] as $publishedUpdate) {
            $memberPrices[$publishedUpdate['ticker']] = (float) $publishedUpdate['price'];
        }

        $updates = [];
        foreach (MarketIndex::cases() as $index) {
            if (!isset($indexFunds[$index->value])) {
                continue;
            }

            $fund = $indexFunds[$index->value];

            // Paid BEFORE the strike below, so the price the tick publishes is already ex the cash that left.
            // The drop is not a return: the holder has the money instead.
            if ($isDistributionTick) {
                $paid = $this->fundAccountant->distribute($fund, new \DateTime());

                if ($paid > 0.0) {
                    $events[] = $this->marketEvent->publish(
                        $fund,
                        'DIVIDEND',
                        sprintf(
                            '%s distributed $%s per share of income collected from its constituents.',
                            $fund->getName(),
                            number_format($paid, 2)
                        ),
                        0.0
                    );
                }
            }

            // What the players did to the FUND this tick, and what its basket costs to trade. The first pushes
            // the fund off its net asset value; the second decides how far it is allowed to go before an
            // authorized participant closes the gap.
            $fundFlowShares = (float) ($board['fund_flow'][$index->value] ?? 0.0);
            $fundUpdate = $this->etfTracker->updateIndex(
                $this->indexCommittee->memberCapitalisation($index, $board['float_caps']),
                $isHistoryTick,
                $index->value,
                $fund,
                $macroState->totalTime,
                $this->indexCommittee->memberDividendPoints($index, $board['dividend_points']),
                $dt,
                $fundFlowShares * (float) $fund->getPrice(),
                $this->indexCommittee->memberWeightedHalfSpread($index, $board['float_caps'], $board['half_spreads'])
            );

            $updates[] = $fundUpdate;

            // The creation basket is a real order in every constituent, in index weight, and it pays each
            // name's own impact when it lands next tick: money going INTO a fund reaches the companies it holds.
            $creationValue = (float) ($fundUpdate['creation_value'] ?? 0.0);

            if ($creationValue !== 0.0) {
                $basket = $this->authorizedParticipant->basketOrders(
                    $creationValue,
                    $this->indexCommittee->memberWeights($index, $board['float_caps']),
                    $memberPrices
                );

                foreach ($basket as $memberTicker => $shares) {
                    $this->orderFlow->record($memberTicker, $shares);
                }
            }
        }

        return ['updates' => $updates, 'events' => $events, 'log' => $log];
    }

    /**
     * The event text for a reconstitution, within the 255 characters an event description holds.
     *
     * A composite that has just lost a dozen names to a wave of bankruptcies would otherwise overflow the column,
     * so each list is cut and the remainder counted rather than dropped silently.
     *
     * @param list<string> $added
     * @param list<string> $deleted
     */
    public static function describeReconstitution(array $added, array $deleted): string
    {
        $list = static function (array $tickers): string {
            $shown = array_slice($tickers, 0, self::RECONSTITUTION_TICKERS_SHOWN);
            $rest = count($tickers) - count($shown);

            return implode(', ', $shown) . ($rest > 0 ? sprintf(' and %d more', $rest) : '');
        };

        $parts = [];
        if ($added !== []) {
            $parts[] = 'added ' . $list($added);
        }
        if ($deleted !== []) {
            $parts[] = 'dropped ' . $list($deleted);
        }

        return 'Quarterly reconstitution: ' . implode('; ', $parts) . '.';
    }
}
