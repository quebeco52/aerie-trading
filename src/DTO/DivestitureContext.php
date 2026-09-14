<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Stock;
use App\Service\Model\BusinessModelInterface;

/**
 * Context object used to pass state through the corporate divestiture pipeline.
 */
class DivestitureContext
{
    public function __construct(
        public readonly Stock $seller,
        public readonly MacroStateDTO $macroState,
        public readonly float $dt
    ) {}

    // State Variables
    public float $eps = 0.0;
    public float $price = 0.0;
    public float $shares = 0.0;
    public float $netIncome = 0.0;
    
    public ?DebtHealthDTO $health = null;
    public float $wacc = 0.0;
    public string $industry = 'General';
    public string $businessModel = 'none';
    public ?BusinessModelInterface $strategy = null;
    
    public float $currentReturn = 0.0;
    public float $hurdleRate = 0.0;
    public float $evaSpread = 0.0;
    
    public bool $isDistressed = false;
    public bool $isDying = false;
    
    public float $treasury = 0.0;
    public float $operatingBase = 0.0;
    public bool $hasCashBuffer = false;
    
    public float $currentEquity = 0.0;
    public float $investedCapital = 0.0;
    public float $evaluationCapital = 0.0;
    
    public float $structuralNetIncome = 0.0;
    public float $normalizedNetIncome = 0.0;
    public float $normalizedEps = 0.0;
    public float $currentPE = 0.0;
    
    public float $targetCash = 0.0;
    public array $hoardStatus = [];
    
    public float $nominalGdpIndex = 0.0;
    public float $samRatio = 0.0;
    /** Invested capital over the firm's serviceable addressable market (CorporateMetrics::calculateScaleRatio). */
    public float $scaleRatio = 0.0;
    
    // Deal Config
    public bool $dealExecuted = false;
    public float $divestedFraction = 0.0;
    public float $saleMultiple = 0.0;
    public float $annualProbability = 0.0;
    public float $sectorPE = 0.0;
    
    // Accounting
    public float $lostNetIncome = 0.0;
    public float $currentDebt = 0.0;
    public float $lostDebt = 0.0;
    public float $lostEquity = 0.0;
    
    public float $salePrice = 0.0;
    public float $baseDistressValue = 0.0;
    public float $newTreasury = 0.0;
    public float $gainOnSale = 0.0;
    
    // Results
    public float $eventShock = 0.0;
    public array $eventData = [];
}
