<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\ExecutionQuoteDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * How much liquidity a name has, what it costs to cross the spread, and how far an order moves the price.
 *
 * Before this existed, any order of any size filled instantly at the last published price. A player with
 * enough cash could buy an entire float at mid and leave the quote untouched, which made size free and made
 * every other market mechanic — a squeeze, a liquidation, a fund unwinding — impossible to express.
 *
 * Three published relations, none of them invented here:
 *
 *  - Volume is structural. Annual turnover as a share of the float is a property of a name, modulated by a
 *    volatility term because information arrivals drive trading and price moves together (Karpoff 1987).
 *  - The spread follows Wyart, Bouchaud, Kockelkoren, Potters & Vettorazzo (2008): S = c * sigma / sqrt(N).
 *    It hangs off the volatility the engine already simulates per name per tick, so a crash widens spreads
 *    on its own rather than through a second calibration.
 *  - Impact follows Almgren-Chriss (Almgren, Thum, Hauptmann & Li 2005), split into a permanent part that
 *    stays in the price and a temporary part the taker pays and nobody keeps. The permanent part is LINEAR
 *    in participation, which is not a simplification: Huberman & Stanzl (2004) show any other shape lets a
 *    round trip move the price for free, and it is the only form that is additive across ticks.
 *
 * The split is the whole point. A single blended impact term that permanently moved the price by the full
 * execution cost would both overcharge the trader and inflate realized volatility.
 */
final class LiquidityEngine
{
    public function __construct(
        private readonly MathUtility $mathUtility,
    ) {}

    /**
     * The structural annual turnover a name should be seeded with, from its own volatility.
     *
     * Volatile names change hands more often than quiet ones — the same information-arrival link that ties
     * volume to volatility within a name holds across names too. Deriving it means one relation rather than
     * forty hand-set numbers that would drift out of step with the volatilities they are supposed to match.
     *
     * @param float $annualVolatility The name's long-run volatility.
     */
    public static function structuralTurnoverRatio(float $annualVolatility): float
    {
        if ($annualVolatility <= 0.0) {
            return FinancialConstants::BASELINE_ANNUAL_TURNOVER;
        }

        $ratio = FinancialConstants::BASELINE_ANNUAL_TURNOVER
            * (($annualVolatility / FinancialConstants::TURNOVER_REFERENCE_VOLATILITY) ** FinancialConstants::TURNOVER_VOLATILITY_ELASTICITY);

        return max(
            FinancialConstants::MIN_ANNUAL_TURNOVER,
            min(FinancialConstants::MAX_ANNUAL_TURNOVER, $ratio)
        );
    }

    /**
     * Shares that trade in an average day.
     *
     * Structural float turnover, scaled by how active the tape currently is. The activity term runs off
     * volatility, so it RISES in a panic — volume and volatility are driven by the same information
     * arrivals (Karpoff 1987), and a crash is the busiest tape a name ever sees, not the quietest. Impact
     * still grows with stress, because volatility enters the impact law faster than depth does; what does
     * not happen is a name becoming untradable exactly when everyone wants to trade it.
     *
     * The multiplier is bounded on both sides: a rally does not manufacture unlimited depth, and the floor
     * catches the opposite case, a name gone so quiet that its structural turnover flatters it.
     */
    public function averageDailyVolume(Stock $stock): float
    {
        return max(FinancialConstants::MIN_ADV_SHARES, $this->rawStructuralDailyVolume($stock) * $this->activityMultiplier($stock));
    }

    /**
     * The name's structural daily volume: shares, float and turnover, with no regard to how busy the tape
     * is right now.
     *
     * This is what a book should be sized against. The activity-scaled figure above is the depth an order
     * meets today; sizing standing capital on it made every agent book grow into a stressed tape — the
     * passive money bought a volatility spike — and shrink out of a quiet one.
     */
    public function structuralDailyVolume(Stock $stock): float
    {
        return max(FinancialConstants::MIN_ADV_SHARES, $this->rawStructuralDailyVolume($stock));
    }

    private function rawStructuralDailyVolume(Stock $stock): float
    {
        $shares = (float) $stock->getSharesOutstanding();
        $float = max(0.0, min(1.0, (float) $stock->getPublicFloatPercentage()));
        $turnover = $stock->getTurnoverRatio() ?? FinancialConstants::BASELINE_ANNUAL_TURNOVER;

        return ($shares * $float * max(0.0, $turnover)) / FinancialConstants::TRADING_DAYS_PER_YEAR;
    }

    /**
     * How busy the tape is relative to this name's own baseline, from the volume-volatility relation.
     */
    public function activityMultiplier(Stock $stock): float
    {
        $baseline = (float) $stock->getVolatility();
        $current = (float) ($stock->getCurrentVolatility() ?? $baseline);

        if ($baseline <= 0.0 || $current <= 0.0) {
            return 1.0;
        }

        $multiplier = ($current / $baseline) ** FinancialConstants::VOLUME_VOLATILITY_ELASTICITY;

        return max(
            FinancialConstants::MIN_ADV_ACTIVITY_MULTIPLIER,
            min(FinancialConstants::MAX_ADV_ACTIVITY_MULTIPLIER, $multiplier)
        );
    }

    /**
     * Daily standard deviation of returns, from the annualized volatility the engine carries.
     */
    public function dailyVolatility(Stock $stock): float
    {
        $annual = (float) ($stock->getCurrentVolatility() ?? $stock->getVolatility());

        return max(0.0, $annual) / sqrt(FinancialConstants::TRADING_DAYS_PER_YEAR);
    }

    /**
     * Half the quoted bid-ask spread, as a fraction of the mid price.
     *
     * The trade count is derived from daily volume rather than configured separately: a name that trades
     * more prints more often, and the spread relation is stated per trade.
     */
    public function halfSpreadFraction(Stock $stock): float
    {
        $trades = max(1.0, $this->averageDailyVolume($stock) / FinancialConstants::TYPICAL_TRADE_SIZE_SHARES);

        $spread = (FinancialConstants::SPREAD_VOLATILITY_COEFFICIENT * $this->dailyVolatility($stock)) / sqrt($trades);

        return max(
            FinancialConstants::MIN_HALF_SPREAD,
            min(FinancialConstants::MAX_HALF_SPREAD, $spread / 2.0)
        );
    }

    /**
     * The log return a net signed quantity leaves permanently in the price.
     *
     * Linear in participation, signed by direction. This used to be the square-root law, which is the
     * right shape for the TOTAL cost of a metaorder but the wrong one for a mark that is applied every
     * tick and kept: a square root is concave, so the same daily flow sliced into N ticks left sqrt(N)
     * times the mark of the same flow in one tick. At forty ticks a day the NPC agents' steady buying
     * moved a name six times further than the calibration said, out-muscled fair-value reversion, and
     * blew momentum bubbles to nearly twice fair value that the tick rate alone had manufactured.
     *
     * Linear is what the literature has for the permanent leg (Almgren, Thum, Hauptmann & Li 2005 estimate
     * an exponent indistinguishable from one; Huberman & Stanzl 2004 prove it must be linear or a round
     * trip manipulates the price). Its practical property here is additivity: a flow leaves the same mark
     * whether it arrives in one tick or a thousand, so the result no longer depends on SIM_TICKS_PER_YEAR.
     *
     * @param Stock $stock          The name traded.
     * @param float $signedQuantity Positive for net buying, negative for net selling.
     */
    public function permanentImpact(Stock $stock, float $signedQuantity): float
    {
        if ($signedQuantity === 0.0) {
            return 0.0;
        }

        $participation = $signedQuantity / $this->averageDailyVolume($stock);

        return FinancialConstants::PERMANENT_IMPACT_GAMMA * $this->dailyVolatility($stock) * $participation;
    }

    /**
     * The slice of a company's own outstanding program (a repurchase, or the flowback of stock it issued)
     * that executes this tick, signed like the backlog it is drawn from.
     *
     * Paced by the SEC Rule 10b-18 volume condition: no more than a quarter of the name's average daily
     * volume per day, scaled to the step. A quarter's buyback therefore takes days or weeks to work
     * through the tape, and the price move it leaves is the same permanent impact any other buyer of
     * that many shares leaves — which is the point of routing it here rather than shocking the price.
     *
     * @param Stock $stock   The name whose program is being worked.
     * @param float $backlog Signed shares still to execute: positive buys, negative sells.
     * @param float $dt      The step, in years.
     */
    public function corporateFlowSlice(Stock $stock, float $backlog, float $dt): float
    {
        if ($backlog === 0.0 || $dt <= 0.0) {
            return 0.0;
        }

        $stepDays = $dt * FinancialConstants::TRADING_DAYS_PER_YEAR;
        $capacity = $this->averageDailyVolume($stock) * FinancialConstants::CORPORATE_FLOW_MAX_ADV_SHARE_PER_DAY * $stepDays;

        return $backlog > 0.0 ? min($backlog, $capacity) : max($backlog, -$capacity);
    }

    /**
     * Largest order the desk will take in one go, in shares.
     *
     * Beyond this the impact law is extrapolation rather than measurement. Refusing is the honest answer:
     * capping the impact instead would make size free again above the cap, which is exactly the hole this
     * engine exists to close.
     */
    public function maximumOrderSize(Stock $stock): float
    {
        return $this->averageDailyVolume($stock) * FinancialConstants::MAX_ORDER_ADV_MULTIPLE;
    }

    /**
     * Prices an order of a given size.
     *
     * The taker pays the half-spread plus half the permanent move: the price walks to its new level while
     * the order fills, so the average fill is the midpoint of that walk. The other half of the move is not
     * a cost to anyone — it is where the price now is.
     *
     * @param Stock  $stock    The name traded.
     * @param string $action   'BUY' or 'SELL'.
     * @param int    $quantity Shares, always positive.
     * @param float  $midPrice The last published price.
     */
    public function quote(Stock $stock, string $action, int $quantity, float $midPrice): ExecutionQuoteDTO
    {
        $signed = $action === 'BUY' ? (float) $quantity : -(float) $quantity;
        $direction = $action === 'BUY' ? 1.0 : -1.0;

        $advShares = $this->averageDailyVolume($stock);
        $halfSpread = $this->halfSpreadFraction($stock);
        $permanentImpact = $this->permanentImpact($stock, $signed);

        $temporaryFraction = FinancialConstants::TEMPORARY_IMPACT_ETA * abs($permanentImpact);

        $spreadCost = $midPrice * $halfSpread * $quantity;
        $impactCost = $midPrice * $temporaryFraction * $quantity;

        $executionPrice = $midPrice * (1.0 + ($direction * ($halfSpread + $temporaryFraction)));

        return new ExecutionQuoteDTO(
            midPrice: $midPrice,
            executionPrice: max(0.01, $executionPrice),
            spreadCost: $spreadCost,
            impactCost: $impactCost,
            permanentImpact: $permanentImpact,
            participationRate: $advShares > 0.0 ? abs($signed) / $advShares : 0.0,
        );
    }

    /**
     * Prices an order in any asset class.
     *
     * Only equities carry a modelled depth. A broad index ETF is arbitraged against its basket by creation
     * and redemption, and a sovereign bond is the deepest instrument on the desk, so both quote at a flat
     * half-spread with no permanent impact. Charging them nothing at all would be worse than approximate:
     * it would make the ETF a free way to buy the whole index and hand size a way around the equity book.
     *
     * @param string $assetType 'STOCK', 'ETF' or 'BOND'.
     */
    public function quoteAsset(Stock|null $stock, string $assetType, string $action, int $quantity, float $midPrice, bool $isCorporateIssue = false): ExecutionQuoteDTO
    {
        if ($assetType === 'STOCK' && $stock instanceof Stock) {
            return $this->quote($stock, $action, $quantity, $midPrice);
        }

        // A corporate issue is not a sovereign one. It trades in a fraction of the size against a fraction
        // of the buyers, and quoting it at the sovereign's depth would make credit risk free to get into and
        // out of — which is exactly the property that makes it risky.
        $halfSpread = match (true) {
            $assetType === 'BOND' && $isCorporateIssue => FinancialConstants::CORPORATE_BOND_HALF_SPREAD,
            $assetType === 'BOND' => FinancialConstants::BOND_HALF_SPREAD,
            default => FinancialConstants::ETF_HALF_SPREAD,
        };

        $direction = $action === 'BUY' ? 1.0 : -1.0;

        return new ExecutionQuoteDTO(
            midPrice: $midPrice,
            executionPrice: max(0.01, $midPrice * (1.0 + ($direction * $halfSpread))),
            spreadCost: $midPrice * $halfSpread * $quantity,
            impactCost: 0.0,
            permanentImpact: 0.0,
            participationRate: 0.0,
        );
    }

    /**
     * Shares printed in one tick.
     *
     * Clark's (1973) mixture-of-distributions hypothesis: volume and return variance are both driven by the
     * same latent information arrivals, which is why the two move together in every market anyone has
     * measured. The activity multiplier already carries that link, so what remains is the dispersion around
     * it, drawn lognormally and mean-corrected so the expected volume is the conditional mean rather than
     * something a half-sigma above it.
     *
     * @param Stock $stock        The name.
     * @param float $dt           Elapsed simulated time in years.
     * @param float $playerVolume Shares the players themselves traded this tick, which are prints too.
     */
    public function simulateTickVolume(Stock $stock, float $dt, float $playerVolume = 0.0): float
    {
        if ($dt <= 0.0) {
            return max(0.0, $playerVolume);
        }

        $expected = $this->averageDailyVolume($stock) * $dt * FinancialConstants::TRADING_DAYS_PER_YEAR;

        $sigma = FinancialConstants::VOLUME_LOGNORMAL_SIGMA;
        $noise = exp(($sigma * $this->mathUtility->generateStandardNormal()) - (($sigma * $sigma) / 2.0));

        return max(0.0, $expected * $noise) + max(0.0, $playerVolume);
    }
}
