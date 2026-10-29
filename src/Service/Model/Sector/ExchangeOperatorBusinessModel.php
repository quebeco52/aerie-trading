<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Math\TimeSeries;
use App\Service\Model\StreamContext;

/**
 * Earnings strategy for an exchange group: the District's share market and its commodity futures market under one
 * owner.
 *
 * Financial Physics:
 * - Equity trading fees are charged on the value the board trades: its measured turnover times its value, each
 *   read against its own multi-year trend. A sell-off raises turnover and cuts the value it is charged on.
 * - Commodity futures volume follows commodity price swings: the basket's realised volatility against its own
 *   trend, through the same volume-volatility elasticity the share market's liquidity uses (Karpoff 1987).
 * - Listing fees are mostly annual and steady; the new-listing part follows deal activity.
 * - Market data, index licences and colocation are sticky subscriptions.
 * - Clearing is not here: Aerie Central Clearing clears what the exchange matches.
 */
class ExchangeOperatorBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of volumes and costs to the macro cycle (1.0 = one for one with the output gap). Trading follows the
     * market rather than the gap, so the cost base sees the cycle only partly.
     */
    public const OPERATING_CYCLICALITY = 0.50;
    /** Own-price elasticity of demand: a licensed venue with no rival loses little volume to a fee rise. */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.15;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers: the District licenses one exchange. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.0;

    // --- Input Cost Basket ---
    /** An exchange buys what a data vendor buys: engineers, data centres and software. */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::FINANCIAL_DATA;

    // --- Pricing Power ---
    /** The only venue for District shares and futures reprices its fee schedule with little pushback. */
    public const PRICING_POWER_INDEX = 0.80;

    // --- Balance Sheet Realism ---
    /** Stock-based compensation as a share of revenue, as the data vendors' (FinancialDataBusinessModel). */
    public const STOCK_COMPENSATION_INTENSITY = FinancialDataBusinessModel::STOCK_COMPENSATION_INTENSITY;

    // --- Analyst Visibility & Error ---
    public const BASE_COVERAGE_VISIBILITY = 0.85;
    public const BASE_COVERAGE_ERROR = 0.08;

    // --- Return Reversion ---
    /** Pull of the trailing return toward the required return plus the moat (0.1 a year). */
    public const ROIC_REVERSION_SPEED = 0.1;
    /** Excess return over the required return the licence sustains (4pp). */
    public const MOAT_SPREAD = 0.04;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.3): matching engines are refreshed on a schedule. */
    public const CAPEX_CYCLICALITY = 0.3;
    /** Share of construction in progress completed into plant each quarter (100%). */
    public const CAPEX_COMPLETION_RATE = 1.0;

    // --- Revenue Streams ---
    /** Share trading: fees on the value matched on the Aerie Exchange (40%). */
    public const EQUITY_TRADING_WEIGHT = 0.40;
    /** Commodity futures: fees per contract on the Commodity Exchange (20%). */
    public const COMMODITY_FUTURES_WEIGHT = 0.20;
    /** Listing fees: annual fees on listed companies plus initial fees on new listings (10%). */
    public const LISTING_WEIGHT = 0.10;
    /** Market data, index licences and colocation (30%). */
    public const MARKET_DATA_WEIGHT = 0.30;

    // --- Line Drifts ---
    /**
     * Market data and index licences' drift against the industry (+3% a year): funds that track an index pay for it,
     * and trading desks buy ever more of the feed. Share trading, futures and listings follow the industry.
     */
    public const MARKET_DATA_DRIFT = 0.03;
    /** Each line's drift against the industry. */
    public const LINE_DRIFTS = ['market_data' => self::MARKET_DATA_DRIFT];

    // --- Working Capital by Stream (shares of the stream's annual revenue) ---
    /** Trading fees: billed to members monthly and paid in about a month; little owed out. */
    public const TRADING_WORKING_CAPITAL = ['receivables' => 0.08, 'inventory' => 0.0, 'payables' => 0.03];
    /** Listing fees: billed a year ahead each January, so about half a year sits as deferred revenue. */
    public const LISTING_WORKING_CAPITAL = ['receivables' => 0.05, 'inventory' => 0.0, 'payables' => 0.50];
    /** Data and colocation: the data vendors' subscription terms (FinancialDataBusinessModel). */
    public const MARKET_DATA_WORKING_CAPITAL = FinancialDataBusinessModel::SUBSCRIPTION_WORKING_CAPITAL;

    // --- Revenue Noise ---
    /** Volatility multiplier for the trading streams' idiosyncratic revenue shocks. */
    public const TRADING_VARIANCE_SCALAR = 0.15;
    /** Volatility multiplier for the subscription streams' idiosyncratic revenue shocks. */
    public const SUBSCRIPTION_VARIANCE_SCALAR = 0.05;

    // --- Listings ---
    /** Share of listing fees paid on new listings rather than annually (30%); only this part follows deal activity. */
    public const INITIAL_LISTING_SHARE = 0.30;

    // --- Commodity Futures Volume ---
    /** Commodity basket weights by contract family: energy, base metals, farm goods. */
    public const COMMODITY_BASKET = ['energy' => 0.45, 'metals' => 0.30, 'agriculture' => 0.25];
    /**
     * Futures volume per unit of the basket's volatility over its trend: half the share market's turnover elasticity
     * (LiquidityEngine), since a hedger holds through a year of swings rather than trading each one. Doubled
     * volatility adds about a fifth to volume.
     */
    public const COMMODITY_VOLUME_VOLATILITY_ELASTICITY = 0.30;
    /** Years over which the basket's realised variance is averaged into the current reading. */
    public const COMMODITY_VARIANCE_EMA_YEARS = 1.0;
    /**
     * Years over which that reading becomes the market's normal: past the commodity cycle, so a busy year reads as
     * busy.
     */
    public const COMMODITY_VARIANCE_TREND_YEARS = 5.0;
    /** Persisted log level of the commodity basket at the last quarter. */
    public const STATE_COMMODITY_BASKET_LOG = 'state:commodity_basket_log';
    /** Persisted realised variance of the basket, annualised and smoothed. */
    public const STATE_COMMODITY_VARIANCE = 'state:commodity_variance';
    /** Persisted multi-year trend of that variance. */
    public const STATE_COMMODITY_VARIANCE_TREND = 'state:commodity_variance_trend';
    /** Persisted count of quarterly moves measured so far. */
    public const STATE_COMMODITY_SAMPLES = 'state:commodity_samples';

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, \App\DTO\MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams  = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);

        $activeWeights = $streams->resolveActiveStreamWeights($this->resolveLineShares($stock, $macroState->totalTime));

        $equityZ    = $streams->generateZ('equity_trading', 0.20);
        $commodityZ = $streams->generateZ('commodity_futures', 0.20);
        $listingZ   = $streams->generateZ('listings', 0.40);
        $dataZ      = $streams->generateZ('market_data', 0.50);

        $tradingNoise = $baselineVol * self::TRADING_VARIANCE_SCALAR;
        $subscriptionNoise = $baselineVol * self::SUBSCRIPTION_VARIANCE_SCALAR;

        $streamRevenues = [
            'equity_trading'    => max(0.0, $expectedRevenue * $activeWeights['equity_trading'] * (1.0 + ($equityZ * $tradingNoise) + $this->resolveEquityTradingShift($macroState))),
            'commodity_futures' => max(0.0, $expectedRevenue * $activeWeights['commodity_futures'] * (1.0 + ($commodityZ * $tradingNoise) + $this->resolveCommodityVolumeShift($macroState, $streams))),
            'listings'          => max(0.0, $expectedRevenue * $activeWeights['listings'] * (1.0 + ($listingZ * $subscriptionNoise) + $this->resolveListingShift($macroState))),
            'market_data'       => max(0.0, $expectedRevenue * $activeWeights['market_data'] * (1.0 + ($dataZ * $subscriptionNoise))),
        ];

        $actualRevenue = max(0.0, array_sum($streamRevenues));
        $streams->recordStreamShares($streamRevenues);

        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);
        $clampedMargin = $this->clampMargin($realizedVariableMargin + $inputCostDrag);

        // Share-trading fees net of their variable cost, at the founding duty, as a share of revenue: what the market
        // prices a change in the stamp duty against (annualStampDutyTurnoverBase()). The measured turnover already
        // carries the duty in force, so it is divided out here rather than charged again.
        $dutyVolumeFactor = MacroTransmission::calculateStampDutyVolumeFactor($macroState->stampDutyRate);
        $streams->registerState(
            FinancialConstants::STATE_STAMP_DUTY_TURNOVER_SHARE,
            $actualRevenue > 0.0 ? $streamRevenues['equity_trading'] / $dutyVolumeFactor * (1.0 - $clampedMargin) / $actualRevenue : 0.0,
        );

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $clampedMargin,
            eventType: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
        );
    }

    /**
     * Trading and listings carry their own market terms and data renews through the cycle, so the root shift keeps
     * only the exchange-rate term.
     */
    public function getMacroPhysics(Stock $stock, \App\DTO\MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);
        $physics['macro_demand_shift'] = $this->resolveFxDemandShift($macroState);

        return $physics;
    }

    /**
     * The activity the cost base is staffed to: share trading and listings at their share of the plan. The commodity
     * term reads persisted state the quarter's physics advances, so it stays out of this pure read.
     */
    public function resolveSectorActivityShift(Stock $stock, \App\DTO\MacroStateDTO $macroState): float
    {
        $shares = $this->resolveLineShares($stock, $macroState->totalTime);

        return ($shares['equity_trading'] * $this->resolveEquityTradingShift($macroState))
            + ($shares['listings'] * $this->resolveListingShift($macroState));
    }

    /**
     * Each line's share of the plan: the opening mix with market data compounding its faded drift against the
     * industry. The exchange's mix is the industry's, so the drift moves its mix and not its growth.
     *
     * @return array{equity_trading: float, commodity_futures: float, listings: float, market_data: float}
     */
    public function resolveLineShares(Stock $stock, float $simYears): array
    {
        return TimeSeries::driftedLineShares(self::targetStreamWeights(), self::LINE_DRIFTS, $simYears, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS) + self::targetStreamWeights();
    }

    /**
     * Value traded against normal. Value traded is turnover times the board's value, so its deviation is the product
     * of the two deviations: a sell-off that doubles turnover on a board worth a third less still adds a third.
     */
    private function resolveEquityTradingShift(\App\DTO\MacroStateDTO $macroState): float
    {
        return ((1.0 + $macroState->boardTurnoverDeviation()) * (1.0 + $macroState->equityWealthDeviation())) - 1.0;
    }

    /** Listing fees against normal: only the new-listing part follows deal activity. */
    private function resolveListingShift(\App\DTO\MacroStateDTO $macroState): float
    {
        return self::INITIAL_LISTING_SHARE * $macroState->dealActivityDeviation();
    }

    /**
     * Futures volume against normal: the commodity basket's realised volatility over its own trend, raised to the
     * futures volume elasticity. Each quarter's basket move feeds the persisted variance and its trend; both open as
     * running means of the moves seen so far and become smoothed averages once their horizons hold that many quarters,
     * so no single quarter sets the normal.
     */
    private function resolveCommodityVolumeShift(\App\DTO\MacroStateDTO $macroState, StreamContext $streams): float
    {
        $basketLog = (self::COMMODITY_BASKET['energy'] * log(max(1e-6, $macroState->energyPriceIndexEma)))
            + (self::COMMODITY_BASKET['metals'] * log(max(1e-6, $macroState->industrialMetalsIndexEma)))
            + (self::COMMODITY_BASKET['agriculture'] * log(max(1e-6, $macroState->agriculturalCommodityIndexEma)));
        $previousLog = $streams->getPersistedState(self::STATE_COMMODITY_BASKET_LOG, $basketLog);
        $streams->registerState(self::STATE_COMMODITY_BASKET_LOG, $basketLog);

        $variance = $streams->getPersistedState(self::STATE_COMMODITY_VARIANCE);
        $trend = $streams->getPersistedState(self::STATE_COMMODITY_VARIANCE_TREND);
        $samples = $streams->getPersistedState(self::STATE_COMMODITY_SAMPLES);
        if ($basketLog !== $previousLog) {
            $samples += 1.0;
            $realised = (($basketLog - $previousLog) ** 2) / FinancialConstants::QUARTER_YEARS;
            $varianceWeight = max(1.0 / $samples, TimeSeries::smoothingWeight(FinancialConstants::QUARTER_YEARS, self::COMMODITY_VARIANCE_EMA_YEARS));
            $trendWeight = max(1.0 / $samples, TimeSeries::smoothingWeight(FinancialConstants::QUARTER_YEARS, self::COMMODITY_VARIANCE_TREND_YEARS));
            $variance += $varianceWeight * ($realised - $variance);
            $trend += $trendWeight * ($realised - $trend);
        }
        $streams->registerState(self::STATE_COMMODITY_VARIANCE, $variance);
        $streams->registerState(self::STATE_COMMODITY_VARIANCE_TREND, $trend);
        $streams->registerState(self::STATE_COMMODITY_SAMPLES, $samples);

        if ($variance <= 0.0 || $trend <= 0.0) {
            return 0.0;
        }

        // The variance ratio to the half power is the volatility ratio.
        return (($variance / $trend) ** (0.5 * self::COMMODITY_VOLUME_VOLATILITY_ELASTICITY)) - 1.0;
    }

    /** @return array{equity_trading: float, commodity_futures: float, listings: float, market_data: float} */
    private static function targetStreamWeights(): array
    {
        return [
            'equity_trading'    => self::EQUITY_TRADING_WEIGHT,
            'commodity_futures' => self::COMMODITY_FUTURES_WEIGHT,
            'listings'          => self::LISTING_WEIGHT,
            'market_data'       => self::MARKET_DATA_WEIGHT,
        ];
    }

    /** Trading bills monthly, listings a year ahead, data on subscription terms, at the mix the last report sold. */
    protected function resolveWorkingCapitalShares(Stock $stock): array
    {
        $weights = StreamContext::filedWeights($stock->getEarningsMomentumZ() ?? [], self::targetStreamWeights());

        return FirmEconomics::blendWorkingCapitalShares(
            [$weights['equity_trading'] + $weights['commodity_futures'], $weights['listings'], $weights['market_data']],
            [self::TRADING_WORKING_CAPITAL, self::LISTING_WORKING_CAPITAL, self::MARKET_DATA_WORKING_CAPITAL],
        );
    }

    /**
     * MacroStateDTO fields (snake_case) this model's operating physics genuinely reads in
     * calculateSectorPhysics()/getMacroPhysics() — see OperatingStrategyInterface for the full rule.
     *
     * @return list<string>
     */
    public function getOperatingMacroFields(): array
    {
        return [
            'agricultural_commodity_index_ema',
            'board_turnover_ema',
            'board_turnover_trend',
            'deal_activity_index_ema',
            'energy_price_index_ema',
            'equity_wealth_ratio',
            'equity_wealth_trend',
            'exchange_rate_index_ema',
            'import_price_level',
            'industrial_metals_index_ema',
            'output_gap_ema',
            'real_wage_gap',
            'stamp_duty_rate',
            'tips_breakeven_ema',
        ];
    }
}
