<?php

declare(strict_types=1);

namespace App\Service\Model\Sector;

use App\Data\Macro\InputOutputExposures;
use App\DTO\MacroStateDTO;
use App\DTO\SectorPhysicsResult;
use App\Entity\Stock;
use App\Service\Event\ShockEvent;
use App\Service\Math\Distributions;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Model\ModelParam;
use App\Service\Politics\Diet\Election;

/**
 * Earnings strategy for Broadcasting (television networks, news and sports channels, owned stations).
 *
 * Financial Physics:
 * - Carriage Fees: cable and broadband distributors pay a fee per subscriber for the right to carry the channels,
 *   under contracts of about three years renewed a slice at a time. Each renewal reprices that slice to what the
 *   network's audience is worth then, so the fee level moves slowly and barely with the cycle. A renewal that fails
 *   takes the channels dark on that distributor until a deal is signed.
 * - Airtime: the minutes of advertising a network airs are fixed, so advertisers' budgets set the price of a minute,
 *   not the number sold. Most of the season is sold a year ahead at the upfront, the rest at the going rate. Viewing of
 *   news follows the news: it rises with policy uncertainty, which a downturn and a campaign both lift.
 * - Political Advertising: parties and campaigns buy airtime in the year before each Diet vote, most of it in the
 *   final months.
 * - Committed Programming: sports rights, news operations and the stations are paid for whatever the airtime sells
 *   for. What does move with revenue are shares of it (sales commissions, the stations' cut of carriage, talent
 *   participations), so the variable cost ratio applies to every dollar sold.
 */
class BroadcastingBusinessModel extends StandardCorporateBusinessModel
{
    // --- Operating Cyclicality & Demand Structure ---
    /**
     * Elasticity of advertisers' budgets to the macro cycle (1.0 = the shared ad-spend shift one for one): the client
     * budgets the agencies place, at the agencies' 1.3.
     */
    public const OPERATING_CYCLICALITY = 1.30;
    /**
     * Own-price elasticity of demand: volume lost per unit of real price increase. A distributor cannot drop the news
     * and live sports its subscribers pay for; advertisers can buy rival networks.
     */
    public const PRICE_ELASTICITY_OF_DEMAND = 0.30;
    /** Share of an idiosyncratic revenue gain taken from same-industry peers: viewers are won from rival networks. */
    public const INDUSTRY_SUBSTITUTABILITY = 0.60;

    // --- Input Cost Basket ---
    /**
     * Shares of the variable cost base by input channel, measured from the BEA input-output accounts with supply-chain
     * content (labor still the model's own).
     */
    public const INPUT_COST_EXPOSURES = InputOutputExposures::BROADCASTING;

    // --- Services Pricing ---
    /** Elasticity of carriage escalators and rate cards to services (supercore) inflation. */
    public const PRICING_ELASTICITY = 0.80;
    /** Services price off core services inflation ex-housing, not goods breakevens. */
    public const PRICING_INFLATION_BASIS = 'supercore_inflation_ema';
    /** Pricing leverage: news and live sports are the channels a distributor cannot drop. */
    public const PRICING_POWER_INDEX = 0.60;

    // --- Balance Sheet Realism ---
    /** Capitalized studio, newsroom and transmitter-site leases as a fraction of annual revenue (4%). */
    public const LEASE_LIABILITY_INTENSITY = 0.04;

    // --- Analyst Visibility & Error ---
    /** Share of routine shocks analysts see (35%): carriage contracts and the upfront are disclosed. */
    public const BASE_COVERAGE_VISIBILITY = 0.35;
    /** Standard deviation of the consensus forecast error (0.04): half the revenue is contracted fees. */
    public const BASE_COVERAGE_ERROR = 0.04;

    // --- Lines of Business ---
    /** Baseline fraction of revenue from carriage fees paid by distributors. */
    public const CARRIAGE_FEES_WEIGHT = 0.48;
    /** Baseline fraction of revenue from advertising airtime: network, news and sports. */
    public const AIRTIME_ADVERTISING_WEIGHT = 0.50;
    /** Baseline fraction of revenue from political advertising, averaged over the Diet's term. */
    public const POLITICAL_ADVERTISING_WEIGHT = 0.02;
    /** Share of the airtime sold in news programming (35%), whose viewing follows the news. */
    public const NEWS_AIRTIME_SHARE = 0.35;

    // --- Carriage Contracts ---
    /** Quarters in a carriage contract (12): three-year deals, renewed a twelfth of the book each quarter. */
    public const CARRIAGE_CONTRACT_QUARTERS = 12;
    /** Share of the network's audience over normal a renewal writes into the fee (0.5). */
    public const CARRIAGE_AUDIENCE_PASS_THROUGH = 0.50;

    // --- Carriage Blackouts ---
    /**
     * Z-score of the dispute draw below which a renewal fails this quarter (-1.48, ~7%): a blackout every 3-4 years.
     */
    public const BLACKOUT_Z_SCORE = -1.48;
    /** Share of the network's subscribers on the distributor that goes dark (25%): one of the large systems. */
    public const BLACKOUT_DISTRIBUTOR_SHARE = 0.25;
    /** Share of the quarter the channels stay dark (23%): three weeks of thirteen. */
    public const BLACKOUT_QUARTER_SHARE = 0.23;

    // --- Airtime Advertising ---
    /** Share of the season's airtime sold at the upfront (60%); the rest sells at the going rate. */
    public const UPFRONT_SHARE = 0.60;
    /** Calendar quarter index the upfront is struck in (1 = April-June), for the season that opens in July. */
    public const UPFRONT_CALENDAR_QUARTER = 1;
    /** News viewing per unit of log policy uncertainty over baseline (0.5): an index 50% up lifts it ~20%. */
    public const NEWS_AUDIENCE_UNCERTAINTY_ELASTICITY = 0.50;
    /**
     * Calendar-quarter airtime seasonality [Q1, Q2, Q3, Q4] summing to 4.0: football and holiday campaigns fill the
     * fourth quarter, the playoffs the first; the spring is the thinnest (Fox, calendar 2023-24, carriage fees out).
     */
    public const AIRTIME_SEASONALITY = [0.92, 0.72, 0.98, 1.38];
    /** Firm-level seasonality at the baseline mix: carriage and campaign money flat, airtime seasonal. */
    public const SEASONALITY_FACTORS = [
        1.0 + (self::AIRTIME_ADVERTISING_WEIGHT * (self::AIRTIME_SEASONALITY[0] - 1.0)),
        1.0 + (self::AIRTIME_ADVERTISING_WEIGHT * (self::AIRTIME_SEASONALITY[1] - 1.0)),
        1.0 + (self::AIRTIME_ADVERTISING_WEIGHT * (self::AIRTIME_SEASONALITY[2] - 1.0)),
        1.0 + (self::AIRTIME_ADVERTISING_WEIGHT * (self::AIRTIME_SEASONALITY[3] - 1.0)),
    ];

    // --- Revenue Volatility ---
    /** Volatility multiplier on carriage fees: subscriber counts move little quarter to quarter. */
    public const CARRIAGE_VARIANCE_SCALAR = 0.05;
    /** Volatility multiplier on airtime: a hit or a flop moves a season's ratings. */
    public const AIRTIME_VARIANCE_SCALAR = 0.12;
    /** Volatility multiplier on political advertising: what the parties raise to spend. */
    public const POLITICAL_VARIANCE_SCALAR = 0.40;

    // --- Return Reversion ---
    /** Excess return over the required return the moat sustains (2.5pp): a news brand and long sports rights. */
    public const MOAT_SPREAD = 0.025;

    // --- Capital Cycle ---
    /** Elasticity of the capital budget to the cycle signal (0.3): studios, transmitters and production trucks. */
    public const CAPEX_CYCLICALITY = 0.30;
    /** Net working capital as a share of revenue (12%): advertisers pay two months after the spots air. */
    public const WORKING_CAPITAL_INTENSITY = 0.12;

    // --- Credit Thresholds ---
    /** Minimum interest coverage the firm is held to (3x). */
    public const MIN_ICR = 3.0;
    /** Interest coverage below which a firm without a cash buffer is in a dividend crisis (2x). */
    public const DIVIDEND_CRISIS_ICR = 2.0;
    /** Interest coverage below which the firm stops repurchasing stock (3x). */
    public const BUYBACK_MIN_ICR = 3.0;

    // --- Persisted State ---
    /** Prefix of the carriage book's renewal slots: the fee struck for each quarter's deliveries. */
    public const STATE_CARRIAGE_RATE_PREFIX = 'state:carriage_rate:';
    /** The upfront price of the season in progress, over the steady price. */
    public const STATE_UPFRONT_PRICE = 'state:upfront_price';

    public function getMacroPhysics(Stock $stock, MacroStateDTO $macroState): array
    {
        $physics = parent::getMacroPhysics($stock, $macroState);

        // The cycle reaches the price of airtime in calculateSectorPhysics(); the minutes aired do not move with it.
        $physics['macro_demand_shift'] = 0.0;

        return $physics;
    }

    /** The season at the firm's own mix: airtime seasonal, carriage and campaign money flat through the year. */
    public function resolveSeasonalityFactors(Stock $stock): array
    {
        $weights = $this->resolveLineWeights($stock);
        $total = array_sum($weights);
        $airtimeShare = $total > 0.0 ? $weights['airtime_advertising'] / $total : 0.0;

        return array_map(static fn(float $season): float => 1.0 + ($airtimeShare * ($season - 1.0)), self::AIRTIME_SEASONALITY);
    }

    protected function calculateSectorPhysics(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, MacroStateDTO $macroState, MathUtility $mathUtility): SectorPhysicsResult
    {
        $momentum = $stock->getEarningsMomentumZ() ?? [];
        $streams = $this->createStreamContext($momentum, $mathUtility, $macroState, $stock);
        $weights = $this->resolveLineWeights($stock);
        $totalWeight = array_sum($weights);
        $quarter = $this->reportedCalendarQuarter();

        // Expected revenue carries the firm-level season; the plan for each line is flat, and the airtime line takes
        // its own season back on.
        $firmSeason = $quarter !== null ? $this->resolveSeasonalityFactors($stock)[$quarter] : 1.0;
        $airtimeSeason = $quarter !== null ? self::AIRTIME_SEASONALITY[$quarter] : 1.0;
        $plan = $totalWeight > 0.0 ? $expectedRevenue / $firmSeason / $totalWeight : 0.0;

        $carriageZ = $streams->generateZ('carriage_fees', 0.80);
        $airtimeZ = $streams->generateZ('airtime_advertising', 0.40);
        $politicalZ = $streams->generateZ('political_advertising', 0.10);
        $disputeZ = $streams->generateExogenousZ('carriage_dispute', 0.0);

        $audienceShift = $this->resolveAudienceShift($stock, $macroState);

        // --- Carriage book: a twelfth renews each quarter at the fee the audience earns then ---
        $carriageRate = $streams->rollHedgeLadder(
            self::STATE_CARRIAGE_RATE_PREFIX,
            self::CARRIAGE_CONTRACT_QUARTERS,
            static fn(int $quarters): float => 1.0 + (self::CARRIAGE_AUDIENCE_PASS_THROUGH * $audienceShift),
        );

        // --- Blackout: a failed renewal darkens one distributor for part of the quarter ---
        // The expected loss is in the plan, so the quarters without one carry what the dark ones give up.
        $blackoutLoss = self::BLACKOUT_DISTRIBUTOR_SHARE * self::BLACKOUT_QUARTER_SHARE;
        $blackoutCompensation = 1.0 / (1.0 - (Distributions::standardNormalCdf(self::BLACKOUT_Z_SCORE) * $blackoutLoss));
        $isBlackout = $disputeZ < self::BLACKOUT_Z_SCORE;
        $reach = $blackoutCompensation * ($isBlackout ? 1.0 - $blackoutLoss : 1.0);

        // --- Airtime: the upfront price of the season in progress on most minutes, the going rate on the rest ---
        $adSpendShift = MacroTransmission::advertisingSpendShift($macroState->outputGapEma, $macroState->sentimentResidual(), $this->getOperatingCyclicality($stock));
        $upfrontPrice = $streams->getPersistedState(self::STATE_UPFRONT_PRICE, $adSpendShift);
        // The upfront struck this quarter prices the season that opens next quarter.
        $streams->registerState(self::STATE_UPFRONT_PRICE, $quarter === self::UPFRONT_CALENDAR_QUARTER ? $adSpendShift : $upfrontPrice);
        $airtimePrice = 1.0 + (self::UPFRONT_SHARE * $upfrontPrice) + ((1.0 - self::UPFRONT_SHARE) * $adSpendShift);

        // --- Campaign money: the quarter's nearness to the vote over its mean across the term ---
        $campaign = Election::campaignProximity($this->coveredQuarterMidpoint($macroState)) / Election::MEAN_CAMPAIGN_PROXIMITY;

        $streamRevenues = [
            'carriage_fees'         => max(0.0, $plan * $weights['carriage_fees'] * $carriageRate * $reach
                * (1.0 + ($carriageZ * $baselineVol * self::CARRIAGE_VARIANCE_SCALAR))),
            'airtime_advertising'   => max(0.0, $plan * $weights['airtime_advertising'] * $airtimeSeason * $airtimePrice * (1.0 + $audienceShift) * $reach
                * (1.0 + ($airtimeZ * $baselineVol * self::AIRTIME_VARIANCE_SCALAR))),
            'political_advertising' => $plan * $weights['political_advertising'] * $campaign
                * max(0.0, 1.0 + ($politicalZ * $baselineVol * self::POLITICAL_VARIANCE_SCALAR)),
        ];
        $actualRevenue = array_sum($streamRevenues);

        // Wages above trend reach the variable cost base and are won back at the firm's pricing power.
        $inputCostDrag = $this->resolveInputCostDrag($stock, $macroState, $streams, $this->resolvePricingPower($stock), $realizedVariableMargin);

        return new SectorPhysicsResult(
            actualRevenue: $actualRevenue,
            rawVariableMargin: $this->clampMargin($realizedVariableMargin + $inputCostDrag),
            eventType: $isBlackout ? ShockEvent::CARRIAGE_BLACKOUT : null,
            isPublicEvent: null,
            streamZ: $streams->getStreamZ(),
            streamRevenue: $streamRevenues,
            kpis: [
                'audience_index'      => 1.0 + $audienceShift,
                'carriage_fee_index'  => $carriageRate,
                'upfront_price_index' => 1.0 + $upfrontPrice,
            ],
        );
    }

    /**
     * Viewing over normal across the airtime, from news alone: its audience moves with log policy uncertainty, which
     * already carries the campaign and the slack (CreditFiscalSubsystem::calculatePolicyUncertainty()), so neither is
     * read again here.
     */
    private function resolveAudienceShift(Stock $stock, MacroStateDTO $macroState): float
    {
        $newsShare = (float) $this->resolveModelParameters($stock, [ModelParam::NewsAirtimeShare->value => self::NEWS_AIRTIME_SHARE])[ModelParam::NewsAirtimeShare];
        $uncertainty = log(max(1.0, $macroState->policyUncertaintyIndexEma) / MacroConstants::EPU_BASELINE);

        return $newsShare * self::NEWS_AUDIENCE_UNCERTAINTY_ELASTICITY * $uncertainty;
    }

    /**
     * Middle of the calendar quarter a report filed now covers, the one before the quarter in progress. The campaign
     * ramp bends only at quarter ends, so its value here is its mean over the quarter.
     */
    private function coveredQuarterMidpoint(MacroStateDTO $macroState): float
    {
        $quarters = FinancialConstants::QUARTERS_PER_YEAR;

        return (floor(round($macroState->totalTime * $quarters, 6)) - 0.5) / $quarters;
    }

    /**
     * @return array{carriage_fees: float, airtime_advertising: float, political_advertising: float}
     */
    private function resolveLineWeights(Stock $stock): array
    {
        $params = $this->resolveModelParameters($stock, [
            ModelParam::CarriageFeesWeight->value         => self::CARRIAGE_FEES_WEIGHT,
            ModelParam::AirtimeAdvertisingWeight->value   => self::AIRTIME_ADVERTISING_WEIGHT,
            ModelParam::PoliticalAdvertisingWeight->value => self::POLITICAL_ADVERTISING_WEIGHT,
        ]);

        return [
            'carriage_fees'         => (float) $params[ModelParam::CarriageFeesWeight],
            'airtime_advertising'   => (float) $params[ModelParam::AirtimeAdvertisingWeight],
            'political_advertising' => (float) $params[ModelParam::PoliticalAdvertisingWeight],
        ];
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
            'consumer_sentiment_index_ema',
            'exchange_rate_index_ema',
            'import_price_level',
            'output_gap_ema',
            'policy_uncertainty_index_ema',
            'supercore_inflation_ema',
            'tips_breakeven_ema',
            'real_wage_gap',
        ];
    }
}
