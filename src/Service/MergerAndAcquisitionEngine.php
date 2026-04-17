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
        private MarketEvent $marketEvent
    ) {}

    /**
     * Evaluates if a stock is in a position to acquire a private company.
     * @return array|null Returns an array with the M&A event details, or null if no deal occurred.
     */
    public function evaluatePrivateAcquisition(Stock $acquirer, array $macroState, float $dt): ?array
    {
        $treasury = (float) $acquirer->getCorporateTreasury();
        $ticker = $acquirer->getTicker();
        $outputGap = $macroState['output_gap'] ?? 0.0;

        $price = (float) $acquirer->getPrice();
        $shares = (float) $acquirer->getSharesOutstanding();
        $marketCap = $price * $shares;

        // Is the company a Mega-Hoarder? (Cash > 25% of Market Cap AND > $50B)
        $isHoarder = $treasury > ($marketCap * 0.25);
        $isMegaHoarder = $treasury > ($marketCap * 0.50);

        // =========================================================================
        // LORE-ACCURATE HUNTING LOGIC (With Randomized Synergy Ranges)
        // =========================================================================

        $config = match (true) {
            $isMegaHoarder => [
                'prob' => 6.00,
                'spend' => 0.50,
                'syn_min' => 0.85,
                'syn_max' => 1.15,
                'type' => 'CONGLOMERATE EXPANSION'
            ],

            $isHoarder => [
                'prob' => 2.50,
                'spend' => 0.50,
                'syn_min' => 0.85,
                'syn_max' => 1.15,
                'type' => 'CONGLOMERATE EXPANSION'
            ],

            // Black Swan: Always hunting. Ruthless asset strippers.
            $ticker === 'SWAN' && $treasury > ($marketCap * 0.05) => [
                'prob' => 7.00,
                'spend' => 0.40,
                'syn_min' => 1.00,
                'syn_max' => 1.20,
                'type' => 'HOSTILE TAKEOVER'
            ],

            // Owl Capital: Only hunts in deep recessions.
            $ticker === 'OWLS' && $treasury > 50_000_000_000.0 && $outputGap < -0.025 => [
                'prob' => 2.00,
                'spend' => 0.70,
                'syn_min' => 1.15,
                'syn_max' => 1.20,
                'type' => 'DISTRESSED BUYOUT'
            ],

            // Kingfisher: Only hunts in massive booms. Uses heavy leverage.
            $ticker === 'KING' && $treasury > 2_000_000_000.0 && $outputGap > 0.025 => [
                'prob' => 1.00,
                'spend' => 0.80,
                'syn_min' => 0.90,
                'syn_max' => 1.10,
                'type' => 'LEVERAGED BUYOUT'
            ],

            // Standard Mega-Corps expanding their footprint
            $treasury > 15_000_000_000.0 => [
                'prob' => 0.10,
                'spend' => 0.20,
                'syn_min' => 0.90,
                'syn_max' => 1.20,
                'type' => 'STRATEGIC ACQUISITION'
            ],

            // Nothing triggered
            default => null,
        };

        if (!$config) return null;

        // RNG Roll to see if a deal actually happens this tick
        if ((mt_rand() / mt_getrandmax()) >= ($config['prob'] * $dt)) {
            return null;
        }


        // EXECUTE THE M&A DEAL


        $purchasePrice = $treasury * (mt_rand(50, 100) / 100.0) * $config['spend'];
        if ($purchasePrice < 1_000_000_000.0) return null; // Ignore tiny deals

        $target = $this->generateProceduralTarget();

        // DRAIN THE CASH
        $newTreasury = $treasury - $purchasePrice;
        $acquirer->setCorporateTreasury((string) $newTreasury);

        // THE RANDOMIZED SYNERGY ROLL
        $minInt = (int) ($config['syn_min'] * 100);
        $maxInt = (int) ($config['syn_max'] * 100);
        $synergyMultiplier = mt_rand($minInt, $maxInt) / 100.0;

        // GOODWILL & CLEAN SURPLUS ACCOUNTING
        // Swapped Cash for Assets. Base Equity remains identical.
        // HOWEVER, must adjust Equity by the Synergy Premium/Discount (Goodwill Impairment).
        $synergyValueCreation = $purchasePrice * ($synergyMultiplier - 1.0);

        $currentEquity = (float) $acquirer->getTotalEquity();
        $newEquity = $currentEquity + $synergyValueCreation;
        $acquirer->setTotalEquity((string) max(10.0, $newEquity));

        // BOOST OR DESTROY RETURN ON INVESTED CAPITAL (ROIC)
        $currentRoic = (float) $acquirer->getCurrentRoic() ?: (float) $acquirer->getBaselineRoic();
        $roicBump = ($synergyMultiplier - 1.0) * 0.05;
        $acquirer->setCurrentRoic((string) max(-0.10, $currentRoic + $roicBump));

        // GENERATE THE MARKET EVENT & PRICE SHOCK
        $purchasePriceB = number_format($purchasePrice / 1_000_000_000, 1);
        $desc = "";

        if ($synergyMultiplier < 1.0) {
            // Bad deal
            $desc = "{$acquirer->getName()} executed a \${$purchasePriceB}B {$config['type']} of {$target['name']}.";
            $shockValue = -1.0 * (mt_rand(200, 600) / 100.0); // 2% to 6% gap DOWN
        } else {
            // A successful deal.
            if ($config['type'] === 'HOSTILE TAKEOVER') {
                $desc = "{$acquirer->getName()} executed a ruthless hostile takeover of {$target['name']} for \${$purchasePriceB}B, initiating immediate asset stripping.";
            } elseif ($config['type'] === 'CONGLOMERATE EXPANSION') {
                $desc = "Deploying its cash reserves, {$acquirer->getName()} went on an acquisition spree, buying {$target['name']} for \${$purchasePriceB}B.";
            } elseif ($config['type'] === 'DISTRESSED BUYOUT') {
                $desc = "Capitalizing on panic, {$acquirer->getName()} secured {$target['name']} in a highly lucrative distressed buyout for \${$purchasePriceB}B.";
            } else {
                $desc = "{$acquirer->getName()} announced the {$config['type']} of {$target['name']} for \${$purchasePriceB}B, unlocking massive synergies.";
            }
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
        // By removing the max(0) floor, shedding 50% of a -$1B loss naturally reduces it to a -$500M loss.
        $seller->setTotalNetIncome((string) ($netIncome - $lostNetIncome));

        $newEquity = $currentEquity - $lostEquity + $salePrice;
        $seller->setTotalEquity((string) max(10.0, $newEquity));

        // BOOST THE RETURN ON INVESTED CAPITAL (ROIC)
        // Shedding assets makes the core business leaner. 
        $currentRoic = $currentRoic ?: (float) $seller->getBaselineRoic();
        $roicBump = $divestedFraction * 0.20;
        $seller->setCurrentRoic((string) ($currentRoic + $roicBump));

        // GENERATE THE MARKET EVENT
        $salePriceB = number_format($salePrice / 1_000_000_000, 1);

        if ($isDistressed) {
            $desc = "Suffocating under corporate bloat and poor returns, {$seller->getName()} executed an emergency fire-sale of major divisions for \${$salePriceB}B to save the core business.";
            $shockValue = mt_rand(300, 600) / 100.0; // Market cheers the massive restructuring (3% to 6% gap up)
        } else {
            $desc = "{$seller->getName()} has divested a non-core business unit for \${$salePriceB}B in cash, shedding dead weight to boost capital efficiency.";
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
