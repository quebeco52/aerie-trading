<?php

declare(strict_types=1);

namespace App\Service\Market;

use App\DTO\OptionQuoteDTO;
use App\DTO\SovereignCurveDTO;
use App\Entity\OptionContract;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MathUtility;

/**
 * Quotes listed options off the same process the price engine simulates.
 *
 * The point of this class is that it invents no surface. A stock in this market moves as a Heston variance
 * with Kou double-exponential jumps, and every parameter of that process is already carried on the row: the
 * spot and long-run volatilities, the jump intensity, the calibrated jump scale. Those parameters have a
 * skewness and an excess kurtosis at any horizon in closed form, and a distribution with a known skewness
 * and kurtosis has a known implied-volatility smile (Backus, Foresi & Wu 2004). So the smile is a
 * CONSEQUENCE of how the underlying is simulated rather than a shape fitted on top of it, and a name whose
 * jumps are violent and left-skewed quotes a steep put skew for the same reason it actually crashes.
 *
 * Three pieces, in order:
 *
 *   1. The at-the-money volatility is the variance expected over the contract's life, not today's. Under a
 *      mean-reverting variance that is the closed-form integral in averageMeanRevertingVolatility(); using
 *      spot volatility instead asserts that today's shock lasts undiminished to expiry, which is wrong in
 *      the same direction on every contract at once after any shock.
 *   2. The shape comes from the jump the price process will actually realize, taken through
 *      MarketEngine::calibratedJumpParameters() so that the desk and the tape agree on the same jump. A desk
 *      quoting the seed's uncalibrated jump would be selling a distribution the market never produces.
 *   3. The level is marked up by the variance risk premium, because a desk that quotes its own forecast of
 *      realized variance loses money on average.
 *
 * Everything past that is Black-Scholes-Merton at the smile's volatility, which is what an option desk
 * actually quotes: the model produces a volatility, the volatility produces a premium, and the greeks come
 * from the same closed form so a hedge and a mark can never disagree.
 */
final class OptionPricingEngine
{
    public function __construct(
        private readonly MathUtility $mathUtility,
        private readonly BondPricingEngine $bondPricingEngine,
    ) {}

    /**
     * The volatility surface of one name at one expiry: its level and its shape.
     *
     * Computed once per expiry and reused across the whole strike ladder, which is both what a desk does and
     * the only way a chain of a hundred contracts reprices inside a tick.
     *
     * @param float $spotVolatility    Today's TOTAL annualized volatility, as the tape carries it.
     * @param float $longRunVolatility The name's configured total volatility.
     * @param float $beta              Its market loading.
     * @param float $lambda            Jump intensity, arrivals per year.
     * @param float $jumpVol           Configured jump scale, before calibration.
     * @param float $timeToExpiry      Years to expiry.
     * @return array{atm_volatility: float, skewness: float, excess_kurtosis: float}
     */
    public function surface(
        float $spotVolatility,
        float $longRunVolatility,
        float $beta,
        float $lambda,
        float $jumpVol,
        float $timeToExpiry
    ): array {
        if ($timeToExpiry < MathUtility::MIN_OPTION_TIME_TO_EXPIRY) {
            return ['atm_volatility' => MathUtility::MIN_OPTION_VOLATILITY, 'skewness' => 0.0, 'excess_kurtosis' => 0.0];
        }

        // The variance expected between now and expiry, marked up by what the desk charges for carrying it.
        $expectedVolatility = $this->mathUtility->averageMeanRevertingVolatility(
            $spotVolatility,
            $longRunVolatility,
            MarketEngine::varianceReversionSpeed($lambda),
            $timeToExpiry
        );

        $atmVolatility = $expectedVolatility * FinancialConstants::OPTION_VARIANCE_RISK_PREMIUM;
        $totalVariance = $atmVolatility * $atmVolatility;

        $jump = MarketEngine::calibratedJumpParameters($longRunVolatility, $beta, $lambda, $jumpVol);
        $pUp = MarketEngine::jumpProbabilityUp();

        // The jump's share of that variance, so the diffusion is handed the REMAINDER and the shape function
        // adds the two back to exactly the variance the contract is being struck on. Charging the jump on
        // top instead would quote a name at more volatility than its own tape realizes.
        $jumpVariance = max(0.0, $lambda) * $this->mathUtility->calculateKouJumpMoment(2, $pUp, $jump['eta_up'], $jump['eta_down']);
        $diffusionVariance = max(0.0, $totalVariance - $jumpVariance);

        $shape = $this->mathUtility->calculateJumpDiffusionShape(
            $diffusionVariance,
            $lambda,
            $pUp,
            $jump['eta_up'],
            $jump['eta_down'],
            $timeToExpiry
        );

        return [
            'atm_volatility' => $atmVolatility,
            'skewness' => $shape['skewness'],
            'excess_kurtosis' => $shape['excess_kurtosis'],
        ];
    }

    /**
     * The surface of a stock at one expiry, from what the row carries.
     *
     * @param float|null $leveredBeta The beta the price step is using, when the caller has already computed
     *                                it; the stored asset beta otherwise.
     * @return array{atm_volatility: float, skewness: float, excess_kurtosis: float}
     */
    public function surfaceFor(Stock $stock, float $timeToExpiry, ?float $leveredBeta = null): array
    {
        $spotVolatility = $stock->getCurrentVolatility() !== null
            ? (float) $stock->getCurrentVolatility()
            : (float) $stock->getVolatility();

        return $this->surface(
            $spotVolatility,
            (float) $stock->getVolatility(),
            $leveredBeta ?? (float) $stock->getBeta(),
            (float) $stock->getJumpIntensity(),
            (float) $stock->getJumpVol(),
            $timeToExpiry
        );
    }

    /**
     * The continuous dividend yield the spot leg is discounted at.
     *
     * Stock::$lastDividend is one QUARTER's payment per share, so the run rate is four of them. A holder of
     * the option does not receive it and a holder of the stock does, which is the whole of why a call on a
     * payer is worth less than a call on an identical non-payer.
     */
    public function dividendYield(Stock $stock): float
    {
        $price = (float) $stock->getPrice();

        if ($price <= 0.0) {
            return 0.0;
        }

        return max(0.0, ((float) $stock->getLastDividend() * 4.0) / $price);
    }

    /**
     * The zero rate a contract of this tenor discounts at, read off the same sovereign curve the bond desk
     * prices against. An option desk funds on the curve, not on the policy rate.
     */
    public function riskFreeRate(SovereignCurveDTO $curve, float $timeToExpiry): float
    {
        return $this->bondPricingEngine->zeroYield($curve, max(MathUtility::MIN_OPTION_TIME_TO_EXPIRY, $timeToExpiry));
    }

    /**
     * Prices one strike against a surface that has already been struck for its expiry.
     *
     * @param array{atm_volatility: float, skewness: float, excess_kurtosis: float} $surface
     */
    public function quote(
        float $spot,
        float $strike,
        bool $isCall,
        array $surface,
        float $riskFreeRate,
        float $dividendYield,
        float $timeToExpiry
    ): OptionQuoteDTO {
        $atmVolatility = $surface['atm_volatility'];

        // d1 has to be evaluated at the AT-THE-MONEY volatility: the expansion is a function of where the
        // strike sits on the reference distribution, so feeding it a volatility that already depends on the
        // strike would be solving for the answer with the answer.
        $deviates = $this->mathUtility->calculateBlackScholesDeviates(
            $spot,
            $strike,
            $atmVolatility,
            $riskFreeRate,
            $dividendYield,
            $timeToExpiry
        );

        $volatility = $deviates === null
            ? $atmVolatility
            : $this->mathUtility->calculateGramCharlierImpliedVolatility(
                $atmVolatility,
                $deviates['d1'],
                $surface['skewness'],
                $surface['excess_kurtosis']
            );

        $mark = $this->mathUtility->calculateBlackScholesPrice(
            $spot,
            $strike,
            $volatility,
            $riskFreeRate,
            $dividendYield,
            $timeToExpiry,
            $isCall
        );

        $greeks = $this->mathUtility->calculateBlackScholesGreeks(
            $spot,
            $strike,
            $volatility,
            $riskFreeRate,
            $dividendYield,
            $timeToExpiry,
            $isCall
        );

        $mark = max(FinancialConstants::OPTION_MIN_PREMIUM, $mark);
        $halfSpread = $this->halfSpread($mark, $greeks['vega']);

        return new OptionQuoteDTO(
            mark: $mark,
            bid: max(0.0, $mark - $halfSpread),
            ask: $mark + $halfSpread,
            impliedVolatility: $volatility,
            delta: $greeks['delta'],
            gamma: $greeks['gamma'],
            vega: $greeks['vega'],
            theta: $greeks['theta'],
            rho: $greeks['rho'],
            timeToExpiry: $timeToExpiry,
            riskFreeRate: $riskFreeRate,
            dividendYield: $dividendYield,
        );
    }

    /**
     * Prices one listed contract as of a tick.
     *
     * @param array{atm_volatility: float, skewness: float, excess_kurtosis: float}|null $surface Reuse across
     *        a ladder; struck here when the caller is pricing a single contract.
     */
    public function quoteContract(
        OptionContract $contract,
        SovereignCurveDTO $curve,
        float $currentTime,
        ?array $surface = null,
        ?float $leveredBeta = null
    ): OptionQuoteDTO {
        $stock = $contract->getStock();
        $timeToExpiry = $contract->timeToExpiry($currentTime);

        return $this->quote(
            (float) $stock->getPrice(),
            (float) $contract->getStrike(),
            $contract->isCall(),
            $surface ?? $this->surfaceFor($stock, $timeToExpiry, $leveredBeta),
            $this->riskFreeRate($curve, $timeToExpiry),
            $this->dividendYield($stock),
            $timeToExpiry
        );
    }

    /**
     * Prices a whole chain, striking one surface per expiry.
     *
     * @param array<int, OptionContract> $contracts
     * @return array<string, OptionQuoteDTO> Keyed by contract ticker.
     */
    public function quoteChain(array $contracts, SovereignCurveDTO $curve, float $currentTime, ?float $leveredBeta = null): array
    {
        $surfaces = [];
        $rates = [];
        $yields = [];
        $quotes = [];

        foreach ($contracts as $contract) {
            $stock = $contract->getStock();
            $timeToExpiry = $contract->timeToExpiry($currentTime);
            $key = $stock->getTicker() . '|' . $contract->getExpirySerial();

            if (!isset($surfaces[$key])) {
                $surfaces[$key] = $this->surfaceFor($stock, $timeToExpiry, $leveredBeta);
                $rates[$key] = $this->riskFreeRate($curve, $timeToExpiry);
                $yields[$key] = $this->dividendYield($stock);
            }

            $quotes[$contract->getTicker()] = $this->quote(
                (float) $stock->getPrice(),
                (float) $contract->getStrike(),
                $contract->isCall(),
                $surfaces[$key],
                $rates[$key],
                $yields[$key],
                $timeToExpiry
            );
        }

        return $quotes;
    }

    /**
     * The half-spread the desk quotes around its mark.
     *
     * An option desk does not quote a spread in premium, it quotes one in VOLATILITY: what it is trading is
     * variance, and what it is exposed to between the two sides of the trade is vega. So the premium spread
     * is the volatility spread times vega, which automatically makes a long-dated at-the-money contract wide
     * in absolute terms and a near-expiry wing narrow, exactly as a real chain reads.
     *
     * Bounded as a fraction of the premium at both ends: a deep in-the-money contract has almost no vega and
     * would otherwise quote for free, and a far out-of-the-money one has more vega than premium and would
     * otherwise quote a negative bid.
     */
    private function halfSpread(float $mark, float $vega): float
    {
        $vegaSpread = abs($vega) * FinancialConstants::OPTION_HALF_SPREAD_VOLATILITY;

        return max(
            $mark * FinancialConstants::OPTION_MIN_HALF_SPREAD_FRACTION,
            min($mark * FinancialConstants::OPTION_MAX_HALF_SPREAD_FRACTION, $vegaSpread)
        );
    }
}
