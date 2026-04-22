<?php

namespace App\Service;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service responsible for executing Mergers and Acquisitions.
 * Injects fresh Market Cap into the simulation by having public titans acquire private, off-board companies.
 */
class MergerAndAcquisitionEngine
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEvent $marketEvent,
        private DebtEngine $debtEngine
    ) {}

    /**
     * Evaluates if a stock is in a position to acquire a private company.
     * @return array|null Returns an array with the M&A event details, or null if no deal occurred.
     */
    public function evaluatePrivateAcquisition(Stock $acquirer, array $macroState, float $dt): ?array
    {
        $treasury = (float) $acquirer->getCorporateTreasury();
        $price = (float) $acquirer->getPrice();
        $shares = (float) $acquirer->getSharesOutstanding();
        $debtRatio = (float) $acquirer->getDebtToEquityRatio();
        
        $equity = (float) $acquirer->getTotalEquity();
        $currentDebt = (float) $acquirer->getTotalDebt();
        $policyRate = $macroState['policy_rate'] ?? 0.04;

        // THE NEGATIVE CARRY BLOCK (Syncing with the CFO)
       // THE NEGATIVE CARRY BLOCK (Calling the Centralized Brain)
        $health = $this->debtEngine->analyzeDebtHealth($acquirer, $macroState);
        
        // If the company is struggling with debt or bleeding money, the CFO forbids M&A!
        if ($health['wants_to_paydown_debt']) {
            return null; // Abort deal. Focus on paying down debt instead.
        }

        // PERSONAL BORROWING COST
        $costOfNewBorrowing = $policyRate + (float) $acquirer->getCreditSpread();

        // A company can borrow up to a hard maximum of 2.50x Debt-to-Equity. 
        $maxAllowableDebt = $equity * 2.50;
        $borrowingCapacity = max(0.0, $maxAllowableDebt - $currentDebt);
        
        $totalBuyingPower = $treasury + $borrowingCapacity;

        // Is the company a Mega-Hoarder? (Cash > 25% or 50% of Equity)
        $isHoarder = $treasury > ($equity * 0.25);
        $isMegaHoarder = $treasury > ($equity * 0.50);
        
        $config = match (true) {
            $isMegaHoarder => [
                'prob' => 6.00, 'spend' => 0.60, 'syn_min' => 0.90, 'syn_max' => 1.15, 'type' => 'CONGLOMERATE EXPANSION', 'use_leverage' => false
            ],
            $isHoarder => [
                'prob' => 2.00, 'spend' => 0.40, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => 'CONGLOMERATE EXPANSION', 'use_leverage' => false
            ],
            $health['can_issue_debt'] && $debtRatio < 0.30 && $totalBuyingPower > 5_000_000_000.0 && $costOfNewBorrowing < 0.07 => [
                'prob' => 0.50, 'spend' => 0.40, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => 'LEVERAGED BUYOUT', 'use_leverage' => true
            ],
            // Secondary LBO tier: Allow up to 8% personal borrowing cost for moderate debt companies
            $health['can_issue_debt'] && $debtRatio < 1.30 && $totalBuyingPower > 5_000_000_000.0 && $costOfNewBorrowing < 0.08 => [
                'prob' => 0.10, 'spend' => 0.30, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => 'LEVERAGED BUYOUT', 'use_leverage' => true
            ],

            default => null,
        };

        $dealExecuted = false;

        // Try the primary specialized strategy first
        if ($config && (mt_rand() / mt_getrandmax()) < ($config['prob'] * $dt)) {
            $dealExecuted = true;
        }
        
        // If no primary deal happened, test the standard cash fallback
        if (!$dealExecuted && $treasury > 15_000_000_000.0) {
            $config = [
                'prob' => 0.30, 'spend' => 0.20, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => 'STRATEGIC ACQUISITION', 'use_leverage' => false
            ];
            if ((mt_rand() / mt_getrandmax()) < ($config['prob'] * $dt)) {
                $dealExecuted = true;
            }
        }

        if (!$dealExecuted || !$config) {
            return null;
        }


        // EXECUTE THE M&A DEAL

        $minOperatingCash = $equity * 0.03;
        $usableTreasury = max(0.0, $treasury - $minOperatingCash);

        // Determine the Purchase Price based on their strategy (Cash vs Leverage)
        $availableCapital = $config['use_leverage'] ? ($usableTreasury + $borrowingCapacity) : $usableTreasury;
        $purchasePrice = $availableCapital * (mt_rand(50, 100) / 100.0) * $config['spend'];
        
        // Private companies are rarely worth more than $150B - $250B. Cap the size of the target.
        // This prevents mega-corps from swallowing the entire global economy in a single deal.
        $maxPrivateCompanyValue = mt_rand(100_000_000_000, 500_000_000_000);
        $purchasePrice = min($purchasePrice, (float) $maxPrivateCompanyValue);
        
        if ($purchasePrice < 1_000_000_000.0) return null; 

        $target = $this->generateProceduralTarget();

        // FUND THE DEAL (Drain Cash and/or Issue Debt)
        if ($purchasePrice <= $usableTreasury) {
            // Funded entirely with cash on hand
            $acquirer->setCorporateTreasury((string) ($treasury - $purchasePrice));
            $debtIssued = 0.0;
        } else {
            // Leveraged Buyout: Drain the usable treasury, borrow the rest!
            $debtIssued = $purchasePrice - $usableTreasury;
            $acquirer->setCorporateTreasury((string) ($treasury - $usableTreasury)); // Leaves min operating cash
            
            // Calculate the Weighted Average of the new debt vs old debt
            $oldHistoricalRate = (float) $acquirer->getHistoricalFixedRate();
            $newTotalDebt = $currentDebt + $debtIssued;
            
            // Figure out what the market is charging for this newly issued debt today
            $newDebtRatio = $newTotalDebt / max(1.0, $equity);
            $leveragePenalty = $newDebtRatio > 2.0 ? ($newDebtRatio - 2.0) * 0.05 : 0.0; // The Junk Bond Penalty
            
            // The cost of the new debt is the Central Bank Rate + Company's Credit Spread + Any Junk Penalty
            $costOfNewDebt = $policyRate + (float) $acquirer->getCreditSpread() + $leveragePenalty;
            
            // Blend them together! ((Old Debt * Old Rate) + (New Debt * New Rate)) / Total Debt
            if ($newTotalDebt > 0) {
                $weightedRate = (($currentDebt * $oldHistoricalRate) + ($debtIssued * $costOfNewDebt)) / $newTotalDebt;
                $acquirer->setHistoricalFixedRate((string) $weightedRate);
            }
            
            $acquirer->setTotalDebt((string) $newTotalDebt);
        }

        //THE RANDOMIZED SYNERGY ROLL
        $minInt = (int) ($config['syn_min'] * 100);
        $maxInt = (int) ($config['syn_max'] * 100);
        $synergyMultiplier = mt_rand(min($minInt, $maxInt), max($minInt, $maxInt)) / 100.0;

        //GOODWILL & CLEAN SURPLUS ACCOUNTING
        $synergyValueCreation = $purchasePrice * ($synergyMultiplier - 1.0);
        $newEquity = $equity + $synergyValueCreation;
        $acquirer->setTotalEquity((string) max(10.0, $newEquity));

        //BOOST OR DESTROY RETURN ON INVESTED CAPITAL (ROIC)
        $baselineRoic = (float) $acquirer->getBaselineRoic();
        $currentRoic = (float) $acquirer->getCurrentRoic() ?: $baselineRoic;
        $oldInvestedCapital = max($equity * 0.50, ($equity + $currentDebt - $treasury));
        
        // Private companies generally have average market returns (6% to 12%)
        $targetRoic = mt_rand(60, 120) / 1000.0;
        $effectiveTargetRoic = $targetRoic * $synergyMultiplier;
        
        // Calculate the heavily diluted blended ROIC using the BASELINE to prevent the Recursive Death Spiral
        $blendedRoic = (($oldInvestedCapital * $baselineRoic) + ($purchasePrice * $effectiveTargetRoic)) / max(1.0, $oldInvestedCapital + $purchasePrice);
        
        // Shift the current ROIC by the exact same delta so we don't erase the current macro cycle
        $roicShift = $blendedRoic - $baselineRoic;
        $newCurrentRoic = $currentRoic + $roicShift;

        $acquirer->setCurrentRoic((string) max(-0.10, $newCurrentRoic));
        if (method_exists($acquirer, 'setBaselineRoic')) {
            $acquirer->setBaselineRoic((string) max(-0.10, $blendedRoic));
        }

        //GENERATE THE MARKET EVENT & PRICE SHOCK
        $purchasePriceB = number_format($purchasePrice / 1_000_000_000, 1);
        $desc = "{$acquirer->getName()} executed a \${$purchasePriceB}B {$config['type']} of {$target['name']}.";

        if ($synergyMultiplier < 1.0) {
            $shockValue = -1.0 * (mt_rand(200, 600) / 100.0); 
        } else {
            $marketCap = $price * $shares;
            $calculatedShock = ($synergyValueCreation / max($marketCap, 1)) * 100;
            $shockValue = min(15.0, max(2.0, $calculatedShock));
        }

        $event = $this->marketEvent->publish($acquirer, $config['type'], $desc, $shockValue);

        return [
            'event' => $event,
            'shock' => $shockValue / 100.0,
            'spent' => $purchasePrice
        ];
    }

    /**
     * Evaluates if a stock should divest (sell off) a business unit.
     * Triggers either to raise cash at a premium (High P/E) or to shed bloat to survive (Negative ROIC).
     */
    public function evaluateCorporateDivestiture(Stock $seller, array $macroState, float $dt): ?array
    {
        $eps = (float) $seller->getEarningsPerShare();
        $price = (float) $seller->getPrice();
        $currentPE = $eps > 0 ? $price / $eps : 0.0;
        $netIncome = (float) $seller->getTotalNetIncome();
        $currentRoic = (float) $seller->getCurrentRoic();

        // Is the company suffocating under its own weight?
        $isDistressed = $currentRoic < 0.02;
        $isDying = $currentRoic < -0.02;


        // Only sell if highly valued OR deeply distressed
        if (!$isDistressed && ($currentPE < 30.0 || $netIncome < 5_000_000_000.0)) {
            return null;
        }

        // Distressed companies are highly motivated to shed weight immediately Dying companies beg
        if ($isDying) {
            // Desperate fire sale: Sheds up to 50% of the company for a terrible 4x multiple
            $divestedFraction = mt_rand(30, 50) / 100.0;
            $saleMultiple = mt_rand(3, 5);
            $annualProbability = 8;
        } elseif ($isDistressed) {
            // Standard distress: Sheds 15-30% for an 8x multiple
            $divestedFraction = mt_rand(15, 30) / 100.0;
            $saleMultiple = mt_rand(6, 10);
            $annualProbability = 0.60;
        } else {
            $annualProbability = 0.20;
            $divestedFraction = mt_rand(5, 15) / 100.0;
            $saleMultiple = $currentPE;
        }


        if ((mt_rand() / mt_getrandmax()) >= ($annualProbability * $dt)) {
            return null;
        }

        // EXECUTE THE DIVESTITURE

        $lostNetIncome = $netIncome * $divestedFraction;
        $currentEquity = (float) $seller->getTotalEquity();
        $lostEquity = $currentEquity * $divestedFraction;

        // If the company is losing money, buyers value the physical assets (Equity) 
        // at a steep discount, rather than applying a multiple to negative earnings.
        if ($netIncome > 0) {
            $salePrice = $lostNetIncome * $saleMultiple;
        } else {
            // Sell the toxic assets for 40 to 80 cents on the dollar
            $salePrice = $lostEquity * (mt_rand(40, 80) / 100.0);
        }

        // INJECT THE CASH
        $currentTreasury = (float) $seller->getCorporateTreasury();
        $seller->setCorporateTreasury((string) ($currentTreasury + $salePrice));

        // SHED THE EARNINGS & THE PHYSICAL EQUITY BLOAT
        $seller->setTotalNetIncome((string) ($netIncome - $lostNetIncome));

        $newEquity = $currentEquity - $lostEquity + $salePrice;
        $seller->setTotalEquity((string) max(10.0, $newEquity));

        // BOOST THE RETURN ON INVESTED CAPITAL (ROIC)
        // Shedding assets makes the core business leaner. 
        $currentRoic = $currentRoic ?: (float) $seller->getBaselineRoic();
        $roicBump = $divestedFraction * 0.20;
        $seller->setCurrentRoic((string) ($currentRoic + $roicBump));
        if (method_exists($seller, 'setBaselineRoic')) {
            $seller->setBaselineRoic((string) ($seller->getBaselineRoic() + ($roicBump * 0.5)));
        }

        // GENERATE THE MARKET EVENT
        $salePriceB = number_format($salePrice / 1_000_000_000, 1);
        $desc = "{$seller->getName()} executed a \${$salePriceB}B DIVESTITURE.";

        if ($isDistressed) {
            $shockValue = mt_rand(300, 600) / 100.0; // Market cheers the massive restructuring (3% to 6% gap up)
        } else {
            $shockValue = mt_rand(100, 300) / 100.0; // Standard 1% to 3% positive price gap
        }

        $event = $this->marketEvent->publish($seller, 'DIVESTITURE', $desc, $shockValue);

        return ['event' => $event, 'shock' => $shockValue / 100.0];
    }

    /**
     * Procedurally generates a realistic sounding private company.
     */
    private function generateProceduralTarget(): array
    {
        $prefixes = ['Apex', 'Horizon', 'Vertex', 'Quantum', 'Cipher', 'Aegis', 'Omni', 'Vanguard', 'Pinnacle', 'Meridian'];
        $suffixes = ['Dynamics', 'Holdings', 'Labs', 'Logistics', 'Networks', 'Heavy Industries', 'Capital', 'Technologies', 'Resources', 'Ventures'];

        $name = $prefixes[array_rand($prefixes)] . ' ' . $suffixes[array_rand($suffixes)];

        return ['name' => $name];
    }
}
