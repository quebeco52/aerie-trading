<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Stock;
use App\Service\Model\BusinessModelInterface;

/**
 * Context object used to pass capital allocation state through the CapitalAllocationEngine pipeline.
 */
class CapitalAllocationContext
{
    public function __construct(
        public readonly Stock $stock,
        public readonly MacroStateDTO $macroState,
        public readonly float $actualAnnualEps,
        public readonly float $quarterlyFcfPerShare,
        public readonly float $currentPrice,
        public readonly float $sharesOutstanding,
        public readonly float $actualTotalNetIncome = 0.0,
        /**
         * Quarterly stock-based compensation (ASC 718). The expense is already inside net income; its
         * credit side is additional paid-in capital, so it must be added back when equity rolls forward.
         */
        public readonly float $stockCompensation = 0.0
    ) {}

    // Core Business Traits
    public string $industry = 'General';
    public string $businessModel = 'none';
    public ?BusinessModelInterface $strategy = null;
    public bool $isFinancial = false;

    // Computed Operational Metrics
    public float $quarterlyEps = 0.0;
    public float $quarterlyNetIncome = 0.0;
    public float $currentTreasury = 0.0;
    public float $operatingBase = 0.0;
    public float $investedCapital = 0.0;
    
    // Profit & Health
    public ?DebtHealthDTO $health = null;
    public float $quarterlyNopat = 0.0;
    public float $retainedEarningsThisQuarter = 0.0;
    
    // Cash Flow & Treasury Tracking
    public float $newTreasury = 0.0;
    public float $targetOperatingCash = 0.0;
    public float $excessCash = 0.0;

    // Liability & Debt Tracking
    public float $wholesaleDebt = 0.0;
    public float $customerDeposits = 0.0;
    public float $debtIssued = 0.0;
    /** Principal that came due this quarter and had to be repaid in cash because it could not be refinanced. */
    public float $principalRepaid = 0.0;
    /** True when the primary market refused to roll this quarter's maturity. */
    public bool $refinancingRefused = false;
    /** Maturing principal the firm could not repay out of cash, before emergency financing is attempted. */
    public float $unfundedMaturity = 0.0;
    public bool $recapActionTaken = false;
    public bool $failedEmergencyBorrow = false;

    // Valuation
    public float $currentPE = 0.0;
    
    // Dividends
    public float $newDividend = 0.0;
    public float $totalPaid = 0.0;

    // Strategy & Buybacks
    public float $organicCapex = 0.0;
    public ?float $bankApy = null;
    public bool $debtActionTaken = false;
    
    public float $totalCashSpent = 0.0; // buybacks
    public float $newShares = 0.0;
    
    // Market Events
    public array $events = [];
}
