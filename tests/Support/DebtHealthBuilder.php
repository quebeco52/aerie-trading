<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DTO\DebtHealthDTO;
use App\DTO\DebtMetricsDTO;

/**
 * Fluent builder for the debt health a DebtEngine would hand a test: a healthy investment-grade borrower by default
 * (5x coverage, open to new debt, no covenant pressure), with each change named.
 */
final class DebtHealthBuilder
{
    private float $grossCost = 0.05;
    private float $effectiveCost = 0.04;
    private float $cashYield = 0.02;
    private bool $isSevereNegativeCarry = false;
    private float $interestCoverage = 5.0;
    private bool $wantsToPaydownDebt = false;
    private bool $canIssueDebt = true;
    private float $debtTolerance = 2.0;
    private float $wacc = 0.08;
    private float $costOfEquity = 0.10;
    private float $leveredBeta = 1.0;
    private ?DebtMetricsDTO $rawMetrics = null;
    private bool $isLiquidityCrisis = false;
    private bool $isLiquidityWarning = false;
    private bool $isUnderLeveraged = false;
    private bool $hasLeverageHeadroom = true;
    private float $netDebtToEbitda = 0.0;
    private ?float $ebitdaCovenantLimit = null;
    private float $debtToEquity = 0.0;
    private float $leverageTarget = 0.0;
    private bool $hasMarketAccess = true;

    public static function healthy(): self
    {
        return new self();
    }

    /** Starts from a reading already taken, field for field, so a test changes only what it is about. */
    public static function from(DebtHealthDTO $health): self
    {
        $builder = new self();
        $builder->grossCost = $health->grossCost;
        $builder->effectiveCost = $health->effectiveCost;
        $builder->cashYield = $health->cashYield;
        $builder->isSevereNegativeCarry = $health->isSevereNegativeCarry;
        $builder->interestCoverage = $health->interestCoverage;
        $builder->wantsToPaydownDebt = $health->wantsToPaydownDebt;
        $builder->canIssueDebt = $health->canIssueDebt;
        $builder->debtTolerance = $health->debtTolerance;
        $builder->wacc = $health->wacc;
        $builder->costOfEquity = $health->costOfEquity;
        $builder->leveredBeta = $health->leveredBeta;
        $builder->rawMetrics = $health->rawMetrics;
        $builder->isLiquidityCrisis = $health->isLiquidityCrisis;
        $builder->isLiquidityWarning = $health->isLiquidityWarning;
        $builder->isUnderLeveraged = $health->isUnderLeveraged;
        $builder->hasLeverageHeadroom = $health->hasLeverageHeadroom;
        $builder->netDebtToEbitda = $health->netDebtToEbitda;
        $builder->ebitdaCovenantLimit = $health->ebitdaCovenantLimit;
        $builder->debtToEquity = $health->debtToEquity;
        $builder->leverageTarget = $health->leverageTarget;
        $builder->hasMarketAccess = $health->hasMarketAccess;

        return $builder;
    }

    /** Raw metrics for a borrower with no interest expense, a 20% EBIT margin on 5,000 of revenue and money at 5%. */
    private static function defaultMetrics(): DebtMetricsDTO
    {
        return new DebtMetricsDTO(
            interestExpense: 0.0,
            blendedRate: 0.05,
            historicalFixedRate: 0.05,
            dynamicSpread: 0.01,
            currentMarketRate: 0.05,
            wholesaleRate: 0.05,
            ebit: 1000.0,
            revenue: 5000.0,
            depreciation: 100.0,
            ebitda: 1100.0,
        );
    }

    /** What the debt costs before and after tax, and what the cash it funds earns. */
    public function withCarry(float $grossCost, float $effectiveCost, float $cashYield): self
    {
        $this->grossCost = $grossCost;
        $this->effectiveCost = $effectiveCost;
        $this->cashYield = $cashYield;

        return $this;
    }

    public function withSevereNegativeCarry(bool $isSevereNegativeCarry = true): self
    {
        $this->isSevereNegativeCarry = $isSevereNegativeCarry;

        return $this;
    }

    public function withCoverage(float $interestCoverage): self
    {
        $this->interestCoverage = $interestCoverage;

        return $this;
    }

    public function wantingToPayDownDebt(bool $wantsToPaydownDebt = true): self
    {
        $this->wantsToPaydownDebt = $wantsToPaydownDebt;

        return $this;
    }

    /** Whether the firm may borrow by choice. */
    public function withDebtIssuance(bool $canIssueDebt): self
    {
        $this->canIssueDebt = $canIssueDebt;

        return $this;
    }

    public function withDebtTolerance(float $debtTolerance): self
    {
        $this->debtTolerance = $debtTolerance;

        return $this;
    }

    public function withCostOfCapital(float $wacc, float $costOfEquity): self
    {
        $this->wacc = $wacc;
        $this->costOfEquity = $costOfEquity;

        return $this;
    }

    public function withLeveredBeta(float $leveredBeta): self
    {
        $this->leveredBeta = $leveredBeta;

        return $this;
    }

    public function withMetrics(DebtMetricsDTO $rawMetrics): self
    {
        $this->rawMetrics = $rawMetrics;

        return $this;
    }

    public function withLiquidityStress(bool $isLiquidityCrisis, bool $isLiquidityWarning): self
    {
        $this->isLiquidityCrisis = $isLiquidityCrisis;
        $this->isLiquidityWarning = $isLiquidityWarning;

        return $this;
    }

    public function underLeveraged(bool $isUnderLeveraged = true): self
    {
        $this->isUnderLeveraged = $isUnderLeveraged;

        return $this;
    }

    /** Net debt / EBITDA against the sector's covenant, and whether that leaves room for discretionary leverage. */
    public function withCovenant(float $netDebtToEbitda, ?float $ebitdaCovenantLimit, bool $hasLeverageHeadroom): self
    {
        $this->netDebtToEbitda = $netDebtToEbitda;
        $this->ebitdaCovenantLimit = $ebitdaCovenantLimit;
        $this->hasLeverageHeadroom = $hasLeverageHeadroom;

        return $this;
    }

    /** Book debt-to-equity and the ratio the firm recapitalizes toward. */
    public function withRecapitalization(float $debtToEquity, float $leverageTarget): self
    {
        $this->debtToEquity = $debtToEquity;
        $this->leverageTarget = $leverageTarget;

        return $this;
    }

    /** Whether the primary market will lend at any price: the gate for funding a shortfall. */
    public function withMarketAccess(bool $hasMarketAccess): self
    {
        $this->hasMarketAccess = $hasMarketAccess;

        return $this;
    }

    public function build(): DebtHealthDTO
    {
        return new DebtHealthDTO(
            grossCost: $this->grossCost,
            effectiveCost: $this->effectiveCost,
            cashYield: $this->cashYield,
            isSevereNegativeCarry: $this->isSevereNegativeCarry,
            interestCoverage: $this->interestCoverage,
            wantsToPaydownDebt: $this->wantsToPaydownDebt,
            canIssueDebt: $this->canIssueDebt,
            debtTolerance: $this->debtTolerance,
            wacc: $this->wacc,
            costOfEquity: $this->costOfEquity,
            leveredBeta: $this->leveredBeta,
            rawMetrics: $this->rawMetrics ?? self::defaultMetrics(),
            isLiquidityCrisis: $this->isLiquidityCrisis,
            isLiquidityWarning: $this->isLiquidityWarning,
            isUnderLeveraged: $this->isUnderLeveraged,
            hasLeverageHeadroom: $this->hasLeverageHeadroom,
            netDebtToEbitda: $this->netDebtToEbitda,
            ebitdaCovenantLimit: $this->ebitdaCovenantLimit,
            debtToEquity: $this->debtToEquity,
            leverageTarget: $this->leverageTarget,
            hasMarketAccess: $this->hasMarketAccess,
        );
    }
}
