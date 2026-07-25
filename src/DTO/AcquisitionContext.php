<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Stock;
use App\Service\Model\BusinessModelInterface;

/**
 * Context object used to pass state through the private acquisition pipeline.
 */
class AcquisitionContext
{
    public function __construct(
        public readonly Stock $acquirer,
        public readonly MacroStateDTO $macroState,
        public readonly float $dt
    ) {}

    // State Variables
    public float $treasury = 0.0;
    public float $price = 0.0;
    public float $shares = 0.0;
    public float $debtRatio = 0.0;
    
    public float $operatingBase = 0.0;
    public float $equity = 0.0;
    public float $currentDebt = 0.0;
    public float $policyRate = 0.0;
    public float $yield5y = 0.0;
    
    public ?DebtHealthDTO $health = null;
    public string $industry = 'General';
    public string $businessModel = 'none';
    public ?BusinessModelInterface $strategy = null;
    
    // Buying Power & Cash
    public float $maxAllowableDebt = 0.0;
    public float $borrowingCapacity = 0.0;
    public float $totalBuyingPower = 0.0;
    public float $targetCash = 0.0;
    public float $excessCash = 0.0;
    public bool $isHoarder = false;
    public bool $isMegaHoarder = false;
    public float $usableTreasury = 0.0;
    
    // Strategy evaluation
    public float $normalizedDebtUtilization = 0.0;
    public float $eps = 0.0;
    public float $currentPE = 0.0;
    public float $trueReturn = 0.0;
    public float $hurdleRate = 0.0;
    public float $economicSpread = 0.0;
    public float $fairValuePE = 0.0;
    public float $bookValuePerShare = 0.0;
    public float $priceToBook = 0.0;
    public bool $isOvervalued = false;
    public float $aggression = 1.0;
    public bool $isEmpireBuilder = false;
    public float $costOfNewBorrowing = 0.0;
    
    // Deal Config
    public ?array $config = null;
    public bool $dealExecuted = false;
    public float $availableCapital = 0.0;
    public float $spendFraction = 0.0;
    public float $purchasePrice = 0.0;
    public float $maxPrivateCompanyValue = 0.0;
    public array $target = [];
    
    // Funding results
    public float $costOfNewDebt = 0.0;
    public float $debtIssued = 0.0;
    public float $sharesIssued = 0.0;
    
    // Synergy & Accounting
    public float $synergyMultiplier = 1.0;
    public float $synergyValueCreation = 0.0;
    public float $newEquity = 0.0;
    
    public float $eventShock = 0.0;
    public array $eventData = [];
}
