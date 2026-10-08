<?php

declare(strict_types=1);

namespace App\Service\Market\Ticker;

use App\DTO\MacroStateDTO;
use App\Entity\Bond;
use App\Entity\Stock;
use App\Service\Market\Bond\BondTracker;
use App\Service\Market\Bond\CorporateBondDesk;
use App\Service\Market\Bond\TreasuryAuctionService;

/**
 * The tick's bond phase: the ladder marked once a trading day at live issuer spreads, coupons and redemptions
 * paid, matured issues dropped from the working set, and the listed ladder topped up by corporate issuance and
 * the quarterly treasury refunding.
 */
final class BondDeskPhase
{
    public function __construct(
        private readonly BondTracker $bondTracker,
        private readonly CorporateBondDesk $corporateBondDesk,
        private readonly TreasuryAuctionService $treasuryAuction,
    ) {}

    /**
     * @param array<int, Bond>  $bonds  The active ladder; returned with matured issues dropped and new ones added.
     * @param array<int, Stock> $stocks
     * @return array{bonds: list<Bond>, result: array{updates: array<int, array<string, mixed>>, struck: array<int, array<string, mixed>>, history: array<int, array<string, mixed>>, matured: array<int, Bond>, curve: array<int, array{tenor: float, yield: float}>}}
     */
    public function run(array $bonds, array $stocks, MacroStateDTO $macroState, int $tickCount, int $ticksPerYear): array
    {
        $issuerSpreads = [];
        foreach ($stocks as $issuer) {
            $issuerId = $issuer->getId();
            if ($issuerId !== null) {
                $issuerSpreads[$issuerId] = (float) $issuer->getDynamicCreditSpread();
            }
        }

        // Marked once a trading day, and every mark is that day's bond_history row: see BOND_MARKS_PER_YEAR.
        $isBondMarkTick = TickCadence::isBondMarkTick($tickCount, $ticksPerYear);

        $result = $this->bondTracker->updateBonds($bonds, $macroState, $isBondMarkTick, $issuerSpreads, $isBondMarkTick);

        // A matured issue stops trading, so it leaves the working set now rather than at the next reload: it
        // would otherwise be re-marked and re-redeemed every tick until then.
        if ($result['matured'] !== []) {
            $maturedIds = array_map(static fn (Bond $b): ?int => $b->getId(), $result['matured']);
            $bonds = array_filter($bonds, static fn (Bond $b): bool => !in_array($b->getId(), $maturedIds, true));
        }
        $bonds = array_values($bonds);

        // Companies come to the public market to keep their listed ladder in step with the debt the balance
        // sheet already carries; see CorporateBondDesk for why a listed issue is a tranche, not new borrowing.
        if ($tickCount % CorporateBondDesk::issuanceIntervalTicks($ticksPerYear) === 0) {
            foreach ($this->corporateBondDesk->reconcile($stocks, $macroState->sovereignCurve(), $macroState->totalTime) as $newIssue) {
                $bonds[] = $newIssue;
            }
        }

        // Quarterly refunding: a fresh on-the-run at every tenor, so a benchmark maturity is always available.
        if ($tickCount % TreasuryAuctionService::auctionIntervalTicks($ticksPerYear) === 0) {
            foreach ($this->treasuryAuction->conductAuction($macroState->sovereignCurve(), $macroState->totalTime, $bonds) as $newIssue) {
                $bonds[] = $newIssue;
            }
        }

        return ['bonds' => $bonds, 'result' => $result];
    }
}
