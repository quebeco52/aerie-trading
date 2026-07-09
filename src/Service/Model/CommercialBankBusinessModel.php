<?php

declare(strict_types=1);

namespace App\Service\Model;

use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Macro\MacroEngine;
use App\Service\Event\ShockEvent;

/**
 * Earnings strategy for Commercial Banks.
 * 
 * Financial Physics:
 * - Profits are driven by Net Interest Margin (NIM) and the spread between wholesale/deposit rates and lending rates.
 * - Evaluated strictly on Return on Equity (ROE) rather than ROIC.
 * - Customer deposits act as operating leverage (inventory), requiring an APY Beta to prevent capital flight.
 */
class CommercialBankBusinessModel extends AbstractBusinessModel
{
    // --- Revenue & Shock Physics ---
    /** Baseline volatility multiplier for loan origination and fee revenue shocks. */
    public const REVENUE_VARIANCE_SCALAR = 0.15;
    /** LGD (Loss-Given-Default) multiplier: collateralized loans suffer lower realized losses than unsecured credit. */
    public const MACRO_DEFAULT_LGD_DRAG  = 0.40;
    /** Provision charge per z-unit when the default z-score breaches the stress threshold. */
    public const LOSS_PROVISION_Z_FACTOR = 0.04;

    // --- Provision Reserve Release (Scaled, Replaces Flat Reversal) ---
    /** Z-score threshold above which benign credit conditions trigger a reserve release. */
    public const PROVISION_RELEASE_Z_FLOOR    = 1.00;
    /** Cost reduction per z-unit of benign credit conditions above the release threshold. */
    public const PROVISION_REVERSAL_SCALE      = 0.015;
    /** Maximum quarterly reserve release clamp (4% of revenue — avoids 2021-style unlimited reversal). */
    public const MAX_PROVISION_REVERSAL        = 0.04;

    // --- CECL Forward Provisioning (Credit Spread Channel) ---
    /** Baseline investment-grade corporate credit spread (~200bps). Widening above this triggers proactive reserve builds. */
    public const CECL_BASELINE_CREDIT_SPREAD  = 0.020;
    /** Variable cost add-on per unit of spread widening above baseline. +100bps widening = +8% cost add-on. */
    public const CECL_SPREAD_SENSITIVITY       = 0.80;

    // --- NIM (Net Interest Margin) Squeeze ---
    /** Break-even NIM floor (~50bps). Steep curve = profit; flat or inverted curve = squeeze. */
    public const NIM_BASE_SPREAD_BUFFER        = 0.005;
    /** Calibrated so a -100bps inversion produces ~10% variable cost add-on. Tune with NIM_QUADRATIC_COEFF. */
    public const NIM_INVERSION_SENSITIVITY     = 10.0;
    /** Quadratic amplifier at extreme inversions. Formula: pow(abs(spread) * SENSITIVITY, 2) * COEFF. */
    public const NIM_QUADRATIC_COEFF           = 0.10;
    /** Structural minimum efficiency ratio: the lowest cost-to-revenue ratio any bank can reach, even at perfect NIM.
     *  Grounded in the fixed portion of bank costs (personnel, tech, compliance ~50-55% of total costs).
     *  Even JPMorgan's best quarter never broke below ~62%. 0.55 is a theoretical minimum for the leanest operators. */
    public const MIN_EFFICIENCY_RATIO          = 0.55;

    // --- Analyst Visibility ---
    /** Fraction of quarterly revenue variance visible to analysts via NIM guidance and Fed H.8 balance sheet data. */
    public const REVENUE_ANALYST_VISIBILITY    = 0.65;
    /** Standard deviation of analyst estimation noise on bank revenue. */
    public const ANALYST_ERROR_STD_DEV         = 0.06;

    // --- Yield Safety Rails ---
    /** Hard ceiling on gross asset yield: prevents hyperinflated loan yields during margin compression. */
    public const MAX_GROSS_ASSET_YIELD  = 0.40;
    /** EBIT floor as a fraction of core liabilities: ensures the bank never shuts down its loan book. */
    public const MIN_CORE_LENDING_YIELD = 0.015;

    // --- Deposit Beta: exp(-decayRate * utilization) model ---
    /** Maximum beta offered at zero utilization — how much of the policy rate the bank passes to depositors. */
    public const DEPOSIT_BETA_AMPLITUDE        = 0.80;
    /** Base exponential decay rate: erodes the beta as the bank approaches its regulatory leverage limit. */
    public const DEPOSIT_BETA_BASE_DECAY       = 0.50;
    /** Accelerating decay when the bank is heavily deposit-funded and competing harder for cheap liabilities. */
    public const DEPOSIT_BETA_RATIO_DECAY_MULT = 2.00;
    /** Upper bound: banks rarely pay more than 70% of the policy rate to retain depositors. */
    public const MAX_DEPOSIT_BETA              = 0.70;
    /** Lower bound: regulatory and reputational floor — banks always pay some yield. */
    public const MIN_DEPOSIT_BETA              = 0.10;
    /** Market-average beta baseline: normalizes competitive advantage to 1.0 at the sector mean. */
    public const DEPOSIT_BETA_NORMALIZATION_BASELINE = 0.20;

    // --- Hoarding & Deposit Flight ---
    /** Fraction of total debt held as idle excess cash before the bank is flagged as a hoarder. */
    public const HOARDING_THRESHOLD_DEBT_RATIO      = 0.05;
    /** Higher idle cash fraction that triggers more aggressive capital return pressure. */
    public const MEGA_HOARDING_THRESHOLD_DEBT_RATIO = 0.10;
    /** Policy rate gap above which depositors flee to money-market funds (calibrated to 2022-23 cycle). */
    public const YIELD_FLIGHT_POLICY_RATE_OFFSET    = 0.01;

    /**
     * Returns a stable structural ROIC proxy to keep top-line loan revenue rock solid.
     * Dynamic NIM (Net Interest Margin) expansion/compression is handled strictly in generateIdiosyncraticShock.
     *
     * @param Stock       $stock       The bank stock entity.
     * @param array       $macroState  The macroeconomic state.
     * @param MathUtility $mathUtility Mathematical utility.
     * @return array{invested_capital: float, baseline_roic: float}
     */
    public function getTargetMetrics(Stock $stock, array &$macroState, MathUtility $mathUtility): array
    {
        $equity = (float) $stock->getTotalEquity();
        $totalDebt = (float) $stock->getTotalDebt();
        $treasury = (float) $stock->getCorporateTreasury();

        $effectiveEquity = max(1.0, $equity);

        // Earning Assets represent the physical capital deployed into loans.
        // It is Equity + Total Debt, minus cash sitting idle in the Treasury.
        $earningAssets = max($effectiveEquity, $effectiveEquity + $totalDebt - $treasury);
        $baselineRoe = max(0.01, (float) $stock->getBaselineRoe());

        $ttmRoe = (float) $stock->getRoeTtm();
        if ($ttmRoe !== 0.0) {
            $baselineRoe = ($baselineRoe * 0.70) + ($ttmRoe * 0.30);
        }

        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;

        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + 0.005);
        $structuralSpread = (float) $stock->getCreditSpread();
        $floatingRatio = (float) $stock->getFloatingDebtRatio();

        $taxRate = $macroState['corporate_tax_rate'] ?? MacroEngine::BASE_CORPORATE_TAX_RATE;

        $customerDeposits = (float) $stock->getCustomerDeposits();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();

        // Calculate what the bank MUST pay depositors to keep them from fleeing.
        $depositRatio = $totalDebt > 0 ? ($customerDeposits / $totalDebt) : 0.0;
        $depositBeta = $this->calculateDepositBeta($totalDebt, $equity, $equityLimit, $customerDeposits);
        $depositRate = max(0.001, $policyRate * $depositBeta);

        $blendedWholesaleRate = ($floatingRatio * $policyRate) + ((1.0 - $floatingRatio) * $yield5y) + $structuralSpread;

        // --- THE CLEAR BALANCE SHEET MATH ---
        // We derive the structural asset yield using the bank's ACTUAL deployed leverage (capped at regulatory limits).
        // This prevents the "Phantom Debt" exploit, where banks operating below max leverage
        // pocket the theoretical interest expense as pure Net Income, causing ROE to hyper-inflate.

        $actualLeverage = $effectiveEquity > 0 ? ($totalDebt / $effectiveEquity) : 0.0;
        $allowedLeverage = min($actualLeverage, max(1.0, $equityLimit));
        $optimalDebt = $effectiveEquity * $allowedLeverage;

        $optimalEarningAssets = $effectiveEquity + $optimalDebt;

        $optimalWholesaleDebt = $optimalDebt * (1.0 - $depositRatio);
        $optimalDeposits = $optimalDebt * $depositRatio;
        $optimalInterestExpense = ($optimalWholesaleDebt * $blendedWholesaleRate) + ($optimalDeposits * $depositRate);

        $optimalNetIncome = $effectiveEquity * $baselineRoe;
        $optimalEbt = $optimalNetIncome / (1.0 - $taxRate);

        // At optimal leverage, there is no idle cash generating a treasury yield, only fully deployed earning assets
        $optimalEbit = $optimalEbt + $optimalInterestExpense;
        $structuralAssetYield = $optimalEbit / max(1.0, $optimalEarningAssets);

        // Apply the mathematically pure structural yield to the ACTUAL physical loan book
        $targetEbit = $earningAssets * $structuralAssetYield;
        // ------------------------------------

        // Banks and Credit Services will never shrink their core loan book to zero just because cash yields are high.
        // We floor the target EBIT based on their core liabilities to guarantee they maintain baseline lending operations.
        $coreLiabilities = $totalDebt;
        $minLendingEbit = $coreLiabilities * self::MIN_CORE_LENDING_YIELD;

        $targetEbit = max($minLendingEbit, $targetEbit);

        $stableMargin = max(0.01, (float) $stock->getOperatingMargin());

        // We derive revenue from target EBIT to hit ROE expectations,
        // but we MUST cap the gross yield. If margins compress, uncapped
        // reverse-engineering will cause the bank's loan yields to hyperinflate!
        $unboundedRevenue = max(0.0, $targetEbit) / $stableMargin;
        $targetRevenue = min($unboundedRevenue, $earningAssets * self::MAX_GROSS_ASSET_YIELD);

        $grossYield = $targetRevenue / max(1.0, abs($earningAssets));

        return [
            'invested_capital' => $earningAssets,
            'baseline_roic' => ($grossYield * $stableMargin) * (1.0 - $taxRate)
        ];
    }

    public function getMacroPhysics(Stock $stock, array &$macroState): array
    {
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $beta = (float) $stock->getBeta();

        return [
            'macro_demand_shift' => $outputGap * $beta * 0.50, // Less demand destruction than physical goods
            'pricing_power_multiplier' => 1.0, // Top-line yields price off bond market natively
        ];
    }

    /**
     * Idiosyncratic shock applied directly to loan origination volume and fee revenue.
     * Introduces massive Loss Provision write-offs during economic downturns.
     */
    public function generateIdiosyncraticShock(Stock $stock, float $expectedRevenue, float $realizedVariableMargin, float $fixedCosts, float $baselineVol, array &$macroState, MathUtility $mathUtility): array
    {
        $revenueZ = $mathUtility->generateStandardNormal();
        $actualRevenue = $expectedRevenue * (1.0 + ($revenueZ * ($baselineVol * self::REVENUE_VARIANCE_SCALAR)));

        $defaultZ = $mathUtility->generateStandardNormal();

        // Loan Loss Provisions (Idiosyncratic Credit Cycle):
        // Collateralized loans (prime mortgages, corporate debt) have lower LGD than unsecured credit.
        $outputGap = $macroState['output_gap_ema'] ?? ($macroState['output_gap'] ?? 0.0);
        $macroDefaultDrag = $outputGap < 0.0 ? abs($outputGap) * self::MACRO_DEFAULT_LGD_DRAG : 0.0;

        if ($defaultZ < -1.5) {
            $provisionShock = abs($defaultZ) * self::LOSS_PROVISION_Z_FACTOR;
        } elseif ($defaultZ > self::PROVISION_RELEASE_Z_FLOOR) {
            // Scaled reserve release: scales with how benign conditions are, not a flat 1%.
            // Models CECL reserve releases: 2021-style large releases when credit is pristine.
            $provisionShock = -min(self::MAX_PROVISION_REVERSAL, ($defaultZ - self::PROVISION_RELEASE_Z_FLOOR) * self::PROVISION_REVERSAL_SCALE);
        } else {
            $provisionShock = 0.0;
        }
        $lossProvisionShock = $provisionShock + $macroDefaultDrag;

        // CECL Forward Provisioning (Credit Spread Channel):
        // Under CECL accounting, banks must provision against EXPECTED future losses.
        // When corporate credit spreads widen, banks build reserves proactively — before loans actually default.
        // This is a distinct, forward-looking channel orthogonal to the idiosyncratic defaultZ.
        $creditSpread = $macroState['macro_credit_spread_ema'] ?? ($macroState['macro_credit_spread'] ?? self::CECL_BASELINE_CREDIT_SPREAD);
        $ceclDrag = max(0.0, ($creditSpread - self::CECL_BASELINE_CREDIT_SPREAD) * self::CECL_SPREAD_SENSITIVITY);

        // Net Interest Margin (NIM) Squeeze:
        // Banks borrow short-term (deposits) and lend long-term (mortgages/commercial).
        // A steep yield curve is highly profitable. An inversion collapses the spread.
        // The quadratic term amplifies the pain during severe inversions (e.g., 2022-23 cycle).
        $yield10y = $macroState['yield_10y_ema'] ?? ($macroState['yield_10y'] ?? 0.04);
        $yield2y  = $macroState['yield_2y_ema']  ?? ($macroState['yield_2y']  ?? 0.03);
        $bankSpread = $yield10y - $yield2y;

        if ($bankSpread < 0) {
            $nimSqueeze = (self::NIM_BASE_SPREAD_BUFFER - $bankSpread)
                + pow(abs($bankSpread) * self::NIM_INVERSION_SENSITIVITY, 2) * self::NIM_QUADRATIC_COEFF;
        } else {
            $nimSqueeze = self::NIM_BASE_SPREAD_BUFFER - $bankSpread;
        }

        // Physics-grounded Efficiency Floor: Total Operating Costs (Fixed + Variable) / Revenue >= MIN_EFFICIENCY_RATIO.
        $minVariableMargin = max(0.01, self::MIN_EFFICIENCY_RATIO - ($fixedCosts / max(1.0, $actualRevenue)));
        $rawMargin = $realizedVariableMargin + $lossProvisionShock + $nimSqueeze + $ceclDrag;
        $clampedMargin = min(1.50, max($minVariableMargin, $rawMargin));
        $actualVariableCosts = $actualRevenue * $clampedMargin;

        $eventType = null;
        if ($defaultZ < -2.0) {
            $eventType = ShockEvent::MASSIVE_CREDIT_PROVISION;
        } elseif ($defaultZ < -1.5) {
            $eventType = ShockEvent::ELEVATED_LOAN_DEFAULTS;
        } elseif ($defaultZ > 2.0) {
            $eventType = ShockEvent::RESERVE_RELEASE;
        }

        // Analyst Visibility:
        // Banks report quarterly NIM guidance and the Fed publishes weekly H.8 balance sheet data —
        // analysts have reasonable visibility into revenue trends (~65%).
        // Loan loss provisions and CECL reserve builds are completely opaque until earnings (0% visible).
        $analystError = $mathUtility->generateStandardNormal() * self::ANALYST_ERROR_STD_DEV;
        $analystExpectedRevenue = $expectedRevenue
            * (1.0 + ($revenueZ * $baselineVol * self::REVENUE_VARIANCE_SCALAR * self::REVENUE_ANALYST_VISIBILITY))
            * (1.0 + $analystError);
        $analystExpectedVariableCosts = $analystExpectedRevenue * $realizedVariableMargin;

        return [
            'actual_revenue'                  => $actualRevenue,
            'actual_variable_costs'           => $actualVariableCosts,
            'analyst_expected_revenue'        => $analystExpectedRevenue,
            'analyst_expected_variable_costs' => $analystExpectedVariableCosts,
            'ebit'                            => $actualRevenue - $fixedCosts - $actualVariableCosts,
            'primary_shock_z'                 => abs($defaultZ) > abs($revenueZ) ? $defaultZ : $revenueZ,
            'event_type'                      => $eventType,
        ];
    }

    /**
     * Banks earn standard money-market yields only on excess liquidity that isn't actively deployed.
     */
    public function calculateInterestIncome(Stock $stock, array &$macroState, MathUtility $mathUtility): float
    {
        $operatingBase = $this->getOperatingBase($stock);
        $excessCash = max(0.0, (float) $stock->getCorporateTreasury() - ($operatingBase * 0.05));

        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;

        return $excessCash * $this->calculateCashYield($macroState, $policyRate);
    }

    /**
     * Financial companies are evaluated strictly on Return on Equity (ROE), not ROIC.
     */
    public function updateDynamicRoic(Stock $stock, float $actualTotalNetIncome, float $investedCapital, float $ebit, float $corporateTaxRate): float
    {
        $equity = (float) $stock->getTotalEquity();
        $truePostTaxReturn = $equity > 0 ? ($actualTotalNetIncome / $equity) * 4.0 : 0.0;

        $stock->setCurrentRoe((string) max(-0.50, min(1.0, $truePostTaxReturn)));

        $oldTtm = (float) $stock->getRoeTtm();
        $newTtm = $oldTtm === 0.0 ? $truePostTaxReturn : ($truePostTaxReturn * 0.25) + ($oldTtm * 0.75);
        $stock->setRoeTtm((string) max(-0.50, min(1.0, $newTtm)));

        return $truePostTaxReturn;
    }

    public function calculateTargetOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * 0.05, $currentLiability * 0.10, $wholesaleDebt * 0.05);
    }

    public function calculateMinOperatingCash(float $operatingBase, float $currentLiability, float $wholesaleDebt): float
    {
        return max($operatingBase * 0.03, $currentLiability * 0.05, $wholesaleDebt * 0.03);
    }

    public function evaluateHoardingStatus(float $treasury, float $targetCashReserves, float $operatingBase, float $totalDebt): array
    {
        $excessCash = max(0.0, $treasury - $targetCashReserves);
        return [
            'excess_cash'     => $excessCash,
            // Banks operate on fractional reserves. Holding more than HOARDING_THRESHOLD_DEBT_RATIO of their total debt in purely IDLE excess cash is hoarding.
            'is_hoarder'      => $excessCash > ($totalDebt * self::HOARDING_THRESHOLD_DEBT_RATIO),
            'is_mega_hoarder' => $excessCash > ($totalDebt * self::MEGA_HOARDING_THRESHOLD_DEBT_RATIO),
        ];
    }

    public function calculateDepositBeta(float $totalDebt, float $equity, float $equityLimit, float $customerDeposits): float
    {
        $utilization = $equity > 0.0 ? ($totalDebt / ($equity * $equityLimit)) : 1.0;
        $depositRatio = $totalDebt > 0 ? ($customerDeposits / $totalDebt) : 0.0;

        $decayRate = self::DEPOSIT_BETA_BASE_DECAY + (self::DEPOSIT_BETA_RATIO_DECAY_MULT * $depositRatio);

        return min(self::MAX_DEPOSIT_BETA, max(self::MIN_DEPOSIT_BETA, self::DEPOSIT_BETA_AMPLITUDE * exp(-$decayRate * $utilization)));
    }

    public function calculateMaxBuybackSpend(float $excessCash, float $retainedEarningsThisQuarter, bool $isMegaHoarder): float
    {
        return $isMegaHoarder ? $excessCash * 0.30 : min($excessCash * 0.10, $retainedEarningsThisQuarter);
    }

    public function calculateInterestExpenseAndWholesaleRate(Stock $stock, float $blendedFixedRate, float $floatingInterestRate, float $currentMarketFixedRate, float $policyRate, float $equityLimit, float $totalEquity, float $debt): array
    {
        $floatingRatio = (float) $stock->getFloatingDebtRatio();
        $customerDeposits = (float) $stock->getCustomerDeposits();
        $wholesaleDebt = (float) $stock->getWholesaleDebt();

        // Wholesale debt is expensive and relies on fixed/floating market rates
        $wholesaleInterest = ($wholesaleDebt * (1.0 - $floatingRatio) * $blendedFixedRate) + ($wholesaleDebt * $floatingRatio * $floatingInterestRate);
        $wholesaleRate = $wholesaleDebt > 0 ? ($wholesaleInterest / $wholesaleDebt) : $currentMarketFixedRate;

        // Deposits are cheap, but the bank must pay an APY to prevent capital flight.
        $depositBeta = $this->calculateDepositBeta($debt, $totalEquity, $equityLimit, $customerDeposits);
        $depositRate = max(0.001, $policyRate * $depositBeta);
        $depositInterest = $customerDeposits * $depositRate;

        return ['interest_expense' => $wholesaleInterest + $depositInterest, 'wholesale_rate' => $wholesaleRate];
    }

    public function getDebtExpansionAggressiveness(float $spreadMultiplier): array
    {
        return ['probability' => 0.85 + ($spreadMultiplier * 0.15), 'aggressiveness' => 0.15 + (0.35 * $spreadMultiplier)];
    }

    public function calculateOrganicCapexSpend(float $organicSpend, float $debtIssued): float
    {
        return max($organicSpend, $debtIssued * 0.95);
    }

    public function getUnfundedExpansionCapacity(float $baseCapacity, float $excessCash): float
    {
        // Banks must use their cheap deposit inflows (excess cash) before issuing expensive wholesale debt
        return max(0.0, $baseCapacity - $excessCash);
    }

    public function calculateEarningsValue(float $revenueFloorValue, float $peFairValue, ?float $fcfPerShare, float $liveWacc, MathUtility $mathUtility): float
    {
        return $peFairValue;
    }

    public function calculateFairValue(float $earningsValue, float $pbFairValue, float $normalizedEps): float
    {
        // Balance Sheet Heavy: Banks trade heavily on their Book Value (Equity).
        // If earnings collapse, investors focus almost entirely (80% weight) on the liquidation value of the loan book.
        $bookWeight = $normalizedEps > 0 ? 0.40 : 0.80;
        $earningsWeight = 1.0 - $bookWeight;
        return ($earningsValue * $earningsWeight) + ($pbFairValue * $bookWeight);
    }

    public function processPassiveLiabilityGrowth(Stock $stock, array &$macroState, array &$state, MathUtility $mathUtility): void
    {
        $currentLiabilities = $state['customerDeposits'];
        if ($currentLiabilities <= 0) return;

        $inflation = $macroState['inflation_ema'] ?? 0.02;
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        $policyRate = $macroState['policy_rate_ema'] ?? 0.04;

        $equity = (float) $stock->getTotalEquity();
        $totalDebt = $state['wholesaleDebt'] + $currentLiabilities;
        $industry = $stock->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 10.0;

        $realGdpGrowth = 0.02 + ($outputGap > 0.0 ? $outputGap * 0.5 : $outputGap * 2.0);
        $depositApyBeta = $this->calculateDepositBeta($totalDebt, $equity, $equityLimit, $currentLiabilities);
        $state['bank_apy'] = max(0.001, $policyRate * $depositApyBeta);

        // Yield Flight Penalty: If Money Market funds yield much higher than the bank's APY, depositors flee.
        $yieldFlightPenalty = max(0.0, max(0.0, $policyRate - self::YIELD_FLIGHT_POLICY_RATE_OFFSET) - $state['bank_apy']) * 1.0;
        $systemicGrowthQuarterly = ($inflation + $realGdpGrowth - $yieldFlightPenalty) / 4.0;

        $betaSensitivity = max(0.8, min(1.2, abs((float) $stock->getBeta())));

        // Competitive advantage relative to market-average deposit beta.
        // A bank paying above the normalization baseline retains and attracts more deposits.
        $competitiveAdvantage = $depositApyBeta / self::DEPOSIT_BETA_NORMALIZATION_BASELINE;

        $baseGrowth = $systemicGrowthQuarterly > 0 ? $systemicGrowthQuarterly * $betaSensitivity * $competitiveAdvantage : $systemicGrowthQuarterly * $betaSensitivity / max(0.1, $competitiveAdvantage);
        $liabilityChange = $currentLiabilities * max(-0.15, min(0.15, $baseGrowth + ($mathUtility->generateStandardNormal() * 0.005)));

        if (abs($liabilityChange) > 0) {
            $state['treasury'] += $liabilityChange;
            $state['customerDeposits'] += $liabilityChange;

            if ($state['treasury'] < 0.0) {
                $liquidityShortfall = abs($state['treasury']);
                $amtB = number_format($liquidityShortfall / 1_000_000_000, 2);
                $state['events'][] = ['event_type' => ShockEvent::BANK_RUN, 'context' => ['amount' => $amtB], 'shock' => -5.0];
            }
            $stock->setCustomerDeposits((string) max(0.0, $state['customerDeposits']));
            if (($liabilityChange / $currentLiabilities) < -0.005) $state['events'][] = ['event_type' => ShockEvent::CUSTOMER_DEPOSIT_FLIGHT, 'context' => ['amount' => number_format(abs($liabilityChange) / 1_000_000_000, 2)], 'shock' => -2.0];
            elseif (($liabilityChange / $currentLiabilities) > 0.005) $state['events'][] = ['event_type' => ShockEvent::CAPTURED_NEW_DEPOSITS, 'context' => ['amount' => number_format($liabilityChange / 1_000_000_000, 2)], 'shock' => 0.5];
        }
    }
}
