<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\District\DistrictCalendar;
use App\Data\District\Institutions;
use App\Data\Politics\AerieCouncil;
use App\Data\Politics\StanceWords;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Service\Corporate\MergerAndAcquisitionEngine;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Politics\Council\CouncilAppointments;
use App\Service\Politics\Department\Exchequer;
use App\Service\Politics\Department\FairTradingBoard;
use App\Service\Politics\Department\FinancialRegulator;
use App\Service\Politics\Department\Office;
use App\Service\Politics\Department\SecuritiesCommission;
use App\Service\Politics\Department\SovereignReserveFund;
use App\Service\Politics\Diet\ElectionForecast;
use App\Service\Politics\Policy\Platforms;

/**
 * The Council's departments' pages, and the headline each gives the government's front page: the Trade Office, the
 * Exchequer, the Fair Trading Board, the Securities Commission, the Security Bureau, the Coast Guard, the State Guard,
 * the Financial Regulator, and the head of the Sovereign Reserve Fund.
 */
final class DepartmentPageBuilder
{
    /**
     * The Sovereign Reserve Fund's head for the government hub: who they are, their stance on the reserves and the
     * fund's equity mix in force. Null before the fund has a head.
     *
     * @return array<string, mixed>|null
     */
    public function fundHead(PoliticsStateDTO $politics, MacroStateDTO $macro): ?array
    {
        $head = $politics->head(Office::FundHead);
        if ($head['name'] === '') {
            return null;
        }

        $stance = SovereignReserveFund::stanceName(SovereignReserveFund::equityShare($head['stance']));

        return [
            'name' => $head['name'],
            'opening' => $head['termStart'] < 0.0,
            'stance' => $stance,
            'stanceClause' => StanceWords::for(StanceWords::RESERVES, StanceWords::CLAUSE, $stance),
            'inForce' => $macro->sovereignFundPolicyEquityShare > 0.0 ? $macro->sovereignFundPolicyEquityShare : null,
        ];
    }

    /**
     * The Trade & Migration Office's page: the tariff, the migration regime and the tax on foreign home buyers it
     * administers, each as in force, as the sitting government's next budget would set it and as the market expects
     * after the vote, with what each does to the District.
     *
     * @return array<string, mixed>
     */
    public function tradeOffice(PoliticsStateDTO $politics, MacroStateDTO $macro): array
    {
        $law = static fn(string $lever, float $inForce): array => [
            'inForce' => $inForce,
            'next' => $politics->sittingLevers[$lever] ?? null,
            'expected' => $politics->forecastLevers[$lever] ?? null,
        ];
        $tariff = $politics->importTariffRate;

        return [
            'tariff' => $law('tariff', $tariff) + [
                'retaliation' => MacroConstants::TARIFF_RETALIATION_RATIO * $tariff,
                'revenueToGdp' => MacroAggregateSubsystem::tariffRevenueToGdp($tariff),
                'outputCost' => MacroConstants::TARIFF_OUTPUT_LOSS * $tariff,
                'goodsImportsToGdp' => MacroAggregateSubsystem::goodsImportsToGdp(),
            ],
            'migration' => $law('laborGrowth', $politics->laborForceGrowthRate) + [
                'structural' => MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE,
                'natural' => MacroConstants::NATURAL_LABOR_FORCE_GROWTH,
                'structuralMigration' => MacroConstants::STRUCTURAL_NET_MIGRATION_RATE,
                'closed' => Platforms::MIGRATION_CLOSED_REGIME,
                'open' => Platforms::MIGRATION_OPEN_REGIME,
                'populationShift' => $macro->immigrationPopulationShift,
                'cyclical' => $macro->cyclicalMigrationRate,
                // Newcomers a year per point (a hundredth) of unemployment under its natural rate.
                'jobsResponse' => LaborMarketSubsystem::MIGRATION_JOBS_RESPONSE * max(0.0, $politics->laborForceGrowthRate - MacroConstants::NATURAL_LABOR_FORCE_GROWTH) / 100.0,
            ],
            'foreignBuyers' => $law('foreignBuyerTax', $politics->foreignBuyerTaxRate) + [
                'priceEffect' => 1.0 - AssetMarketSubsystem::foreignBuyerPriceFactor($politics->foreignBuyerTaxRate),
                'revenueToGdp' => AssetMarketSubsystem::foreignBuyerTaxRevenueToGdp($politics->foreignBuyerTaxRate),
                'strictest' => Platforms::POLICY_PROTECTIONIST_FOREIGN_BUYER_TAX,
            ],
            'expectedFrom' => $politics->forecastLevers === [] ? null : DistrictCalendar::quarter(ElectionForecast::takesEffect($politics)),
        ];
    }

    public function exchequer(PoliticsStateDTO $politics, MacroStateDTO $macro): ?array
    {
        $head = $politics->head(Office::ExchequerHead);
        if ($head['name'] === '') {
            return null;
        }

        $time = $politics->totalTime;
        $target = Exchequer::averageMaturity($head['stance']);
        $maturity = $macro->sovereignDebtAverageMaturity;
        $billShare = CreditFiscalSubsystem::billShare($maturity);
        $rate = ($billShare * $macro->policyRate) + ((1.0 - $billShare) * $macro->sovereignCouponRate);

        return [
            'head' => [
                'name' => $head['name'],
                'age' => (int) floor($time - $head['birth']),
                'sinceLabel' => $head['termStart'] < 0.0 ? 'Before Year 1' : DistrictCalendar::quarter($head['termStart']),
                'termEndsLabel' => DistrictCalendar::quarter(Office::ExchequerHead->termEnd($time)),
                'opening' => $head['termStart'] < 0.0,
                'stance' => Exchequer::stanceName($target),
                ...self::stanceWords(StanceWords::DEBT, Exchequer::stanceName($target)),
                'maturity' => $target,
                'passedOver' => array_map(static fn(array $candidate): array => [
                    'name' => $candidate['name'],
                    'age' => (int) floor($head['termStart'] - $candidate['birth']),
                    'maturity' => Exchequer::averageMaturity($candidate['debt']),
                ], $head['passedOver']),
            ],
            'debt' => $macro->sovereignDebtToGdp,
            'netDebt' => $macro->sovereignNetDebtToGdp,
            // What the bond market's default premium answers to: the debt less the fund whole.
            'netLiabilities' => $macro->sovereignNetFinancialLiabilitiesToGdp,
            'maturity' => $maturity,
            // A book still more than a month from the head's maturity is moving toward it.
            'movingTo' => abs($macro->sovereignDebtMaturityTarget - $maturity) > 1.0 / 12.0 ? $macro->sovereignDebtMaturityTarget : null,
            'repricingShare' => $billShare,
            'rate' => $rate,
            'interestToGdp' => CreditFiscalSubsystem::publicAccounts($macro, 0.0)->interest,
            // What the Monetary Authority pays over a year, and the losses it is still making up first.
            'remittanceToGdp' => CreditFiscalSubsystem::publicAccounts($macro, 0.0)->centralBankRemittance,
            'authorityLossesToGdp' => $macro->centralBankDeferredAssetToGdp,
            'tenYear' => $macro->yield10y,
            'maturityBp' => (int) round(10000.0 * CreditFiscalSubsystem::maturitySupplyTermPremium($macro->sovereignDebtToGdpEma, $maturity)),
            'riskSpreadBp' => (int) round(10000.0 * $macro->sovereignRiskSpread),
            'councilMaturity' => $politics->councilDebtStances === [] ? null : Exchequer::averageMaturity(CouncilAppointments::median($politics->councilDebtStances)),
            'rules' => [
                'termYears' => Office::ExchequerHead->termYears(),
                'shortlist' => CouncilAppointments::SHORTLIST,
                'shortest' => Exchequer::SHORTEST_AVERAGE_MATURITY,
                'longest' => Exchequer::LONGEST_AVERAGE_MATURITY,
                'founding' => CreditFiscalSubsystem::FOUNDING_DEBT_AVERAGE_MATURITY,
            ],
        ];
    }

    /**
     * The Fair Trading Board: its head, their stance on competition and whom the Council passed over for them, the
     * merger screens in force, and the range a head may set them across. Null before the Board has a head.
     *
     * @return array<string, mixed>|null
     */
    public function fairTrading(PoliticsStateDTO $politics): ?array
    {
        $head = $politics->head(Office::FairTradingHead);
        if ($head['name'] === '') {
            return null;
        }

        $time = $politics->totalTime;
        $screens = static fn(float $stance): array => MergerAndAcquisitionEngine::reviewScreens(FairTradingBoard::leniency($stance));

        return [
            'head' => [
                'name' => $head['name'],
                'age' => (int) floor($time - $head['birth']),
                'sinceLabel' => $head['termStart'] < 0.0 ? 'Before Year 1' : DistrictCalendar::quarter($head['termStart']),
                'termEndsLabel' => DistrictCalendar::quarter(Office::FairTradingHead->termEnd($time)),
                'opening' => $head['termStart'] < 0.0,
                'stance' => FairTradingBoard::stanceName($head['stance']),
                ...self::stanceWords(StanceWords::COMPETITION, FairTradingBoard::stanceName($head['stance'])),
                'passedOver' => array_map(static fn(array $candidate): array => [
                    'name' => $candidate['name'],
                    'age' => (int) floor($head['termStart'] - $candidate['birth']),
                    'line' => $screens($candidate['competition'])['concentrated'],
                ], $head['passedOver']),
            ],
            'screens' => $screens($head['stance']),
            'councilLine' => $politics->councilCompetitionStances === [] ? null : $screens(CouncilAppointments::median($politics->councilCompetitionStances))['concentrated'],
            'rules' => [
                'termYears' => Office::FairTradingHead->termYears(),
                'shortlist' => CouncilAppointments::SHORTLIST,
                'lightest' => $screens(-1.0),
                'strictest' => $screens(1.0),
            ],
        ];
    }

    /**
     * The Securities Commission: its head, their stance on the markets and whom the Council passed over for them; the
     * margins and the enforcement on earnings in force and, through a new head's notice, the ones coming; and the range
     * a head may set them across. Null before the Commission has a head.
     *
     * @return array<string, mixed>|null
     */
    public function securities(PoliticsStateDTO $politics, MacroStateDTO $macro): ?array
    {
        $head = $politics->head(Office::SecuritiesHead);
        if ($head['name'] === '') {
            return null;
        }

        $time = $politics->totalTime;
        $stance = $head['stance'];
        $opening = $head['termStart'] < 0.0;
        $effective = $head['termStart'] + SecuritiesCommission::RULE_NOTICE_YEARS;

        return [
            'head' => [
                'name' => $head['name'],
                'age' => (int) floor($time - $head['birth']),
                'sinceLabel' => $opening ? 'Before Year 1' : DistrictCalendar::quarter($head['termStart']),
                'termEndsLabel' => DistrictCalendar::quarter(Office::SecuritiesHead->termEnd($time)),
                'opening' => $opening,
                'stance' => SecuritiesCommission::stanceName($stance),
                ...self::stanceWords(StanceWords::MARKETS, SecuritiesCommission::stanceName($stance)),
                'passedOver' => array_map(static fn(array $candidate): array => [
                    'name' => $candidate['name'],
                    'age' => (int) floor($head['termStart'] - $candidate['birth']),
                    'margin' => SecuritiesCommission::initialMargin($candidate['markets']),
                ], $head['passedOver']),
            ],
            'inForce' => SecuritiesCommission::margins($macro->initialMarginRequirement) + [
                'enforcement' => $macro->earningsManagementScale,
            ],
            // A new head's rules, announced at their seating, while their notice runs.
            'coming' => $opening || $time >= $effective ? null : [
                'fromLabel' => DistrictCalendar::quarter($effective),
                'initial' => SecuritiesCommission::initialMargin($stance),
                'enforcement' => SecuritiesCommission::earningsManagementScale($stance),
            ],
            'councilMargin' => $politics->councilMarketsStances === [] ? null : SecuritiesCommission::initialMargin(CouncilAppointments::median($politics->councilMarketsStances)),
            'rules' => [
                'termYears' => Office::SecuritiesHead->termYears(),
                'shortlist' => CouncilAppointments::SHORTLIST,
                'noticeMonths' => (int) round(SecuritiesCommission::RULE_NOTICE_YEARS * 12.0),
                'lightestMargin' => SecuritiesCommission::LIGHTEST_INITIAL_MARGIN,
                'strictestMargin' => SecuritiesCommission::STRICTEST_INITIAL_MARGIN,
                'lightestEnforcement' => SecuritiesCommission::LIGHTEST_EARNINGS_MANAGEMENT_SCALE,
                'strictestEnforcement' => SecuritiesCommission::STRICTEST_EARNINGS_MANAGEMENT_SCALE,
            ],
        ];
    }

    /**
     * The Security Bureau: its mandate and its director, and when the director's term ends. The Bureau sets no rule, so
     * there are no figures. Null before it has a director.
     *
     * @return array<string, mixed>|null
     */
    public function securityBureau(PoliticsStateDTO $politics): ?array
    {
        if ($politics->head(Office::SecurityDirector)['name'] === '') {
            return null;
        }

        return [
            'mandate' => self::mandate(Institutions::SECURITY_BUREAU),
            'director' => self::standingPost($politics, Office::SecurityDirector),
            'rules' => ['termYears' => Office::SecurityDirector->termYears(), 'shortlist' => CouncilAppointments::SHORTLIST],
        ];
    }

    /**
     * The Coast Guard: its mandate, its commandant and when their term ends, and the term the Council names them for.
     * It sets no rule, so there are no figures. The commandant is null before the first is seated.
     *
     * @return array{name: string, mandate: string, headTitle: string, head: array<string, mixed>|null, rules: array{termYears: float, shortlist: int}}
     */
    public function coastGuard(PoliticsStateDTO $politics): array
    {
        return [
            'name' => Institutions::COAST_GUARD,
            'mandate' => self::mandate(Institutions::COAST_GUARD),
            'headTitle' => 'commandant',
            'head' => $politics->head(Office::CoastGuardCommandant)['name'] === '' ? null : self::standingPost($politics, Office::CoastGuardCommandant),
            'rules' => ['termYears' => Office::CoastGuardCommandant->termYears(), 'shortlist' => CouncilAppointments::SHORTLIST],
        ];
    }

    /**
     * The State Guard: its mandate, its commander and when their term ends, and the term the Council names them for.
     * It sets no rule, so there are no figures. The commander is null before the first is seated.
     *
     * @return array{name: string, mandate: string, headTitle: string, head: array<string, mixed>|null, rules: array{termYears: float, shortlist: int}}
     */
    public function stateGuard(PoliticsStateDTO $politics): array
    {
        return [
            'name' => Institutions::STATE_GUARD,
            'mandate' => self::mandate(Institutions::STATE_GUARD),
            'headTitle' => 'commander',
            'head' => $politics->head(Office::StateGuardCommander)['name'] === '' ? null : self::standingPost($politics, Office::StateGuardCommander),
            'rules' => ['termYears' => Office::StateGuardCommander->termYears(), 'shortlist' => CouncilAppointments::SHORTLIST],
        ];
    }

    /**
     * A head's stance in words: the sentence their department's page opens with, and the clause after their name on
     * the government's front page.
     *
     * @return array{stanceText: string, stanceClause: string}
     */
    private static function stanceWords(string $question, string $stance): array
    {
        return [
            'stanceText' => StanceWords::for($question, StanceWords::SENTENCE, $stance),
            'stanceClause' => StanceWords::for($question, StanceWords::CLAUSE, $stance),
        ];
    }

    /** A department's mandate as the Council lists it. */
    private static function mandate(string $department): string
    {
        return array_column(AerieCouncil::DEPARTMENTS, 'mandate', 'name')[$department];
    }

    /**
     * A head who sets no rule, for their department's page: their name and age, when they were seated and leave, and
     * whom the Council passed over for them, each at their age then.
     *
     * @return array{name: string, age: int, sinceLabel: string, termEndsLabel: string, opening: bool, passedOver: list<array{name: string, age: int}>}
     */
    private static function standingPost(PoliticsStateDTO $politics, Office $office): array
    {
        $time = $politics->totalTime;
        $head = $politics->head($office);

        return [
            'name' => $head['name'],
            'age' => (int) floor($time - $head['birth']),
            'sinceLabel' => $head['termStart'] < 0.0 ? 'Before Year 1' : DistrictCalendar::quarter($head['termStart']),
            'termEndsLabel' => DistrictCalendar::quarter($office->termEnd($time)),
            'opening' => $head['termStart'] < 0.0,
            'passedOver' => array_map(static fn(array $candidate): array => [
                'name' => $candidate['name'],
                'age' => (int) floor($head['termStart'] - $candidate['birth']),
            ], $head['passedOver']),
        ];
    }

    /**
     * The Financial Regulator: its head, their stance on the banks and whom the Council passed over for them; the core
     * capital requirement in force and, while a rise phases in, the one it is rising to; the countercyclical buffer on
     * top; and the range of regimes a head may run. Null before the Regulator has a head.
     *
     * @return array<string, mixed>|null
     */
    public function regulator(PoliticsStateDTO $politics, MacroStateDTO $macro): ?array
    {
        $head = $politics->head(Office::Regulator);
        if ($head['name'] === '') {
            return null;
        }

        $time = $politics->totalTime;
        $target = FinancialRegulator::requirement($head['stance']);

        return [
            'head' => [
                'name' => $head['name'],
                'age' => (int) floor($time - $head['birth']),
                'sinceLabel' => $head['termStart'] < 0.0 ? 'Before Year 1' : DistrictCalendar::quarter($head['termStart']),
                'termEndsLabel' => DistrictCalendar::quarter(Office::Regulator->termEnd($time)),
                'stance' => FinancialRegulator::stanceName($target),
                ...self::stanceWords(StanceWords::BANKS, FinancialRegulator::stanceName($target)),
                'requirement' => $target,
                'passedOver' => array_map(static fn(array $candidate): array => [
                    'name' => $candidate['name'],
                    'age' => (int) floor($head['termStart'] - $candidate['birth']),
                    'requirement' => FinancialRegulator::requirement($candidate['regulation']),
                ], $head['passedOver']),
            ],
            'inForce' => $politics->bankCapitalRequirement,
            'buffer' => $macro->countercyclicalBufferRate,
            'phasing' => $target > $politics->bankCapitalRequirement + 1e-9 ? [
                'target' => $target,
                'completeLabel' => DistrictCalendar::quarter($politics->requirementPhaseStart + FinancialRegulator::PHASE_IN_YEARS),
            ] : null,
            'rules' => [
                'termYears' => Office::Regulator->termYears(),
                'shortlist' => CouncilAppointments::SHORTLIST,
                'lightest' => FinancialRegulator::lightest(),
                'strictest' => FinancialRegulator::strictest(),
                'phaseInMonths' => (int) round(FinancialRegulator::PHASE_IN_YEARS * 12),
            ],
        ];
    }
}
