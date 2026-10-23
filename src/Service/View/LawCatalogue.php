<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroConstants;
use App\Service\Politics\Policy\Lever;

/**
 * The Diet's laws as the government pages print them: the order the pages list them in, each one's name, its short
 * name in a question's heading, its unit, and its value as a reader knows it rather than as the lever holds it.
 */
final class LawCatalogue
{
    /** The laws in the order every government page lists them, the budget table's. */
    public const ORDER = [
        Lever::PublicSpending,
        Lever::CorporateTax,
        Lever::DividendTax,
        Lever::Tariff,
        Lever::LaborGrowth,
        Lever::ForeignBuyerTax,
        Lever::GreenBelt,
        Lever::CarbonPrice,
        Lever::Extraction,
        Lever::StampDuty,
        Lever::BankLevy,
        Lever::ReserveDraw,
        Lever::ConsolidationTax,
        Lever::DebtTarget,
        Lever::DebtPace,
        Lever::ReserveHome,
        Lever::UtilityRateGrant,
    ];

    /** The law's name in a table or a chart's caption. */
    public static function name(Lever $lever): string
    {
        return match ($lever) {
            Lever::PublicSpending => 'Public spending, share of GDP',
            Lever::CorporateTax => 'Corporate tax rate',
            Lever::DividendTax => 'Tax on dividends',
            Lever::Tariff => 'Average tariff on imports',
            Lever::LaborGrowth => 'Labour force growth',
            Lever::ForeignBuyerTax => 'Tax on foreign home buyers',
            Lever::GreenBelt => 'Housing schemes refused',
            Lever::CarbonPrice => 'Carbon price',
            Lever::Extraction => 'Extraction compliance cost',
            Lever::StampDuty => 'Stamp duty on share trades',
            Lever::BankLevy => 'Bank levy',
            Lever::ReserveDraw => 'Share of reserve returns spent',
            Lever::ConsolidationTax => 'Debt repair raised by tax',
            Lever::ReserveHome => 'Reserve fund shares held at home',
            Lever::UtilityRateGrant => 'Utility rate requests granted',
            Lever::DebtTarget => 'Debt target',
            Lever::DebtPace => 'Debt cut a year',
        };
    }

    /** The law as a question's heading lists it on the Diet's compass. */
    public static function shortName(Lever $lever): string
    {
        return match ($lever) {
            Lever::PublicSpending => 'public spending',
            Lever::CorporateTax => 'corporate tax',
            Lever::DividendTax => 'dividend tax',
            Lever::Tariff => 'tariffs',
            Lever::LaborGrowth => 'immigration',
            Lever::ForeignBuyerTax => 'foreign buyers',
            Lever::GreenBelt => 'green belts',
            Lever::CarbonPrice => 'carbon price',
            Lever::Extraction => 'extraction rules',
            Lever::StampDuty => 'stamp duty',
            Lever::BankLevy => 'bank levy',
            Lever::ReserveDraw => 'reserve draw',
            Lever::ConsolidationTax => 'tax or cuts',
            Lever::ReserveHome => 'reserves at home',
            Lever::UtilityRateGrant => 'utility rates',
            Lever::DebtTarget => 'debt target',
            Lever::DebtPace => 'debt pace',
        };
    }

    /** How the page prints the law (government/_macros.html.twig lever_value): dollars a tonne, else a percentage. */
    public static function unit(Lever $lever): string
    {
        return $lever === Lever::CarbonPrice ? 'usd_t' : 'pct';
    }

    /**
     * The law's value as the page shows it: the corporate rate itself rather than its shift, the green belt as the
     * schemes it refuses, the extraction rules as what they add to cost.
     */
    public static function display(Lever $lever, float $value): float
    {
        return match ($lever) {
            Lever::CorporateTax => MacroConstants::BASE_CORPORATE_TAX_RATE + $value,
            Lever::GreenBelt => AssetMarketSubsystem::planningRefusalRate($value),
            Lever::Extraction => FirmEconomics::calculateProductivityLossCostFactor(FinancialConstants::ENVIRONMENTAL_REGULATION_TFP_LOSS * $value) - 1.0,
            default => $value,
        };
    }
}
