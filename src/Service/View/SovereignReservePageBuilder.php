<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\StrategicHoldings;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\Stock;
use App\Repository\MacroReportHistoryRepository;
use App\Repository\StockRepository;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Market\Index\IndexCommittee;
use App\Service\Math\FinancialConstants;
use App\Service\Politics\SovereignReserveFund;

/**
 * Builds the Sovereign Reserve page: what the fund is worth, where it sits against its policy weights and bands, what
 * it holds on the board, and the rules it runs on.
 *
 * Every figure is read off what the fund publishes each tick (its size over GDP, its board weight, its equity share,
 * its ownership of the float) rather than recomputed from its sleeves, so the page and macro_report cannot disagree.
 * The policy weights and bands are the subsystem's own; the holdings follow from its one rule for the board: the same
 * share of every listed company's float, which is how StockTracker slices its trades.
 *
 * The District's strategic stakes (StrategicHoldings) are shown beside the fund, not in it: they are off the float and
 * outside its weights and bands, and only their cash is paid into it.
 */
class SovereignReservePageBuilder
{
    // --- Dividend Annualisation ---
    /** Dividends a year: Stock::lastDividend is the quarterly payment, annualised as the screener and option desk do. */
    private const DIVIDENDS_PER_YEAR = 4.0;

    public function __construct(
        private readonly StockRepository $stocks,
        private readonly SovereignFundSubsystem $fund,
        private readonly ?MacroReportHistoryRepository $reports = null,
    ) {}

    /**
     * @return array{
     *     incepted: bool,
     *     head: array{name: string, age: int, sinceLabel: string, termEndsLabel: string, stance: string, equityShare: float, opening: bool}|null,
     *     summary: array{value: float, toGdp: float, annualDraw: float, drawToGdp: float, stabilisationToGdp: float, stampDutyToGdp: float, stampDutyYearToDate: float, ownership: float, grossDebtToGdp: float, netDebtToGdp: float},
     *     returns: array{index: float, realIndex: float, assumedReal: float, bondYield: float},
     *     programme: array{active: bool, buying: bool, share: float, monthsLeft: float, monthsSinceLast: float|null},
     *     sleeves: list<array{key: string, label: string, weight: float, policy: float, value: float}>,
     *     bands: list<array{key: string, label: string, weight: float, policy: float, band: float, breachingMove: float}>,
     *     bandHistory: array<string, array{domesticPolicy: float, domesticBand: float, equityPolicy: float, equityBand: float}>,
     *     holdings: list<array{rank: int, ticker: string, name: string, sector: string, stakeValue: float, stakeShares: float, sleeveWeight: float}>,
     *     strategic: list<array{ticker: string, name: string, stake: float, fundStake: float, shares: float, value: float, annualDividend: float}>,
     *     mandate: array<string, float>
     * }
     */
    public function build(MacroStateDTO $macro, ?PoliticsStateDTO $politics = null): array
    {
        // The currency bridge is set at inception and never otherwise (SovereignFundSubsystem::isIncepted).
        $incepted = $macro->sovereignFundDollarsPerGdp > 0.0;
        $value = $macro->sovereignFundToGdp * $macro->sovereignFundDollarsPerGdp * $macro->nominalGdpIndex;

        $target = $macro->sovereignFundTargetWeight;
        $equityPolicy = !$incepted ? 0.0 : ($macro->sovereignFundPolicyEquityShare > 0.0 ? $macro->sovereignFundPolicyEquityShare : $this->fund->policyEquityShare($target));
        $weight = $macro->sovereignFundDomesticWeight;
        $equityShare = $macro->sovereignFundEquityShare;
        $board = $incepted ? $this->stocks->findAll() : [];

        return [
            'incepted' => $incepted,
            'head' => $politics === null || $politics->fundHeadName === '' ? null : [
                'name' => $politics->fundHeadName,
                'age' => (int) floor($politics->totalTime - $politics->fundHeadBirth),
                'sinceLabel' => $politics->fundHeadTermStart < 0.0 ? 'Before Year 1' : GovernmentPageBuilder::simDate($politics->fundHeadTermStart),
                'termEndsLabel' => GovernmentPageBuilder::simDate(SovereignReserveFund::headTermEnd($politics->totalTime)),
                'stance' => SovereignReserveFund::stanceName(SovereignReserveFund::equityShare($politics->fundHeadStance)),
                'equityShare' => SovereignReserveFund::equityShare($politics->fundHeadStance),
                'opening' => $politics->fundHeadTermStart < 0.0,
            ],
            'summary' => [
                'value' => $value,
                'toGdp' => $macro->sovereignFundToGdp,
                'annualDraw' => $macro->sovereignFundAnnualDraw,
                'drawToGdp' => $macro->sovereignFundDrawToGdp,
                'stabilisationToGdp' => $macro->sovereignFundStabilisationToGdp,
                'stampDutyToGdp' => $macro->sovereignFundStampDutyToGdp,
                'stampDutyYearToDate' => $macro->sovereignFundStampDutyYearToDate,
                'ownership' => $macro->sovereignFundOwnershipShare,
                'grossDebtToGdp' => $macro->sovereignDebtToGdp,
                'netDebtToGdp' => $macro->sovereignNetDebtToGdp,
            ],
            // The index levels only: trailing returns need the recorded history, which the page reads from macro_report.
            'returns' => [
                'index' => $macro->sovereignFundReturnIndex,
                'realIndex' => $macro->sovereignFundRealReturnIndex,
                'assumedReal' => $macro->sovereignFundExpectedRealReturn,
                'bondYield' => $macro->foreignBondYield,
            ],
            'programme' => [
                'active' => $macro->sovereignFundRebalanceMonthsLeft > 0.0,
                'buying' => $macro->sovereignFundRebalanceShare > 0.0,
                'share' => abs($macro->sovereignFundRebalanceShare),
                'monthsLeft' => $macro->sovereignFundRebalanceMonthsLeft,
                'monthsSinceLast' => $macro->lastSovereignRebalanceAt >= 0.0
                    ? 12.0 * max(0.0, $macro->totalTime - $macro->lastSovereignRebalanceAt)
                    : null,
            ],
            'sleeves' => $incepted ? [
                ['key' => 'district', 'label' => 'District equities', 'weight' => $weight, 'policy' => $target, 'value' => $weight * $value],
                ['key' => 'foreign-equities', 'label' => 'Foreign equities', 'weight' => $equityShare - $weight, 'policy' => $equityPolicy - $target, 'value' => ($equityShare - $weight) * $value],
                ['key' => 'foreign-bonds', 'label' => 'Foreign bonds', 'weight' => 1.0 - $equityShare, 'policy' => 1.0 - $equityPolicy, 'value' => (1.0 - $equityShare) * $value],
            ] : [],
            'bands' => $incepted ? [
                [
                    'key' => 'district',
                    'label' => 'District equity weight',
                    'weight' => $weight,
                    'policy' => $target,
                    'band' => $this->fund->rebalanceBand($target),
                    'breachingMove' => SovereignFundSubsystem::breachingMove(SovereignFundSubsystem::GPIF_DOMESTIC_EQUITY_TARGET, SovereignFundSubsystem::GPIF_DOMESTIC_EQUITY_DEVIATION_LIMIT),
                ],
                [
                    'key' => 'equity',
                    'label' => 'Whole equity share',
                    'weight' => $equityShare,
                    'policy' => $equityPolicy,
                    'band' => $this->fund->equityBand($equityPolicy),
                    'breachingMove' => SovereignFundSubsystem::breachingMove(SovereignFundSubsystem::GPIF_GLOBAL_EQUITY_TARGET, SovereignFundSubsystem::GPIF_GLOBAL_EQUITY_DEVIATION_LIMIT),
                ],
            ] : [],
            'bandHistory' => $incepted ? $this->bandHistory() : [],
            'holdings' => $this->holdings($board, $macro->sovereignFundOwnershipShare),
            'strategic' => $this->strategicHoldings($board, $macro->sovereignFundOwnershipShare),
            'mandate' => [
                'spendingShare' => $macro->reserveDrawShare,
                'drawCeiling' => MacroEngine::RESERVE_DRAW_CEILING,
                'foreignEquityShare' => $incepted && $target < 1.0 ? ($equityPolicy - $target) / (1.0 - $target) : SovereignFundSubsystem::FOREIGN_EQUITY_SHARE,
                'transitionMonths' => SovereignFundSubsystem::POLICY_TRANSITION_MONTHS,
                'foreignBondDuration' => SovereignFundSubsystem::FOREIGN_BOND_DURATION,
                'returnIndexBase' => SovereignFundSubsystem::RETURN_INDEX_BASE,
                'openingSize' => SovereignFundSubsystem::OPENING_FUND_TO_GDP,
                'openingOwnership' => SovereignFundSubsystem::DOMESTIC_OWNERSHIP_OPENING,
                'ownershipCeiling' => SovereignFundSubsystem::MAX_OWNERSHIP_SHARE,
                'checkMonths' => 12.0 * SovereignFundSubsystem::REBALANCE_CHECK_PERIOD_YEARS,
                'executionMonths' => SovereignFundSubsystem::REBALANCE_EXECUTION_MONTHS,
                'stampDutyRate' => $macro->stampDutyRate,
                'debtFloor' => MacroEngine::SOVEREIGN_DEBT_FLOOR,
                'budgetRoundMonths' => 12.0 * MacroEngine::BUDGET_ROUND_PERIOD_YEARS,
                'stabilisationGapResponse' => MacroEngine::FUND_STABILISATION_GAP_RESPONSE,
                'stabilisationReversal' => MacroEngine::FUND_STABILISATION_IMPULSE_REVERSAL,
                'stabilisationPersistence' => MacroEngine::FUND_STABILISATION_PERSISTENCE,
                'stabilisationTrendYears' => MacroEngine::FUND_STABILISATION_GAP_TREND_YEARS,
                'clearinghouseStake' => StrategicHoldings::CLEARINGHOUSE_STAKE,
            ],
        ];
    }

    /**
     * The policy weights and bands as they stood at each recorded quarter, keyed by the quarter's total_time, so the band
     * charts hold each quarter to the policy then in force: a new head's mix moves the policy, and a band is a function
     * of its policy weight alone. A quarter before the fund opened has no policy and is left out.
     *
     * @return array<string, array{domesticPolicy: float, domesticBand: float, equityPolicy: float, equityBand: float}>
     */
    private function bandHistory(): array
    {
        $history = [];
        foreach ($this->reports?->fundPolicy() ?? [] as $time => $policy) {
            $target = $policy['target'] ?? 0.0;
            if ($target <= 0.0) {
                continue;
            }
            $equityPolicy = ($policy['equityPolicy'] ?? 0.0) > 0.0 ? (float) $policy['equityPolicy'] : $this->fund->policyEquityShare($target);
            $history[$time] = [
                'domesticPolicy' => $target,
                'domesticBand' => $this->fund->rebalanceBand($target),
                'equityPolicy' => $equityPolicy,
                'equityBand' => $this->fund->equityBand($equityPolicy),
            ];
        }

        return $history;
    }

    /**
     * The fund's stake in every listed company, largest first: its ownership share of each float.
     *
     * @param  list<Stock> $board
     * @return list<array{rank: int, ticker: string, name: string, sector: string, stakeValue: float, stakeShares: float, sleeveWeight: float}>
     */
    private function holdings(array $board, float $ownership): array
    {
        $rows = [];
        $boardFloatCap = 0.0;
        foreach ($board as $stock) {
            $floatCap = IndexCommittee::floatAdjustedCap($stock);
            if ($floatCap <= 0.0) {
                continue;
            }

            $boardFloatCap += $floatCap;
            $floatShares = (float) $stock->getSharesOutstanding() * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()));
            $rows[] = [
                'ticker' => $stock->getTicker(),
                'name' => $stock->getName(),
                'sector' => $stock->getSector(),
                'floatCap' => $floatCap,
                'stakeValue' => $ownership * $floatCap,
                'stakeShares' => $ownership * $floatShares,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['floatCap'] <=> $a['floatCap']);

        $holdings = [];
        foreach ($rows as $index => $row) {
            $holdings[] = [
                'rank' => $index + 1,
                'ticker' => $row['ticker'],
                'name' => $row['name'],
                'sector' => $row['sector'],
                'stakeValue' => $row['stakeValue'],
                'stakeShares' => $row['stakeShares'],
                'sleeveWeight' => $row['floatCap'] / $boardFloatCap,
            ];
        }

        return $holdings;
    }

    /**
     * The District's strategic stakes, valued at market, with the dividends they pay the fund at the company's current
     * rate. The fund's own float stake in the same company is carried beside it, since the two together are what the
     * District owns. A company that has failed carries no stake.
     *
     * @param  list<Stock> $board
     * @return list<array{ticker: string, name: string, stake: float, fundStake: float, shares: float, value: float, annualDividend: float}>
     */
    private function strategicHoldings(array $board, float $ownership): array
    {
        $rows = [];
        foreach ($board as $stock) {
            $stake = StrategicHoldings::stake($stock->getTicker());
            if ($stake <= 0.0 || $stock->isBankrupt()) {
                continue;
            }

            $shares = $stake * (float) $stock->getSharesOutstanding();
            $rows[] = [
                'ticker' => $stock->getTicker(),
                'name' => $stock->getName(),
                'stake' => $stake,
                'fundStake' => $ownership * max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage())),
                'shares' => $shares,
                'value' => $shares * (float) $stock->getPrice(),
                'annualDividend' => $shares * (float) $stock->getLastDividend() * self::DIVIDENDS_PER_YEAR,
            ];
        }

        return $rows;
    }
}
