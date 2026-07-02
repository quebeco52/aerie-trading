<?php

namespace App\Service\Corporate;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\Event\MarketEventPublisher;
use App\Service\Math\MathUtility;
use App\Service\Math\CorporateMetrics;

/**
 * Service responsible for executing Mergers and Acquisitions.
 * Injects fresh Market Cap into the simulation by having public titans acquire private, off-board companies.
 */
class MergerAndAcquisitionEngine
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MarketEventPublisher $marketEvent,
        private DebtEngine $debtEngine,
        private MathUtility $mathUtility,
        private CorporateMetrics $corporateMetrics
    ) {}

    /**
     * Evaluates if a stock is in a position to acquire a private company.
     * 
     * @param Stock $acquirer   The potential acquiring stock.
     * @param array $macroState The macroeconomic state.
     * @param float $dt         The time step (in years).
     * @return array{event: array<string, mixed>, shock: float, spent: float}|null Returns an array with the M&A event details, or null if no deal occurred.
     */
    public function evaluatePrivateAcquisition(Stock $acquirer, array $macroState, float $dt): ?array
    {
        $treasury = (float) $acquirer->getCorporateTreasury();
        $price = (float) $acquirer->getPrice();
        $shares = (float) $acquirer->getSharesOutstanding();
        $debtRatio = (float) $acquirer->getDebtToEquityRatio();
        
        $operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $acquirer->getTotalRevenue(), (float) $acquirer->getTotalEquity());
        $equity = (float) $acquirer->getTotalEquity();
        $currentDebt = (float) $acquirer->getTotalDebt();
        $policyRate = $macroState['policy_rate'] ?? 0.04;
        $yield5y = $macroState['yield_5y_ema'] ?? ($macroState['yield_5y'] ?? $policyRate + 0.005);

       // THE NEGATIVE CARRY BLOCK (Calling the Centralized Brain)
        $health = $this->debtEngine->analyzeDebtHealth($acquirer, $macroState);
        
        // If the company is struggling with debt or bleeding money, the CFO forbids M&A!
        if ($health['wants_to_paydown_debt']) {
            return null; // Abort deal. Focus on paying down debt instead.
        }

        // PERSONAL BORROWING COST
        $costOfNewBorrowing = $health['raw_metrics']['current_market_rate'] ?? ($yield5y + (float) $acquirer->getCreditSpread());

        // Leveraged industries have much higher natural limits.
        $industry = $acquirer->getIndustry() ?: 'General';
        $equityLimit = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['equity_limit'] ?? 1.0;
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        
        // Allow up to their maximum structural equity limit + a 20% M&A over-leverage buffer
        $maxAllowableDebt = $equity * $equityLimit;
        $borrowingCapacity = max(0.0, $maxAllowableDebt - $currentDebt);
        
        $totalBuyingPower = $treasury + $borrowingCapacity;

        // Calculate actual excess cash above target operating requirements
        $targetCash = $strategy->calculateTargetOperatingCash($operatingBase, (float) $acquirer->getCustomerDeposits(), (float) $acquirer->getWholesaleDebt());
        $hoardStatus = $strategy->evaluateHoardingStatus($treasury, $targetCash, $operatingBase, $currentDebt);
        $excessCash = $hoardStatus['excess_cash'];
        $isHoarder = $hoardStatus['is_hoarder'];
        $isMegaHoarder = $hoardStatus['is_mega_hoarder'];
        
        // Normalize the debt ratio against the sector's limit (1.0 = at max leverage, 0.5 = half levered)
        $normalizedDebtUtilization = $debtRatio / max(0.1, $equityLimit);
        
        $eps = (float) $acquirer->getEarningsPerShare();
        $currentPE = $eps > 0 ? ($price / $eps) : 9999.0;
        
        $trueReturn = $isFinancial ? (float) $acquirer->getCurrentRoe() : (float) $acquirer->getCurrentRoic();
        $hurdleRate = $isFinancial ? ($health['cost_of_equity'] ?? 0.10) : ($health['wacc'] ?? 0.08);
        $economicSpread = $trueReturn - $hurdleRate;
        
        $fairValuePE = $this->mathUtility->calculateIntrinsicFairValuePE($hurdleRate, $trueReturn, 0.02);
        $acquirerShares = (float) $acquirer->getSharesOutstanding();
        $bookValuePerShare = max(0.01, $acquirer->getTotalEquity() / max(1.0, $acquirerShares));
        $priceToBook = $price / $bookValuePerShare;
        $isOvervalued = $economicSpread > 0.0 && $currentPE > ($fairValuePE * 1.5) && $currentPE > 25.0 && $priceToBook > 2.0;
        
        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($acquirer->getCeoArchetype());
        $aggression = $archetypeStrategy->modifyAcquisitionAggression(1.0);
        $isEmpireBuilder = $aggression >= 2.0;
        
        $config = match (true) {
            $isOvervalued => [
                'prob' => 0.15, 'spend' => 0.50, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => 'STOCK-FOR-STOCK MERGER', 'use_leverage' => false, 'use_stock' => true
            ],
            // Empire Builders aggressively execute M&A with massive leverage, ignoring standard utilization limits and personal borrowing costs
            $isEmpireBuilder && $health['can_issue_debt'] && $totalBuyingPower > 2_000_000_000.0 => [
                'prob' => 0.75, 'spend' => 0.80, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => $isFinancial ? 'STRATEGIC ACQUISITION' : 'LEVERAGED BUYOUT', 'use_leverage' => true, 'use_stock' => false
            ],
            $isMegaHoarder => [
                'prob' => 0.50, 'spend' => 0.60, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => $isFinancial ? 'STRATEGIC ACQUISITION' : 'CONGLOMERATE EXPANSION', 'use_leverage' => false, 'use_stock' => false
            ],
            $isHoarder => [
                'prob' => 0.25, 'spend' => 0.40, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => $isFinancial ? 'STRATEGIC ACQUISITION' : 'CONGLOMERATE EXPANSION', 'use_leverage' => false, 'use_stock' => false
            ],
            $health['can_issue_debt'] && $normalizedDebtUtilization < 0.30 && $totalBuyingPower > 5_000_000_000.0 && $costOfNewBorrowing < 0.07 => [
                'prob' => 0.10, 'spend' => 0.40, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => $isFinancial ? 'STRATEGIC ACQUISITION' : 'LEVERAGED BUYOUT', 'use_leverage' => true, 'use_stock' => false
            ],
            // Secondary LBO tier: Allow up to 8% personal borrowing cost for moderate debt companies
            $health['can_issue_debt'] && $normalizedDebtUtilization < 0.80 && $totalBuyingPower > 5_000_000_000.0 && $costOfNewBorrowing < 0.08 => [
                'prob' => 0.05, 'spend' => 0.30, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => $isFinancial ? 'STRATEGIC ACQUISITION' : 'LEVERAGED BUYOUT', 'use_leverage' => true, 'use_stock' => false
            ],

            default => null,
        };

        $dealExecuted = false;

        // Try the primary specialized strategy first
        if ($config && $this->mathUtility->checkProbability($config['prob'] * $dt)) {
            $dealExecuted = true;
        }
        
        // If no primary deal happened, test the standard cash fallback
        if (!$dealExecuted && $excessCash > 15_000_000_000.0) {
            $config = [
                'prob' => 0.05, 'spend' => 0.20, 'syn_min' => 0.90, 'syn_max' => 1.10, 'type' => 'STRATEGIC ACQUISITION', 'use_leverage' => false, 'use_stock' => false
            ];
            if ($this->mathUtility->checkProbability($config['prob'] * $dt)) {
                $dealExecuted = true;
            }
        }

        if (!$dealExecuted || !$config) {
            return null;
        }


        // EXECUTE THE M&A DEAL

        $minOperatingCash = $strategy->calculateMinOperatingCash($operatingBase, (float) $acquirer->getCustomerDeposits(), (float) $acquirer->getWholesaleDebt());
        $usableTreasury = max(0.0, $treasury - $minOperatingCash);

        // Determine the Purchase Price based on their strategy (Cash vs Leverage vs Stock)
        $availableCapital = $config['use_stock'] ? ($price * $shares * 0.10) : ($config['use_leverage'] ? ($usableTreasury + $borrowingCapacity) : $usableTreasury);
        $purchasePrice = $availableCapital * (mt_rand(50, 100) / 100.0) * $config['spend'];
        

        $maxPrivateCompanyValue = max((float) mt_rand(100, 500) * 1_000_000_000.0, $availableCapital * 0.50);
        $purchasePrice = min($purchasePrice, $maxPrivateCompanyValue);
        
        // Financials must safely cap their M&A spend to a fraction of their Tier 1 Capital (Equity)
        // EXCEPT Mega Hoarders (who are desperate to flush cash) and Empire Builders (who don't care about safety limits)
        if ($isFinancial && !$isMegaHoarder && !$isEmpireBuilder) {
            $purchasePrice = min($purchasePrice, $equity * 0.15);
        }
        
        if ($purchasePrice < 1_000_000_000.0) return null; 

        $target = $this->generateProceduralTarget();

        // FUND THE DEAL (Drain Cash, Issue Debt, or Issue Stock)
        $costOfNewDebt = 0.0;
        $debtIssued = 0.0;
        
        if ($config['use_stock']) {
            // Funded entirely with new shares
            $offeringPrice = $price * 0.90; // Assume 10% underpricing for massive share issuance
            $sharesIssued = $purchasePrice / max(0.01, $offeringPrice);
            $acquirer->setSharesOutstanding((string) ($shares + $sharesIssued));
        } elseif ($purchasePrice <= $usableTreasury) {
            // Funded entirely with cash on hand
            $acquirer->setCorporateTreasury((string) ($treasury - $purchasePrice));
        } else {
            // Leveraged Buyout: Drain the usable treasury, borrow the rest!
            $debtIssued = $purchasePrice - $usableTreasury;
            $acquirer->setCorporateTreasury((string) ($treasury - $usableTreasury)); // Leaves min operating cash
            
            // Calculate the Weighted Average of the new debt vs old debt
            $oldHistoricalRate = (float) $acquirer->getHistoricalFixedRate();
            $newTotalDebt = $currentDebt + $debtIssued;
            
            // MERTON LBO CREDIT SPREAD CALCULATION
            // An LBO expands the firm's debt. We must calculate the credit spread of the post-merger entity.
            $equityVolatility = (float) ($acquirer->getCurrentVolatility() ?? $acquirer->getVolatility());
            $equityVolatility = max(0.05, $equityVolatility);
            
            $marketCap = max(1.0, (float) $acquirer->getPrice() * max(1.0, (float) $acquirer->getSharesOutstanding()));
            $currentNetDebt = max(0.0, $currentDebt - $treasury);
            $newNetDebt = $currentNetDebt + $debtIssued;
            
            // The new asset value absorbs the purchase price
            $newAssetValue = $marketCap + $currentNetDebt + $purchasePrice;
            $newAssetVolatility = $equityVolatility * ($marketCap / $newAssetValue);
            $newAssetVolatility = max(0.02, $newAssetVolatility);
            
            $lossGivenDefault = $isFinancial ? 0.30 : 0.40;
            
            $distanceToDefault = $this->mathUtility->calculateDistanceToDefault(
                $newAssetValue,
                max(0.01, $newNetDebt),
                $newAssetVolatility,
                $policyRate,
                5.0
            );
            
            $projectedSpread = $this->mathUtility->calculateMertonCreditSpread($distanceToDefault, $lossGivenDefault, 5.0);
            
            // Re-apply the baseline spread + the new projected Merton spread
            $tmpArchetype = \App\Data\CeoArchetypes::getStrategy($acquirer->getCeoArchetype());
            $baselineCreditSpread = $tmpArchetype->modifyCreditSpread((float) $acquirer->getCreditSpread());
            $dynamicSpread = $baselineCreditSpread + $projectedSpread;
            
            $costOfNewDebt = $yield5y + $dynamicSpread;
            
            // Issue the debt through the centralized DebtEngine
            $this->debtEngine->issueDebt($acquirer, $debtIssued, $costOfNewDebt);
        }

        $archetypeStrategy = \App\Data\CeoArchetypes::getStrategy($acquirer->getCeoArchetype());
        $synergyRange = $archetypeStrategy->modifyMAndASynergyRange($config['syn_min'], $config['syn_max']);
        
        //THE RANDOMIZED SYNERGY ROLL
        $minInt = (int) ($synergyRange['min'] * 100);
        $maxInt = (int) ($synergyRange['max'] * 100);
        $synergyMultiplier = mt_rand(min($minInt, $maxInt), max($minInt, $maxInt)) / 100.0;

        //GOODWILL & CLEAN SURPLUS ACCOUNTING
        $synergyValueCreation = $purchasePrice * ($synergyMultiplier - 1.0);
        $newEquity = $equity + $synergyValueCreation;
        $acquirer->setTotalEquity((string) max(10.0, $newEquity));

        // Clean Surplus Accounting: The synergy (premium/discount) must flow through Retained Earnings
        // Represents a "Gain on Bargain Purchase" or an "Impairment/Goodwill Write-off"
        $currentRetained = (float) $acquirer->getRetainedEarnings();
        $acquirer->setRetainedEarnings((string) ($currentRetained + $synergyValueCreation));

        // BLEND THE STRUCTURAL DNA (Baseline ROIC and Operating Margin)
        // This permanently alters the physical efficiency of the combined entity
        $oldBaselineRoic = (float) $acquirer->getBaselineRoic();
        $oldOperatingMargin = (float) $acquirer->getOperatingMargin();
        $oldInvestedCapital = $acquirer->getInvestedCapital();
        
        $oldCapitalBase = $isFinancial ? $equity : $oldInvestedCapital;
        
        // Private companies generally have average market returns (6% to 12%)
        $targetRoic = mt_rand(60, 120) / 1000.0;
        $effectiveTargetRoic = $targetRoic * $synergyMultiplier;
        
        // Assume the target has a slightly worse operating margin, but protect structural floors
        $targetMargin = max(0.01, $oldOperatingMargin * (mt_rand(70, 95) / 100.0));
        
        $totalNewCapital = max(1.0, $oldCapitalBase + $purchasePrice);
        
        // Blend the Baseline ROIC (Only for normal companies, Banks use this as a Target ROE!)
        if (!$isFinancial) {
            $blendedBaselineRoic = (($oldCapitalBase * $oldBaselineRoic) + ($purchasePrice * $effectiveTargetRoic)) / $totalNewCapital;
            $acquirer->setBaselineRoic((string) max(0.01, $blendedBaselineRoic));
        } else {
            $oldBaselineRoe = (float) $acquirer->getBaselineRoe();
            $blendedBaselineRoe = (($oldCapitalBase * $oldBaselineRoe) + ($purchasePrice * $effectiveTargetRoic)) / $totalNewCapital;
            $acquirer->setBaselineRoe((string) max(0.01, $blendedBaselineRoe));
        }
        
        // Blend the Structural Operating Margin and apply M&A Indigestion
        // Merging corporate hierarchies is chaotic. We apply a 10% penalty to the blended margin.
        // The EarningsEngine CIR mean-reversion will naturally heal this over the next 3-4 quarters.
        $blendedMargin = (($oldCapitalBase * $oldOperatingMargin) + ($purchasePrice * $targetMargin)) / $totalNewCapital;
        $acquirer->setOperatingMargin((string) max(0.01, $blendedMargin * 0.90));
        
        // We no longer manually shift CurrentRoic. The EarningsEngine will naturally calculate 
        // the diluted, bottom-up ROIC next quarter using this new blended DNA!

        // Calculate the TRUE Net Income contribution (Target Operating Earnings minus New Interest Expense)
        $acquiredOperatingIncome = $purchasePrice * $effectiveTargetRoic;
        
        // ADD ACQUIRED REVENUE TO THE ACQUIRER
        $acquiredRevenue = $acquiredOperatingIncome / max(0.01, $targetMargin);
        $currentRevenue = (float) $acquirer->getTotalRevenue();
        $acquirer->setTotalRevenue((string) ($currentRevenue + $acquiredRevenue));

        $newInterestExpense = 0.0;
        if ($debtIssued > 0) {
            // Calculate interest drag, factoring in the standard 21% corporate tax shield
            $newInterestExpense = $debtIssued * $costOfNewDebt * (1.0 - $macroState['corporate_tax_rate']);
        }
        
        $trueAcquiredNetIncome = $acquiredOperatingIncome - $newInterestExpense;
        
        // Immediately integrate the acquired company's true net earnings into the parent's baseline EPS
        $currentEps = (float) $acquirer->getEarningsPerShare();
        $acquirer->setEarningsPerShare((string) ($currentEps + ($trueAcquiredNetIncome / max(1.0, $shares))));

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
     *
     * @param Stock $seller     The potential selling stock.
     * @param array $macroState The macroeconomic state.
     * @param float $dt         The time step (in years).
     * @return array{event: array<string, mixed>, shock: float}|null Returns an array with the divestiture event details, or null if no deal occurred.
     */
    public function evaluateCorporateDivestiture(Stock $seller, array $macroState, float $dt): ?array
    {
        $eps = (float) $seller->getEarningsPerShare();
        $price = (float) $seller->getPrice();
        $shares = max(1.0, (float) $seller->getSharesOutstanding());
        $netIncome = (float) $seller->getTotalNetIncome();

        $health = $this->debtEngine->analyzeDebtHealth($seller, $macroState);
        $wacc = $health['wacc'] ?? 0.08;

        // Is the company suffocating under its own weight?

        $industry = $seller->getIndustry() ?: 'General';
        $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$industry]['business_model'] ?? 'none';
        $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
        $currentReturn = $isFinancial ? (float) $seller->getCurrentRoe() : (float) $seller->getCurrentRoic();
        $hurdleRate = $isFinancial ? ($health['cost_of_equity'] ?? 0.10) : $wacc;

        $evaSpread = $currentReturn - $hurdleRate;

        $isDistressed = $evaSpread < -0.02 || $currentReturn < 0.03;
        $isDying = $currentReturn < 0.00 || $evaSpread < -0.05;

        $treasury = (float) $seller->getCorporateTreasury();
        $operatingBase = $this->corporateMetrics->calculateOperatingBase((float) $seller->getTotalRevenue(), (float) $seller->getTotalEquity());
        $hasCashBuffer = $treasury > ($operatingBase * 0.10); // 10% buffer is a massive fortress

        $currentEquity = (float) $seller->getTotalEquity();
        $investedCapital = $seller->getInvestedCapital();
        $evaluationCapital = $isFinancial ? $currentEquity : $investedCapital;
        
        // NORMALIZED EARNINGS FIX
        // Because the new EarningsEngine introduces massive, realistic volatility spikes (like loan loss provisions),
        // we must normalize the net income against its structural capacity to prevent valuing a divestiture at $0 during a temporary bad quarter.
        $structuralNetIncome = $evaluationCapital * $currentReturn;
        $normalizedNetIncome = ($netIncome * 0.50) + ($structuralNetIncome * 0.50);
        
        $normalizedEps = $normalizedNetIncome / $shares;
        $currentPE = $normalizedEps > 0 ? $price / $normalizedEps : 0.0;
        
        // THE HOARDER TRAP:
        // Cash hoarders suffer from low ROE due to cash drag. If we let them divest, they sell 
        // earning assets for MORE cash, accelerating their death spiral!
        $strategy = \App\Data\Sectors::getBusinessModelStrategy($businessModel);
        $targetCash = $strategy->calculateTargetOperatingCash($operatingBase, (float) $seller->getCustomerDeposits(), (float) $seller->getWholesaleDebt());
        $hoardStatus = $strategy->evaluateHoardingStatus($treasury, $targetCash, $operatingBase, (float) $seller->getTotalDebt());
        
        if ($hoardStatus['is_hoarder']) {
            return null; // Hoarders must BUY or DELEVERAGE, never sell!
        }
        
        $nominalGdpIndex = $macroState['nominal_gdp_index'] ?? 1.0;
        $samRatio = (float) $seller->getSamRatio();
        $marketShare = $this->corporateMetrics->calculateMarketShare($evaluationCapital, $nominalGdpIndex, $samRatio);


        // If they have a massive cash fortress, they can easily weather the storm without a fire sale!
        if ($hasCashBuffer && !($marketShare > 1.00)) {
            $isDistressed = false;
            $isDying = false;
        }

        // Only sell if highly valued OR deeply distressed
        if (!$isDistressed && ($currentPE < 30.0 || $normalizedNetIncome < 5_000_000_000.0)) {
            return null;
        }

        // Distressed companies are highly motivated to shed weight immediately Dying companies beg
        if ($isDying) {
            // Desperate fire sale: Sheds up to 50% of the company for a terrible 4x multiple
            $divestedFraction = mt_rand(30, 50) / 100.0;
            $saleMultiple = mt_rand(3, 5);
            $annualProbability = 2.0;
        } elseif ($isDistressed) {
            // Standard distress: Sheds 15-30% for an 8x multiple
            $divestedFraction = mt_rand(15, 30) / 100.0;
            $saleMultiple = mt_rand(6, 10);
            $annualProbability = 0.30;
        } else {
            // High P/E trimming (Taking advantage of an overvalued stock)
            $divestedFraction = mt_rand(5, 10) / 100.0;
            // Blend the company's inflated P/E with the sector average, and cap it at a realistic 18x.
            $sectorPE = \App\Data\Sectors::MACRO_SECTORS[$seller->getSector()] ?? 20.0;
            $blendedMultiple = ($currentPE + $sectorPE) / 2.0;
            $saleMultiple = min(18.0, max(8.0, $blendedMultiple));
            $annualProbability = 0.05;
        }


        if (!$this->mathUtility->checkProbability($annualProbability * $dt)) {
            return null;
        }

        // EXECUTE THE DIVESTITURE

        $lostNetIncome = $normalizedNetIncome * $divestedFraction;
        $investedCapital = $seller->getInvestedCapital();
        
        $currentDebt = (float) $seller->getWholesaleDebt();
        $lostDebt = $currentDebt * $divestedFraction;
        
        if ($isFinancial) {
            $currentDeposits = (float) $seller->getCustomerDeposits();
            $totalLoans = $currentEquity + $currentDebt + $currentDeposits - $treasury;
            $lostLoans = $totalLoans * $divestedFraction;
            $lostEquity = $lostLoans - $lostDebt;
        } else {
            $lostInvestedCapital = $investedCapital * $divestedFraction;
            $lostEquity = $lostInvestedCapital - $lostDebt;
        }

        // If the company is structurally losing money, buyers value the physical assets (Equity) 
        // at a steep discount, rather than applying a multiple to negative earnings.
        if ($normalizedNetIncome > 0) {
            $salePrice = $lostNetIncome * $saleMultiple;
        } else {
            // Sell the toxic assets for 40 to 80 cents on the dollar
            $baseDistressValue = max($currentEquity, $investedCapital * 0.25);
            $salePrice = ($baseDistressValue * $divestedFraction) * (mt_rand(40, 80) / 100.0);
        }

        // INJECT THE CASH FROM THE SALE
        $currentTreasury = (float) $seller->getCorporateTreasury();
        $newTreasury = $currentTreasury + $salePrice;
        $seller->setCorporateTreasury((string) $newTreasury);

        // Restate forward guidance: Reduce EPS proportionally so the Earnings Engine doesn't report a massive miss next quarter
        $currentEps = (float) $seller->getEarningsPerShare();
        $seller->setEarningsPerShare((string) ($currentEps * (1.0 - $divestedFraction)));

        // SHED THE DEBT (Liabilities associated with the sold unit)
        $seller->setWholesaleDebt((string) max(0.0, $currentDebt - $lostDebt));

        if ($isFinancial) {
            $currentDeposits = (float) $seller->getCustomerDeposits();
            $lostDeposits = $currentDeposits * $divestedFraction;
            $seller->setCustomerDeposits((string) max(0.0, $currentDeposits - $lostDeposits));
            
            // In fractional reserve banking, deposits are backed by the loan book, not pure cash.
            // We transfer the proportional share of the existing cash reserves, not the absolute deposit value.
            // Note: We use the pre-sale $currentTreasury to calculate the divested portion.
            $lostCashReserves = $currentTreasury * $divestedFraction;
            $seller->setCorporateTreasury((string) max(0.0, ((float) $seller->getCorporateTreasury()) - $lostCashReserves));
        }
        
        // REDUCE REVENUE
        $currentRevenue = (float) $seller->getTotalRevenue();
        $seller->setTotalRevenue((string) max(1.0, $currentRevenue * (1.0 - $divestedFraction)));

        $newEquity = $currentEquity - $lostEquity + $salePrice;
        $seller->setTotalEquity((string) max(10.0, $newEquity));
        
        // Clean Surplus Accounting: Record the Gain/Loss on Sale into Retained Earnings
        $gainOnSale = $salePrice - $lostEquity;
        $currentRetained = (float) $seller->getRetainedEarnings();
        $seller->setRetainedEarnings((string) ($currentRetained + $gainOnSale));

        // BOOST STRUCTURAL EFFICIENCY
        // Shedding bloat permanently improves the company's core DNA (Baseline ROIC and Margin).
        // We ONLY do this if the company was distressed and selling toxic assets.
        // Selling a highly profitable unit at a premium does NOT make the rest of the company fundamentally better.
        if ($isDistressed || $isDying) {
            if ($isFinancial) {
                $baselineRoe = (float) $seller->getBaselineRoe();
                $roeBump = $baselineRoe * ($divestedFraction * 0.50);
                $seller->setBaselineRoe((string) ($baselineRoe + $roeBump));
            } else {
                $baselineRoic = (float) $seller->getBaselineRoic();
                $roicBump = $baselineRoic * ($divestedFraction * 0.50); // Up to a 25% relative improvement
                $seller->setBaselineRoic((string) ($baselineRoic + $roicBump));
            }
            $operatingMargin = (float) $seller->getOperatingMargin();
            
            $marginBump = $operatingMargin * ($divestedFraction * 0.30); 
            
            $seller->setOperatingMargin((string) ($operatingMargin + $marginBump));
        }
        
        // EarningsEngine will automatically calculate a higher CurrentRoic next quarter

        // GENERATE THE MARKET EVENT
        $salePriceB = number_format($salePrice / 1_000_000_000, 1);
        $gainOnSaleB = number_format($gainOnSale / 1_000_000_000, 1);
        $target = $this->generateProceduralTarget();
        $desc = "{$seller->getName()} sold its {$target['name']} division for \${$salePriceB}B in cash, generating a \${$gainOnSaleB}B gain on sale.";

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
     *
     * @return array{name: string} An array containing the generated company name.
     */
    private function generateProceduralTarget(): array
    {
        $prefixes = ['Apex', 'Horizon', 'Vertex', 'Quantum', 'Cipher', 'Aegis', 'Omni', 'Vanguard', 'Pinnacle', 'Meridian'];
        $suffixes = ['Dynamics', 'Holdings', 'Labs', 'Logistics', 'Networks', 'Heavy Industries', 'Capital', 'Technologies', 'Resources', 'Ventures'];

        $name = $prefixes[array_rand($prefixes)] . ' ' . $suffixes[array_rand($suffixes)];

        return ['name' => $name];
    }
}
