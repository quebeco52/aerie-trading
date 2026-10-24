<?php

declare(strict_types=1);

namespace App\Service\Macro\Subsystem\Aggregate;

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\Math\TimeSeries;

/**
 * The District's trade: export and import volumes against the mainland's demand, the real exchange rate, allied
 * defence spending and tariffs, the net exports they add to the gap, what a tariff raises, and the import price level
 * the currency and duties settle at.
 */
final class OpenEconomy
{
    // --- Open Economy: Trade Volumes and Import Prices (IMF WEO October 2015, Ch. 3, Table 3.1: 60 economies,
    // 1980-2014) ---
    /**
     * Exports over GDP (32%): a $12T economy sells less of itself abroad than a small one, its finance and its arms
     * most of what it does sell; large open economies run 25-45% (Germany 46%, the UK 30%, Japan 18%).
     */
    public const DISTRICT_EXPORT_SHARE = 0.32;
    /** Imports over GDP: exports less the structural trade balance (27.5%). */
    public const DISTRICT_IMPORT_SHARE = self::DISTRICT_EXPORT_SHARE - MacroConstants::TRADE_BALANCE_BASELINE;
    /**
     * Foreign value added in the District's gross exports, Switzerland's 24.6% (OECD TiVA 2015: domestic content 75.4%,
     * via Council of the EU WK 5369/2021): imports that pass through into exports.
     */
    public const FOREIGN_VALUE_ADDED_SHARE_OF_EXPORTS = 0.246;
    /**
     * The District's own value added in its exports over GDP, the part of an export swing that is its output (Johnson &
     * Noguera 2012).
     */
    public const DISTRICT_VALUE_ADDED_EXPORT_SHARE = self::DISTRICT_EXPORT_SHARE * (1.0 - self::FOREIGN_VALUE_ADDED_SHARE_OF_EXPORTS);
    /**
     * Imports for use at home over GDP: imports less those passed through into exports, the part domestic demand draws
     * in.
     */
    public const DISTRICT_DOMESTIC_IMPORT_SHARE = self::DISTRICT_IMPORT_SHARE - (self::DISTRICT_EXPORT_SHARE * self::FOREIGN_VALUE_ADDED_SHARE_OF_EXPORTS);
    /**
     * The seeded Aerospace & Defense makers' first-year revenue (GRIP, PTAR), all of it sold to allied governments
     * (full-market opening).
     */
    public const DISTRICT_DEFENSE_EXPORTS_USD = 170.0e9;
    /** Arms exports over GDP, part of the exports above (1.4%). */
    public const DISTRICT_DEFENSE_EXPORT_SHARE = self::DISTRICT_DEFENSE_EXPORTS_USD / MacroConstants::DISTRICT_GDP_USD;
    /**
     * Mean lag (years) from an allied arms order to its delivery as exports: the makers' cost-plus backlog burns 15% a
     * quarter (DefenseContractorBusinessModel).
     */
    public const DEFENSE_DELIVERY_LAG_YEARS = 1.0 / (4.0 * 0.15);
    /**
     * Long-run export volume per unit of trading-partner income: the UK's 1.43 (Senhadji & Montenegro 1999, Table 2,
     * fully modified; sample mean 1.47).
     */
    public const EXPORT_DEMAND_ELASTICITY = 1.43;
    /**
     * Partial-adjustment coefficient on last year's exports in the same equation, the UK's 0.66: the year's short-run
     * elasticity is the remaining 0.34 of the long-run one (0.48).
     */
    public const EXPORT_DEMAND_PERSISTENCE = 0.66;
    /** Import volume per unit of domestic demand (1.4). */
    public const IMPORT_DEMAND_ELASTICITY = 1.4;
    /** Long-term pass-through of the real exchange rate to export prices in foreign currency (0.552, PPI-based). */
    public const EXPORT_PRICE_PASS_THROUGH = 0.552;
    /** Long-term price elasticity of export volumes (0.321). */
    public const EXPORT_PRICE_ELASTICITY = 0.321;
    /** Long-term pass-through of the real exchange rate to import prices in domestic currency (0.605). */
    public const IMPORT_PRICE_PASS_THROUGH = 0.605;
    /** Long-term price elasticity of import volumes (0.298). */
    public const IMPORT_PRICE_ELASTICITY = 0.298;
    /**
     * Time constant (years) of the net-export response to the real exchange rate: at the district's shares the table's
     * one-year effects give 87% of the long-term one.
     */
    public const TRADE_VOLUME_ADJUSTMENT_YEARS = 0.49;
    /**
     * Goods in the District's imports, the part a tariff is levied on: the UK's 75.6% (World Bank TM.VAL.MRCH.CD.WT
     * over NE.IMP.GNFS.CD, 2010-2019 mean); services cross no customs border.
     */
    public const GOODS_SHARE_OF_IMPORTS = 0.756;
    /**
     * Goods in the District's exports, the part partners can tariff: the UK's 56.5% (World Bank TX.VAL.MRCH.CD.WT over
     * NE.EXP.GNFS.CD, 2010-2019 mean).
     */
    public const GOODS_SHARE_OF_EXPORTS = 0.565;

    /**
     * Net exports away from the structural trade balance, as a share of GDP (IMF WEO October 2015, Ch. 3).
     *
     * Exports follow the mainland's demand by partial adjustment: under half the long-run response arrives within the
     * year (Senhadji & Montenegro 1999), so a mainland cycle reaches them damped and late. The currency against its own
     * trend moves both volumes through the prices it passes into, the price response building over about a year toward
     * its long-term size.
     *
     * @param MacroState $state Current macroeconomic state.
     * @param float      $dt    Time increment in years.
     * @return float Net export gap (fraction of GDP).
     */
    public static function updateNetExportGap(MacroState $state, float $dt): float
    {
        $state->mainlandDemandExportLag = TimeSeries::calculateDistributedLag(
            $state->mainlandDemandExportLag,
            $state->foreignOutputGapEma,
            $dt,
            self::exportDemandAdjustmentYears(),
        );
        $state->realExchangeRateTradeLag = TimeSeries::calculateDistributedLag(
            $state->realExchangeRateTradeLag,
            self::realExchangeRateGap($state->exchangeRateIndexEma, $state->exchangeRateTrend),
            $dt,
            self::TRADE_VOLUME_ADJUSTMENT_YEARS,
        );
        $state->alliedDefenseDeliveryLag = TimeSeries::calculateDistributedLag(
            $state->alliedDefenseDeliveryLag,
            self::alliedDefenseGap($state->alliedDefenseSpendingIndexEma),
            $dt,
            self::DEFENSE_DELIVERY_LAG_YEARS,
        );
        // A duty reaches volumes as a price does, over the same year.
        $state->tariffTradeLag = TimeSeries::calculateDistributedLag($state->tariffTradeLag, log(1.0 + $state->importTariffRate), $dt, self::TRADE_VOLUME_ADJUSTMENT_YEARS);

        return self::netExportGapAt($state->mainlandDemandExportLag, $state->realExchangeRateTradeLag, $state->alliedDefenseDeliveryLag, $state->tariffTradeLag);
    }

    /**
     * Time constant (years) of export volumes' partial adjustment to the mainland's demand (Nerlove 1958; the export
     * equation of Senhadji & Montenegro 1999): a yearly coefficient lambda on last year's exports is a first-order lag
     * of -1 / ln(lambda) years (2.4y at the UK's 0.66).
     */
    public static function exportDemandAdjustmentYears(): float
    {
        return -1.0 / log(self::EXPORT_DEMAND_PERSISTENCE);
    }

    /** Goods the District imports, as a share of GDP, at no tariff: the import share times its goods share. */
    public static function goodsImportsToGdp(): float
    {
        return self::DISTRICT_IMPORT_SHARE * self::GOODS_SHARE_OF_IMPORTS;
    }

    /**
     * What a tariff raises, as a share of GDP: the duty on the goods still imported once it has raised their price,
     * imports falling at their price elasticity (duties pass fully into import prices: Amiti, Redding & Weinstein
     * 2019).
     */
    public static function tariffRevenueToGdp(float $tariff): float
    {
        return $tariff * self::goodsImportsToGdp() * ((1.0 + $tariff) ** -self::IMPORT_PRICE_ELASTICITY);
    }

    /**
     * Allied defence spending against its trend, in logs: the orders the District's arms makers take.
     *
     * @param float $alliedDefenseSpendingIndexEma The allied defence index, smoothed over a quarter.
     * @return float Log allied defence gap; positive is a build-up.
     */
    public static function alliedDefenseGap(float $alliedDefenseSpendingIndexEma): float
    {
        return $alliedDefenseSpendingIndexEma > 0.0 ? log($alliedDefenseSpendingIndexEma / MacroConstants::ALLIED_DEFENSE_BASELINE) : 0.0;
    }

    /**
     * The currency against its own trend, in logs: the competitiveness trade volumes answer. The trend, not the index's
     * nominal baseline, is the reference, since the currency settles away from 100 by construction.
     *
     * @param float $exchangeRateIndexEma The currency index, smoothed over a quarter.
     * @param float $exchangeRateTrend    The currency's own long-run trend.
     * @return float Log real exchange rate gap; positive is an appreciation.
     */
    public static function realExchangeRateGap(float $exchangeRateIndexEma, float $exchangeRateTrend): float
    {
        return ($exchangeRateTrend > 0.0 && $exchangeRateIndexEma > 0.0) ? log($exchangeRateIndexEma / $exchangeRateTrend) : 0.0;
    }

    /**
     * Net exports at a mainland gap, a (lagged) real exchange rate gap and the allied defence spending delivered, as a
     * share of GDP.
     *
     * Civilian export volume moves 1.43 times the mainland's demand as it has reached them; arms exports move with the
     * allied procurement their makers sell into, at its elasticity to defence spending. A real appreciation raises
     * export prices abroad by the export pass-through and loses their elasticity's worth of volume, and lowers import
     * prices at home by the import pass-through and gains that elasticity's worth of imports, each weighted by its
     * share of GDP (the chapter's footnote 21, which gives 1.5% of GDP per 10% at the sample's 42% and 41% shares).
     *
     * Volumes are gross; output is not. An export swing moves the District's GDP by its own value added only, the
     * imports passed through into exports going out with it, and only imports for home use displace home output
     * (Johnson & Noguera 2012). Arms are taken as made at home.
     *
     * A tariff is a price with full pass-through (Amiti, Redding & Weinstein 2019): imported goods lose their price
     * elasticity's worth of volume on the whole duty. Partners answer on the District's goods exports at the 2018
     * retaliation ratio (Fajgelbaum et al. 2020), which lose theirs; allied governments do not tariff the arms they
     * buy.
     *
     * @param float $mainlandGap         Mainland output gap as export volumes have adjusted to it (fraction).
     * @param float $realExchangeRateGap Log real exchange rate against its trend.
     * @param float $alliedDefenseGap    Log allied defence spending against its trend, as deliveries have reached it.
     * @param float $tariffDuty          Log of one plus the District's average tariff, as it has reached volumes.
     * @return float Net export gap (fraction of GDP).
     */
    public static function netExportGapAt(float $mainlandGap, float $realExchangeRateGap, float $alliedDefenseGap, float $tariffDuty = 0.0): float
    {
        $domesticContent = 1.0 - self::FOREIGN_VALUE_ADDED_SHARE_OF_EXPORTS;
        $priceResponse = (self::EXPORT_PRICE_PASS_THROUGH * self::EXPORT_PRICE_ELASTICITY * self::DISTRICT_VALUE_ADDED_EXPORT_SHARE)
            + (self::IMPORT_PRICE_PASS_THROUGH * self::IMPORT_PRICE_ELASTICITY * self::DISTRICT_DOMESTIC_IMPORT_SHARE);
        $retaliatoryDuty = self::retaliatoryDuty($tariffDuty);
        $retaliatedExportShare = ((self::DISTRICT_EXPORT_SHARE * self::GOODS_SHARE_OF_EXPORTS) - self::DISTRICT_DEFENSE_EXPORT_SHARE) * $domesticContent;

        return ((self::DISTRICT_EXPORT_SHARE - self::DISTRICT_DEFENSE_EXPORT_SHARE) * $domesticContent * self::EXPORT_DEMAND_ELASTICITY * $mainlandGap)
            + (self::DISTRICT_DEFENSE_EXPORT_SHARE * MacroConstants::ALLIED_PROCUREMENT_ELASTICITY * $alliedDefenseGap)
            - ($priceResponse * $realExchangeRateGap)
            + (self::IMPORT_PRICE_ELASTICITY * self::DISTRICT_DOMESTIC_IMPORT_SHARE * self::GOODS_SHARE_OF_IMPORTS * $tariffDuty)
            - (self::EXPORT_PRICE_ELASTICITY * $retaliatedExportShare * $retaliatoryDuty);
    }

    /**
     * Civilian goods export volume against its trend, in logs: the volume netExportGapAt() weights into GDP. The
     * mainland's demand as exports have adjusted to it at the long-run income elasticity, the export price a real
     * appreciation passes through at its elasticity, and partners' retaliation against a District duty.
     *
     * @param float $mainlandGap         Mainland output gap as export volumes have adjusted to it (fraction).
     * @param float $realExchangeRateGap Log real exchange rate against its trend, as volumes have adjusted to it.
     * @param float $tariffDuty          Log of one plus the District's average tariff, as it has reached volumes.
     */
    public static function goodsExportVolumeGap(float $mainlandGap, float $realExchangeRateGap, float $tariffDuty = 0.0): float
    {
        return (self::EXPORT_DEMAND_ELASTICITY * $mainlandGap)
            - self::exportPriceVolumeLoss($realExchangeRateGap)
            - (self::EXPORT_PRICE_ELASTICITY * self::retaliatoryDuty($tariffDuty));
    }

    /**
     * Log volume an export, good or service, loses to a real appreciation: the share of it that reaches the price
     * abroad times the export price elasticity. Negative for a depreciation.
     *
     * @param float $realExchangeRateGap Log real exchange rate against its trend, as volumes have adjusted to it.
     */
    public static function exportPriceVolumeLoss(float $realExchangeRateGap): float
    {
        return self::EXPORT_PRICE_PASS_THROUGH * self::EXPORT_PRICE_ELASTICITY * $realExchangeRateGap;
    }

    /**
     * Civilian goods exports' share of the District's goods trade, exports plus imports; arms go to allied governments
     * outside it.
     */
    public static function goodsExportShareOfTrade(): float
    {
        $exports = (self::DISTRICT_EXPORT_SHARE * self::GOODS_SHARE_OF_EXPORTS) - self::DISTRICT_DEFENSE_EXPORT_SHARE;

        return $exports / ($exports + self::goodsImportsToGdp());
    }

    /**
     * Goods import volume against its trend, in logs: domestic demand at the import demand elasticity, the cheaper
     * imports a real appreciation passes through at their price elasticity, and a District duty, a price with full
     * pass-through, at the same elasticity.
     *
     * @param float $domesticDemandGap   Domestic demand gap (fraction).
     * @param float $realExchangeRateGap Log real exchange rate against its trend, as volumes have adjusted to it.
     * @param float $tariffDuty          Log of one plus the District's average tariff, as it has reached volumes.
     */
    public static function goodsImportVolumeGap(float $domesticDemandGap, float $realExchangeRateGap, float $tariffDuty = 0.0): float
    {
        return (self::IMPORT_DEMAND_ELASTICITY * $domesticDemandGap)
            + (self::IMPORT_PRICE_PASS_THROUGH * self::IMPORT_PRICE_ELASTICITY * $realExchangeRateGap)
            - (self::IMPORT_PRICE_ELASTICITY * $tariffDuty);
    }

    /**
     * Partners' duty on the District's goods in answer to its own, in logs: the 2018 retaliation ratio (Fajgelbaum et
     * al. 2020) on the ad valorem rate.
     */
    private static function retaliatoryDuty(float $tariffDuty): float
    {
        return log(1.0 + (MacroConstants::TARIFF_RETALIATION_RATIO * (exp($tariffDuty) - 1.0)));
    }

    /**
     * The log import price level, relative to domestic prices, that the currency and the tariff settle it at: the
     * long-term pass-through times the index against its baseline, plus the whole duty, which passes into
     * duty-inclusive prices in full (Amiti, Redding & Weinstein 2019; Fajgelbaum et al. 2020). Only its change is ever
     * read, so the baseline sets no level of its own.
     *
     * @param float $exchangeRateIndexEma The currency index, smoothed over a quarter.
     * @param float $importTariffRate     The average tariff on imports.
     * @return float Log relative import price level.
     */
    public static function importPriceLevelTarget(float $exchangeRateIndexEma, float $importTariffRate = 0.0): float
    {
        $index = $exchangeRateIndexEma > 0.0 ? $exchangeRateIndexEma : AssetMarketSubsystem::EXCHANGE_RATE_BASELINE;

        return (-self::IMPORT_PRICE_PASS_THROUGH * log($index / AssetMarketSubsystem::EXCHANGE_RATE_BASELINE)) + log(1.0 + $importTariffRate);
    }
}
