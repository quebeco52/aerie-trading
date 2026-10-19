<?php

declare(strict_types=1);

namespace App\Service\Politics\Policy;

use App\Data\Politics\AerieDiet;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Math\Valuation;

/**
 * What a position legislates: each lever between the policies real parties at the two ends of its question have
 * enacted, a party's debt plank, and a cabinet's position as its members' weighted by seats.
 */
final class Platforms
{
    // --- The Budget ---
    /** The state field each lever is kept in, in the same order. */
    public const LEVER_FIELDS = [
        'publicSpending' => 'publicSpendingShare',
        'corporateTax' => 'corporateTaxPolicyShift',
        'tariff' => 'importTariffRate',
        'laborGrowth' => 'laborForceGrowthRate',
        'foreignBuyerTax' => 'foreignBuyerTaxRate',
        'greenBeltStringency' => 'greenBeltStringency',
        'carbonPrice' => 'carbonPrice',
        'extractionStringency' => 'extractionStringency',
        'stampDutyRate' => 'stampDutyRate',
        'bankLevyRate' => 'bankLevyRate',
        'reserveDrawShare' => 'reserveDrawShare',
        'consolidationTaxShare' => 'consolidationTaxShare',
        'reserveHomeShare' => 'reserveHomeEquityShare',
        'utilityRateGrant' => 'utilityRateCaseGrantShare',
        'dividendTax' => 'dividendTaxRate',
        'debtTarget' => 'debtTarget',
        'debtPace' => 'debtPace',
    ];
    /** The question each lever's platform reads, in the same order. */
    public const LEVER_AXES = [
        'publicSpending' => AerieDiet::AXIS_STATE,
        'corporateTax' => AerieDiet::AXIS_STATE,
        'tariff' => AerieDiet::AXIS_OPENNESS,
        'laborGrowth' => AerieDiet::AXIS_OPENNESS,
        'foreignBuyerTax' => AerieDiet::AXIS_OPENNESS,
        'greenBeltStringency' => AerieDiet::AXIS_ENVIRONMENT,
        'carbonPrice' => AerieDiet::AXIS_ENVIRONMENT,
        'extractionStringency' => AerieDiet::AXIS_ENVIRONMENT,
        'stampDutyRate' => AerieDiet::AXIS_STATE,
        'bankLevyRate' => AerieDiet::AXIS_STATE,
        'reserveDrawShare' => AerieDiet::AXIS_STATE,
        'consolidationTaxShare' => AerieDiet::AXIS_STATE,
        'reserveHomeShare' => AerieDiet::AXIS_OPENNESS,
        'utilityRateGrant' => AerieDiet::AXIS_COUNCIL,
        'dividendTax' => AerieDiet::AXIS_STATE,
        'debtTarget' => AerieDiet::AXIS_STATE,
        'debtPace' => AerieDiet::AXIS_STATE,
    ];

    // --- Platforms: Public Spending ---
    /**
     * Log gap between the big-state and small-state ends' public spending: the 0.7% of GDP Singapore's Workers' Party
     * would add (manifesto 2025) over the founding purchases share, small enough that share and log coincide.
     */
    public const POLICY_MANIFESTO_PUBLIC_SPENDING_LOG_GAP = 0.007 / MacroConstants::PUBLIC_PURCHASES_TO_GDP;

    // --- Platforms: Corporate Tax ---
    /**
     * Gap between the corporate rates the big-state and small-state ends set: 2 points, Singapore's cut from 20% to 18%
     * (Budget 2008). Enacted rates follow manifestos (Osterloh & Debus 2012).
     */
    public const POLICY_MANIFESTO_CORPORATE_TAX_GAP = 0.02;

    // --- Platforms: Tariffs ---
    /**
     * Average effective tariff the protectionist end of the openness axis enacts over the free port: the US's 2025 rise
     * from 2.5% to 17.9% (Yale Budget Lab). Duties pass fully into import prices (Amiti, Redding & Weinstein 2019).
     */
    public const POLICY_PROTECTIONIST_TARIFF = 0.154;

    // --- Platforms: Immigration ---
    /** Net migration a closed regime admits, a year as a share of population: Japan's 0.11% (World Bank). */
    public const MIGRATION_CLOSED_REGIME = 0.0011;
    /** Net migration an open regime admits, a year: Canada's 0.73% and Australia's 0.86%, averaged (World Bank). */
    public const MIGRATION_OPEN_REGIME = (0.00728 + 0.00859) / 2.0;

    // --- Platforms: Foreign Buyers of Homes ---
    /**
     * Tax on a home bought from outside the District at the closed end of the openness axis, a share of the price:
     * Singapore's 60% Additional Buyer's Stamp Duty on foreigners. The open end taxes none, as at the founding.
     */
    public const POLICY_PROTECTIONIST_FOREIGN_BUYER_TAX = 0.60;

    // --- Platforms: Stamp Duty ---
    /**
     * Duty on each side of a share trade the big-state end enacts: Hong Kong's 0.13% (Inland Revenue Department). The
     * small-state end keeps the founding 0.05%; either way it is paid into the reserve fund, not the budget.
     */
    public const POLICY_BIG_STATE_STAMP_DUTY = 0.0013;

    // --- Platforms: Bank Levy ---
    /**
     * Bank levy the big-state end enacts, a year: Ireland's 0.122% of eligible deposits (Revenue), charged here in full
     * on short-term funding and at half on long-term funding and uninsured deposits. The small-state end levies none.
     */
    public const POLICY_BIG_STATE_BANK_LEVY = 0.00122;

    // --- Platforms: The Reserve Draw ---
    /**
     * Share of the reserve fund's expected long-term real return the big-state end spends: the 60% Singapore's Workers'
     * Party proposes (manifesto 2025). The small-state end keeps the founding half, the ceiling without fund consent.
     */
    public const POLICY_BIG_STATE_RESERVE_DRAW_SHARE = 0.60;

    // --- Platforms: Who Pays to Cut the Debt ---
    /**
     * Gap between the shares of a deficit cut the big-state and small-state manifestos raise by tax: Labour's £24bn
     * against the Conservatives' £14bn of £71bn (IFS 2010); enacted plans show no partisan gap.
     */
    public const POLICY_MANIFESTO_CONSOLIDATION_TAX_SHARE_GAP = (24.0 - 14.0) / 71.0;

    // --- Platforms: The Tax on Dividends ---
    /** Tax on dividends the big-state end enacts: the founding 15%, as no financial centre's left proposes more. */
    public const POLICY_BIG_STATE_DIVIDEND_TAX = Valuation::FOUNDING_DIVIDEND_TAX_RATE;
    /** Tax on dividends the small-state end enacts: none, as in Singapore's one-tier system (IRAS). */
    public const POLICY_SMALL_STATE_DIVIDEND_TAX = 0.0;

    // --- Platforms: The Reserve Fund's Home Share ---
    /**
     * Share of the reserve fund's equities the closed end of the openness axis keeps at home: GPIF's half, 25% domestic
     * in a 50% equity mix. The open end keeps none, as Norway's fund invests only abroad.
     */
    public const POLICY_PROTECTIONIST_RESERVE_HOME_SHARE = 0.25 / 0.50;

    // --- Platforms: The Environment ---
    /**
     * Carbon price on power and industry the environment-first end enacts, in dollars a tonne of CO2: the EU Emissions
     * Trading System's $70.37 (World Bank Carbon Pricing Dashboard). A growth-first cabinet prices none.
     */
    public const POLICY_GREEN_CARBON_PRICE = 70.37;

    /**
     * The levers a government at this position legislates, each between the policies real parties at the two ends of
     * its axis have enacted; past an end party it enacts that party's policy and no more. The environment levers are
     * none from the axis's middle toward growth and the strictest at the Tideline Accord's place.
     *
     * @param array<string, float> $position A position by axis (coalitionPosition(), or a party's).
     * @return array<string, float> Purchases (share of GDP), corporate rate shift from neutral, import tariff, labour
     *                              force growth (a year), foreign buyer tax, green belt and extraction stringency (0
     *                              founding, 1 strictest), carbon price ($ a tonne), stamp duty (a side), bank levy (a
     *                              year), reserve draw share, tax share of a deficit cut, reserve fund's home share,
     *                              utility rate case grant share, dividend tax.
     */
    public static function platform(array $position): array
    {
        // Each axis runs between the parties defined at its two ends, which stand for the real policies.
        $smallState = AerieDiet::FIXED_POSITIONS[AerieDiet::VANGUARD][AerieDiet::AXIS_STATE];
        $bigState = AerieDiet::FIXED_POSITIONS[AerieDiet::CIVIC][AerieDiet::AXIS_STATE];
        $closed = AerieDiet::FIXED_POSITIONS[AerieDiet::IRON_HARBOR][AerieDiet::AXIS_OPENNESS];
        $open = AerieDiet::FIXED_POSITIONS[AerieDiet::EXCHANGE][AerieDiet::AXIS_OPENNESS];
        $populist = AerieDiet::FIXED_POSITIONS[AerieDiet::COMMON_LOT][AerieDiet::AXIS_COUNCIL];
        $technocratic = AerieDiet::FIXED_POSITIONS[AerieDiet::CHARTISTS][AerieDiet::AXIS_COUNCIL];
        $green = AerieDiet::FIXED_POSITIONS[AerieDiet::TIDELINE][AerieDiet::AXIS_ENVIRONMENT];
        $stringency = max(0.0, min($green, $position[AerieDiet::AXIS_ENVIRONMENT] ?? 0.0)) / $green;
        $state = max($smallState, min($bigState, $position[AerieDiet::AXIS_STATE] ?? 0.0));
        $openness = max($closed, min($open, $position[AerieDiet::AXIS_OPENNESS] ?? 0.0));
        $council = max($populist, min($technocratic, $position[AerieDiet::AXIS_COUNCIL] ?? 0.0));
        $stateSpan = $bigState - $smallState;
        // Lean on the size-of-state axis, -1 at the small-state end to +1 at the big-state end, each side scaled to its
        // own end so a manifesto gap moves half each way however far from the average each end party stands.
        $stateLean = $state >= 0.0 ? $state / $bigState : -$state / $smallState;
        $opennessSpan = $open - $closed;

        return [
            // Purchases move in proportion; the neutral position keeps the founding share.
            'publicSpending' => MacroConstants::PUBLIC_PURCHASES_TO_GDP * exp(self::POLICY_MANIFESTO_PUBLIC_SPENDING_LOG_GAP / 2.0 * $stateLean),
            'corporateTax' => self::POLICY_MANIFESTO_CORPORATE_TAX_GAP / 2.0 * $stateLean,
            'tariff' => self::POLICY_PROTECTIONIST_TARIFF * max(0.0, $openness / $closed),
            // The resident population grows the labour force whatever the law; the average party admits the structural
            // newcomers on top, and each end its own regime.
            'laborGrowth' => MacroConstants::NATURAL_LABOR_FORCE_GROWTH + MacroConstants::STRUCTURAL_NET_MIGRATION_RATE
                + ((($openness >= 0.0 ? self::MIGRATION_OPEN_REGIME : self::MIGRATION_CLOSED_REGIME) - MacroConstants::STRUCTURAL_NET_MIGRATION_RATE) * ($openness >= 0.0 ? $openness / $open : $openness / $closed)),
            'foreignBuyerTax' => self::POLICY_PROTECTIONIST_FOREIGN_BUYER_TAX * max(0.0, $openness / $closed),
            'greenBeltStringency' => $stringency,
            'carbonPrice' => self::POLICY_GREEN_CARBON_PRICE * $stringency,
            'extractionStringency' => $stringency,
            'stampDutyRate' => FinancialConstants::STAMP_DUTY_RATE
                + ((self::POLICY_BIG_STATE_STAMP_DUTY - FinancialConstants::STAMP_DUTY_RATE) * ($state - $smallState) / $stateSpan),
            'bankLevyRate' => self::POLICY_BIG_STATE_BANK_LEVY * ($state - $smallState) / $stateSpan,
            'reserveDrawShare' => MacroConstants::RESERVE_DRAW_CEILING
                + ((self::POLICY_BIG_STATE_RESERVE_DRAW_SHARE - MacroConstants::RESERVE_DRAW_CEILING) * ($state - $smallState) / $stateSpan),
            // The neutral position keeps the average plan's mix.
            'consolidationTaxShare' => CreditFiscalSubsystem::FOUNDING_CONSOLIDATION_TAX_SHARE + (self::POLICY_MANIFESTO_CONSOLIDATION_TAX_SHARE_GAP / 2.0 * $stateLean),
            'reserveHomeShare' => self::POLICY_PROTECTIONIST_RESERVE_HOME_SHARE * ($open - $openness) / $opennessSpan,
            // The technocratic half keeps the appointed commission; toward the populist end it grants as elected.
            'utilityRateGrant' => FirmEconomics::APPOINTED_COMMISSION_GRANTED_SHARE
                * (1.0 - ((1.0 - FirmEconomics::ELECTED_COMMISSION_GRANT_RATIO) * max(0.0, $council / $populist))),
            // The neutral position keeps the founding rate; each end enacts its own side's plan.
            'dividendTax' => Valuation::FOUNDING_DIVIDEND_TAX_RATE
                + ((self::POLICY_BIG_STATE_DIVIDEND_TAX - Valuation::FOUNDING_DIVIDEND_TAX_RATE) * max(0.0, $state / $bigState))
                + ((self::POLICY_SMALL_STATE_DIVIDEND_TAX - Valuation::FOUNDING_DIVIDEND_TAX_RATE) * max(0.0, $state / $smallState)),
        ];
    }

    /**
     * A set of parties' debt plank, their planks weighted by seats as their positions are (Gamson's law): the gross
     * debt the budget steers to and the points of GDP a year it moves debt there (AerieDiet::DEBT_PLANKS).
     *
     * @param list<string>             $parties
     * @param array<string, int|float> $seats   Seats by party.
     * @return array{debtTarget: float, debtPace: float}
     */
    public static function debtPlanks(array $parties, array $seats): array
    {
        $total = 0.0;
        $target = 0.0;
        $pace = 0.0;
        foreach ($parties as $party) {
            $weight = max(0.0, (float) ($seats[$party] ?? 0.0));
            $total += $weight;
            $target += $weight * AerieDiet::DEBT_PLANKS[$party]['target'];
            $pace += $weight * AerieDiet::DEBT_PLANKS[$party]['pace'];
        }

        return $total > 0.0
            ? ['debtTarget' => $target / $total, 'debtPace' => $pace / $total]
            : ['debtTarget' => CreditFiscalSubsystem::FOUNDING_DEBT_TARGET, 'debtPace' => CreditFiscalSubsystem::FOUNDING_DEBT_PACE];
    }

    /**
     * A party's whole programme: the levers its position legislates (platform()) and its own debt plank.
     *
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @return array<string, float>
     */
    public static function partyProgramme(string $party, array $positions): array
    {
        return self::platform(AerieDiet::position($party, $positions)) + self::debtPlanks([$party], [$party => 1.0]);
    }

    /**
     * A cabinet's policy: its members' positions weighted by their seats (Gamson's law).
     *
     * @param array<string, float>                $coalition 1.0 for a member.
     * @param array<string, int|float>            $seats     Seats by party.
     * @param array<string, array<string, float>> $positions Positions by party and axis.
     * @return array{state: float, openness: float, council: float, environment: float}
     */
    public static function coalitionPosition(array $coalition, array $seats, array $positions): array
    {
        $weighted = array_fill_keys(AerieDiet::AXES, 0.0);
        $total = 0.0;
        foreach (AerieDiet::governingParties($coalition) as $party) {
            $weight = (float) ($seats[$party] ?? 0.0);
            $point = AerieDiet::position($party, $positions);
            foreach (AerieDiet::AXES as $axis) {
                $weighted[$axis] += $weight * $point[$axis];
            }
            $total += $weight;
        }
        if ($total > 0.0) {
            foreach (AerieDiet::AXES as $axis) {
                $weighted[$axis] /= $total;
            }
        }

        /** @var array{state: float, openness: float, council: float, environment: float} $weighted */
        return $weighted;
    }
}
