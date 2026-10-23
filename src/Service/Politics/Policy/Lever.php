<?php

declare(strict_types=1);

namespace App\Service\Politics\Policy;

use App\Data\Politics\AerieDiet;

/**
 * A law the Diet sets at its budget rounds. The value keys a budget's levers (Platforms::platform(),
 * BudgetRound::standingLevers()); field() names the property that holds it in the politics and macro state, which carry
 * one property a lever. The cases run in the order a budget is written, so an unchanged budget compares equal.
 */
enum Lever: string
{
    /** Government purchases as a share of GDP: the level the purchases process reverts to. */
    case PublicSpending = 'publicSpending';
    /** The corporate rate's shift from the neutral rate. */
    case CorporateTax = 'corporateTax';
    /** Average tariff on imports. */
    case Tariff = 'tariff';
    /** Labour force growth, the immigration regime in it. */
    case LaborGrowth = 'laborGrowth';
    /** Tax on a home bought by a buyer from outside the District, a share of the price. */
    case ForeignBuyerTax = 'foreignBuyerTax';
    /** How far the green belt stands between the founding planning regime (0) and the strictest on record (1). */
    case GreenBelt = 'greenBeltStringency';
    /** The carbon price on power and industry, in dollars a tonne of CO2. */
    case CarbonPrice = 'carbonPrice';
    /** How far the rules on extraction stand between the founding ones (0) and the strictest on record (1). */
    case Extraction = 'extractionStringency';
    /** Stamp duty on each side of a share trade, paid into the reserve fund. */
    case StampDuty = 'stampDutyRate';
    /** Bank levy on short-term funding, a year (half that on long-term funding). */
    case BankLevy = 'bankLevyRate';
    /**
     * Share of the reserve fund's expected long-term real return the budget spends
     * (MacroConstants::RESERVE_DRAW_CEILING without the fund's consent).
     */
    case ReserveDraw = 'reserveDrawShare';
    /**
     * Share of the surplus the debt calls for (App\Service\Macro\Subsystem\CreditFiscalSubsystem::fiscalStance())
     * raised by tax rather than by cutting purchases, and of the room under the line spent on purchases rather than tax
     * cuts.
     */
    case ConsolidationTax = 'consolidationTaxShare';
    /** Share of the reserve fund's equities held on the District's own board. */
    case ReserveHome = 'reserveHomeShare';
    /** Share of a utility's rate request the utility commission grants. */
    case UtilityRateGrant = 'utilityRateGrant';
    /** Tax on the dividends the District's own shareholders are paid. */
    case DividendTax = 'dividendTax';
    /** Gross debt to GDP the Diet's budget steers toward. */
    case DebtTarget = 'debtTarget';
    /** Points of GDP a year the budget moves debt toward that target. */
    case DebtPace = 'debtPace';

    /** The property that holds the lever in PoliticsState, PoliticsStateDTO, MacroState and MacroStateDTO. */
    public function field(): string
    {
        return match ($this) {
            self::PublicSpending => 'publicSpendingShare',
            self::CorporateTax => 'corporateTaxPolicyShift',
            self::Tariff => 'importTariffRate',
            self::LaborGrowth => 'laborForceGrowthRate',
            self::ForeignBuyerTax => 'foreignBuyerTaxRate',
            self::ReserveHome => 'reserveHomeEquityShare',
            self::UtilityRateGrant => 'utilityRateCaseGrantShare',
            self::DividendTax => 'dividendTaxRate',
            self::GreenBelt, self::CarbonPrice, self::Extraction, self::StampDuty, self::BankLevy, self::ReserveDraw,
            self::ConsolidationTax, self::DebtTarget, self::DebtPace => $this->value,
        };
    }

    /** The question (AerieDiet::AXES) whose position a platform reads the lever from. */
    public function axis(): string
    {
        return match ($this) {
            self::Tariff, self::LaborGrowth, self::ForeignBuyerTax, self::ReserveHome => AerieDiet::AXIS_OPENNESS,
            self::GreenBelt, self::CarbonPrice, self::Extraction => AerieDiet::AXIS_ENVIRONMENT,
            self::UtilityRateGrant => AerieDiet::AXIS_COUNCIL,
            self::PublicSpending, self::CorporateTax, self::StampDuty, self::BankLevy, self::ReserveDraw,
            self::ConsolidationTax, self::DividendTax, self::DebtTarget, self::DebtPace => AerieDiet::AXIS_STATE,
        };
    }

    /**
     * Each lever's property, keyed by lever, in budget order.
     *
     * @return array<string, string>
     */
    public static function fields(): array
    {
        return array_column(array_map(static fn(self $lever): array => [$lever->value, $lever->field()], self::cases()), 1, 0);
    }

    /**
     * Each lever's question, keyed by lever, in budget order.
     *
     * @return array<string, string>
     */
    public static function axes(): array
    {
        return array_column(array_map(static fn(self $lever): array => [$lever->value, $lever->axis()], self::cases()), 1, 0);
    }
}
