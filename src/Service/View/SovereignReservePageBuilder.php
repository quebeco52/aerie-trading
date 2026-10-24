<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\StrategicHoldings;
use App\Data\District\DistrictCalendar;
use App\Data\Politics\StanceWords;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\Stock;
use App\Repository\MacroReportHistoryRepository;
use App\Repository\StockRepository;
use App\Service\Macro\Subsystem\SovereignFundSubsystem;
use App\Service\Market\Index\IndexCommittee;
use App\Service\Math\MacroConstants;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Department\SovereignReserveFund;
use App\Twig\Extension\UiExtension;

/**
 * Builds the Sovereign Reserve Fund's page: what the fund is worth, where it sits against its policy weights and bands,
 * what it holds on the board, and the rules it runs on, each written out with the figures in force.
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
    /**
     * Dividends a year: Stock::lastDividend is the quarterly payment, annualised as the screener and option desk do.
     */
    private const DIVIDENDS_PER_YEAR = 4.0;

    public function __construct(
        private readonly StockRepository $stocks,
        private readonly SovereignFundSubsystem $fund,
        private readonly ?MacroReportHistoryRepository $reports = null,
    ) {}

    /**
     * @return array{
     *     incepted: bool,
     *     head: array{name: string, age: int, sinceLabel: string, termEndsLabel: string, stance: string, stanceText: string, equityShare: float, opening: bool}|null,
     *     summary: array{value: float, toGdp: float, annualDraw: float, drawToGdp: float, stampDutyToGdp: float, stampDutyYearToDate: float, ownership: float, grossDebtToGdp: float, netDebtToGdp: float},
     *     returns: array{index: float, assumedReal: float, bondYield: float},
     *     programme: array{active: bool, buying: bool, share: float, monthsLeft: float, monthsSinceLast: float|null},
     *     sleeves: list<array{key: string, label: string, weight: float, policy: float, value: float}>,
     *     bands: list<array{key: string, label: string, weight: float, policy: float, band: float, breachingMove: float}>,
     *     bandHistory: array<string, array{domesticPolicy: float, domesticBand: float, equityPolicy: float, equityBand: float}>,
     *     holdings: list<array{rank: int, ticker: string, name: string, sector: string, stakeValue: float, stakeShares: float, sleeveWeight: float}>,
     *     strategic: list<array{ticker: string, name: string, stake: float, fundStake: float, shares: float, value: float, annualDividend: float}>,
     *     mandate: array<string, float>,
     *     rules: list<array{name: string, text: string}>
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
        $head = $politics?->head(Office::FundHead) ?? Office::VACANT;
        $stance = SovereignReserveFund::stanceName(SovereignReserveFund::equityShare($head['stance']));
        $bands = $incepted ? [
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
        ] : [];
        $mandate = [
            'spendingShare' => $macro->reserveDrawShare,
            'homeShare' => $macro->reserveHomeEquityShare,
            'drawCeiling' => MacroConstants::RESERVE_DRAW_CEILING,
            'foreignEquityShare' => $incepted && $target < 1.0 ? ($equityPolicy - $target) / (1.0 - $target) : SovereignFundSubsystem::FOREIGN_EQUITY_SHARE,
            'transitionMonths' => SovereignFundSubsystem::POLICY_TRANSITION_MONTHS,
            'foreignBondDuration' => SovereignFundSubsystem::FOREIGN_BOND_DURATION,
            'returnIndexBase' => SovereignFundSubsystem::RETURN_INDEX_BASE,
            'openingSize' => MacroConstants::OPENING_FUND_TO_GDP,
            'openingOwnership' => SovereignFundSubsystem::DOMESTIC_OWNERSHIP_OPENING,
            'ownershipCeiling' => SovereignFundSubsystem::MAX_OWNERSHIP_SHARE,
            'checkMonths' => 12.0 * SovereignFundSubsystem::REBALANCE_CHECK_PERIOD_YEARS,
            'executionMonths' => SovereignFundSubsystem::REBALANCE_EXECUTION_MONTHS,
            'stampDutyRate' => $macro->stampDutyRate,
            'debtFloor' => MacroConstants::SOVEREIGN_DEBT_FLOOR,
            'clearinghouseStake' => StrategicHoldings::CLEARINGHOUSE_STAKE,
        ];

        return [
            'incepted' => $incepted,
            'head' => $politics === null || $head['name'] === '' ? null : [
                'name' => $head['name'],
                'age' => (int) floor($politics->totalTime - $head['birth']),
                'sinceLabel' => $head['termStart'] < 0.0 ? 'Before Year 1' : DistrictCalendar::quarter($head['termStart']),
                'termEndsLabel' => DistrictCalendar::quarter(Office::FundHead->termEnd($politics->totalTime)),
                'stance' => $stance,
                'stanceText' => StanceWords::for(StanceWords::RESERVES, StanceWords::CLAUSE, $stance),
                'equityShare' => SovereignReserveFund::equityShare($head['stance']),
                'opening' => $head['termStart'] < 0.0,
            ],
            'summary' => [
                'value' => $value,
                'toGdp' => $macro->sovereignFundToGdp,
                'annualDraw' => $macro->sovereignFundAnnualDraw,
                'drawToGdp' => $macro->sovereignFundDrawToGdp,
                'stampDutyToGdp' => $macro->sovereignFundStampDutyToGdp,
                'stampDutyYearToDate' => $macro->sovereignFundStampDutyYearToDate,
                'ownership' => $macro->sovereignFundOwnershipShare,
                'grossDebtToGdp' => $macro->sovereignDebtToGdp,
                'netDebtToGdp' => $macro->sovereignNetDebtToGdp,
            ],
            // The index levels only: trailing returns need the recorded history, which the page reads from
            // macro_report.
            'returns' => [
                'index' => $macro->sovereignFundReturnIndex,
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
            'bands' => $bands,
            'bandHistory' => $incepted ? $this->bandHistory() : [],
            'holdings' => $this->holdings($board, $macro->sovereignFundOwnershipShare),
            'strategic' => $this->strategicHoldings($board, $macro->sovereignFundOwnershipShare),
            'mandate' => $mandate,
            'rules' => $bands === [] ? [] : self::rules($mandate, $bands),
        ];
    }

    /**
     * Every rule the fund runs on, as the page's mandate lists them, with the figures in force written in.
     *
     * Where each rule is taken from: the spending rule from Singapore's Net Investment Returns framework (2008), whose
     * contribution is budget revenue while its budget surpluses accrue to its reserves (the budget rule); the opening
     * size from GPIF's assets against Japan's GDP (FY2024) and its share of the Japanese market (2016); the reference
     * portfolio from GIC's Reference Portfolio (2013), at the duration of the Bloomberg Global Aggregate (2026); both
     * equity bands from GPIF's policy asset mix, 5th medium-term plan (FY2025); rebalancing from Norges Bank Investment
     * Management's rule (2018); the ownership ceiling from the Government Pension Fund Global's mandate; corporate
     * actions from index-holder practice; returns as GIPS time-weighted returns, as GPFG and GIC report them; the stamp
     * duty at the District's own rate (Hong Kong charges 0.1% a side, the UK 0.5% on purchases); and the strategic
     * holding from SEL Holdings' 23% of SGX and Norway's 67% of Equinor, whose dividends go to the GPFG.
     *
     * @param  array<string, float>                                          $mandate
     * @param  list<array{policy: float, band: float, breachingMove: float}> $bands   The board's band, then the equity band.
     * @return list<array{name: string, text: string}>
     */
    private static function rules(array $mandate, array $bands): array
    {
        $pct = UiExtension::percent(...);
        [$district, $equity] = $bands;

        return [
            [
                'name' => 'Who sets the mix',
                'text' => "The Council names the fund's head for a single term. The head sets how much of the fund is held in equities; the District's share of those equities stays as the fund opened, and the rest goes abroad. A new mix puts the foreign money on its new split at once and moves the board to its new weight over " . number_format($mandate['transitionMonths']) . ' months.',
            ],
            [
                'name' => 'Spending rule',
                'text' => 'Each budget year the fund pays the budget the share of its expected long-term real return on its value that the Diet passes, ' . $pct($mandate['spendingShare'], 0) . ' now. The Diet may spend up to ' . $pct($mandate['drawCeiling'], 0) . ' on its own; the fund holds the second key on anything more, and consents only while the Statistical Office has a recession declared. The return is the compound one, the average less half the variance, so volatility is never spent.',
            ],
            [
                'name' => 'Budget rule',
                'text' => "The draw is budget revenue, counted like a tax when the Diet plans the year's balance against its debt target, so a fund that grows faster than the economy widens what the budget can spend. The fund's own market swings never reach the budget. The District keeps at least " . $pct($mandate['debtFloor'], 0) . ' of GDP in gross debt outstanding for its benchmark curve, and a surplus that would take debt below that buys bonds for the fund instead.',
            ],
            [
                'name' => 'Opening size',
                'text' => 'Opened at ' . $pct($mandate['openingSize'], 0) . ' of GDP, holding ' . $pct($mandate['openingOwnership'], 1) . " of the board's float.",
            ],
            [
                'name' => 'Reference portfolio',
                'text' => 'Money off the board is held ' . $pct($mandate['foreignEquityShare'], 0) . ' in global equities and ' . $pct(1.0 - $mandate['foreignEquityShare'], 0) . ' in foreign government bonds. The bonds are held at a constant duration of ' . number_format($mandate['foreignBondDuration'], 1) . ' years, so they earn their yield and roll-down and gain when foreign rates fall.',
            ],
            [
                'name' => 'District equity band',
                'text' => "The Diet sets how much of the fund's equities are held in the District: " . $pct($mandate['homeShare'], 0) . ' under the law in force. Policy weight ' . $pct($district['policy']) . ', band ±' . UiExtension::points($district['band']) . ', so it trips on a relative move of about ' . $pct($district['breachingMove'], 0) . '.',
            ],
            [
                'name' => 'Equity band',
                'text' => 'Policy equity share ' . $pct($equity['policy']) . ', band ±' . UiExtension::points($equity['band']) . '. This is the band a global crash breaches.',
            ],
            [
                'name' => 'Rebalancing',
                'text' => 'Checked ' . ($mandate['checkMonths'] === 1.0 ? 'at every month end' : 'every ' . number_format($mandate['checkMonths']) . ' months') . '. A breach puts the foreign sleeves back on policy at once and trades the board back to its policy weight evenly over ' . number_format($mandate['executionMonths']) . ' months, sliced across companies by float.',
            ],
            [
                'name' => 'Ownership ceiling',
                'text' => 'Never more than ' . $pct($mandate['ownershipCeiling'], 0) . ' of the float. Holding the same share of every company, that keeps it under ' . $pct($mandate['ownershipCeiling'], 0) . " of any company's voting shares.",
            ],
            [
                'name' => 'Corporate actions',
                'text' => "Tenders its share into buybacks and takes up its share of new issues, so a company's own flow never changes the fund's ownership.",
            ],
            [
                'name' => 'Returns',
                'text' => "Reported as a time-weighted return, valued continuously, so money paid in and the budget's draw are not performance. The real return is after District inflation, and is set against the return the spending rule assumed.",
            ],
            [
                'name' => 'Stamp duty',
                'text' => 'Every share trade pays ' . $pct($mandate['stampDutyRate']) . ' on each side, and the duty is paid into the fund rather than the budget. Index funds are exempt.',
            ],
            [
                'name' => 'Strategic holding',
                'text' => 'The District holds ' . $pct($mandate['clearinghouseStake'], 0) . ' of its central counterparty outside the fund. The stake is off the float and never traded. The District tenders into buybacks and takes up new issues pro rata, so the percentage holds. Its dividends and buyback proceeds are paid into the fund, and any subscription is paid out of it.',
            ],
        ];
    }

    /**
     * The policy weights and bands as they stood at each recorded quarter, keyed by the quarter's total_time, so the
     * band charts hold each quarter to the policy then in force: a new head's mix moves the policy, and a band is a
     * function of its policy weight alone. A quarter before the fund opened has no policy and is left out.
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

        usort($rows, static fn(array $a, array $b): int => $b['floatCap'] <=> $a['floatCap']);

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
