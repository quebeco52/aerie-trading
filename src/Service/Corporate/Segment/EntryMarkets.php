<?php

declare(strict_types=1);

namespace App\Service\Corporate\Segment;

use App\Data\Company\IndustryAdjacency;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Math\MacroConstants;

/**
 * The industries a company could enter, whether it builds its way in (CapitalBudgetEngine) or buys (AcquisitionEngine):
 * those next to the businesses it runs (IndustryAdjacency), each with what its incumbents earn on their plant, the
 * market they sell into and the room its fringe leaves a newcomer.
 */
final class EntryMarkets
{
    public function __construct(private readonly IndustryShareLedger $ledger) {}

    /**
     * Each industry next to a business the company still runs and has not put up for sale, at its closest link to any
     * of them; not one it is in, is building into or has left (a sold segment keeps its place at none). It must run on
     * plant whose output its market prices (SegmentEntry::isEnterable()), its incumbents must have filed what they
     * earn, and the ledger must size its market. Its growth above the economy's is its industry's secular excess as it
     * fades; with it, the share a typical incumbent there holds and the sales of the largest.
     *
     * @return array<string, array{closeness: float, margin: float, turnover: float, market: float, room: float, typical: float, leader: float, excess: float}>
     */
    public function survey(Stock $stock, MacroStateDTO $macroState, int $tick, int $ticksPerYear): array
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $held = SegmentBooks::industries($stock);
        $homes = array_filter($held, static fn(string $segment): bool => SegmentBooks::holdsPlant($stock, $segment) && !SegmentBooks::isForSale($momentum, $segment), ARRAY_FILTER_USE_KEY);
        $markets = [];
        foreach ($homes as $industry) {
            foreach (IndustryAdjacency::neighbours($industry) as $target => $closeness) {
                if (isset($markets[$target])) {
                    $markets[$target]['closeness'] = max($markets[$target]['closeness'], $closeness);
                    continue;
                }
                $strategy = Sectors::strategyFor($target);
                if (in_array($target, $held, true) || !SegmentEntry::isEnterable($target, $strategy)) {
                    continue;
                }
                $peers = $this->ledger->resolvePeerEconomics($target, $stock->getTicker(), $tick, $ticksPerYear);
                $market = $this->ledger->describeEntrant($target, $stock->getTicker(), $macroState, IndustryShareLedger::secularExcessGrowth($strategy, $stock), $tick, $ticksPerYear);
                if ($peers === null || $market === null || $market['market'] <= 0.0) {
                    continue;
                }
                $markets[$target] = [
                    'closeness' => $closeness,
                    'margin' => $peers['margin'],
                    'turnover' => $peers['turnover'],
                    'market' => $market['market'],
                    'room' => $market['fringe_share'],
                    'typical' => $market['typical_share'],
                    'leader' => $market['leader_revenue'],
                    'excess' => $strategy->getFadedSecularGrowthRate($stock, $macroState->totalTime) - MacroConstants::TREND_REAL_GROWTH,
                ];
            }
        }

        return $markets;
    }
}
