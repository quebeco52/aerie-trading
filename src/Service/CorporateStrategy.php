<?php

namespace App\Service;

use App\Entity\Stock;

class CorporateStrategy
{
    /**
     * Evaluates the current environment and generates a Corporate Directive.
     * The CorporateAction will strictly obey these target parameters.
     */
    public function formulateDirective(Stock $stock, array $macroState, array $debtHealth, array $zScoreData): array
    {
        $baseStrategy = $stock->getCeoStrategy() ?? 'balanced';
        
        $canIssueDebt = $debtHealth['can_issue_debt'] ?? false;
        $wantsToPaydownDebt = $debtHealth['wants_to_paydown_debt'] ?? false;
        $interestCoverage = $debtHealth['interest_coverage'] ?? 5.0;
        
        // EMERGENCY BOARD OVERRIDE: If the company is facing bankruptcy or a severe liquidity crisis, 
        // the Board of Directors forces the CEO into a 'correcting' survival strategy.
        $isDistressed = ($zScoreData['zone'] ?? 'Safe') !== 'Safe';
        $liquidityCrisis = $interestCoverage < 1.5;
        
        if ($isDistressed || $liquidityCrisis) {
            $baseStrategy = 'correcting';
        }

        // PERSONALITY DIRECTIVES
        $directive = match ($baseStrategy) {
            // Aggressively expand operations and capture market share
            'expanding' => [
                'strategy' => 'expanding',
                'm_and_a_prob_multiplier' => 2.0,
                'divestiture_prob_multiplier' => 0.2,
                'organic_expansion_prob' => 0.85,
                'issue_debt_for_expansion' => true,
                'target_debt_tolerance' => ($debtHealth['debt_tolerance'] ?? 0.5) * 1.5,
                'defend_dividen' => false,
                'cut_dividen' => false,
                'buyback_aggressiveness' => 0.3,
                'paydown_debt_aggressiveness' => 0.2,
            ],
            // Keep the ship steady
            'conservative' => [
                'strategy' => 'conservative',
                'm_and_a_prob_multiplier' => 0.3,
                'divestiture_prob_multiplier' => 1.0,
                'organic_expansion_prob' => 0.30,
                'issue_debt_for_expansion' => false,
                'target_debt_tolerance' => ($debtHealth['debt_tolerance'] ?? 0.5) * 0.5,
                'defend_dividen' => true, // Highly defensive of dividend payouts
                'cut_dividen' => false,
                'buyback_aggressiveness' => 0.6,
                'paydown_debt_aggressiveness' => 1.5,
            ],
            // Turnaround a downward trend
            'correcting' => [
                'strategy' => 'correcting',
                'm_and_a_prob_multiplier' => 0.0,
                'divestiture_prob_multiplier' => 3.0,
                'organic_expansion_prob' => 0.05,
                'issue_debt_for_expansion' => false,
                'target_debt_tolerance' => ($debtHealth['debt_tolerance'] ?? 0.5) * 0.2,
                'defend_dividen' => false,
                'cut_dividen' => true, 
                'buyback_aggressiveness' => 0.0, // Preserve cash
                'paydown_debt_aggressiveness' => 3.0, // Aggressive deleveraging
            ],
            // Optimize the balance sheet for optimal operations
            'optimizing' => [
                'strategy' => 'optimizing',
                'm_and_a_prob_multiplier' => 0.8,
                'divestiture_prob_multiplier' => 1.5,
                'organic_expansion_prob' => 0.40,
                'issue_debt_for_expansion' => true,
                'target_debt_tolerance' => ($debtHealth['debt_tolerance'] ?? 0.5) * 1.0,
                'defend_dividen' => false,
                'cut_dividen' => false,
                'buyback_aggressiveness' => 2.0, // Maximize EPS through aggressive buybacks
                'paydown_debt_aggressiveness' => 1.0,
            ],
            default => [
                'strategy' => 'balanced',
                'm_and_a_prob_multiplier' => 1.0,
                'divestiture_prob_multiplier' => 1.0,
                'organic_expansion_prob' => 0.50,
                'issue_debt_for_expansion' => true,
                'target_debt_tolerance' => ($debtHealth['debt_tolerance'] ?? 0.5) * 1.0,
                'defend_dividen' => false,
                'cut_dividen' => false,
                'buyback_aggressiveness' => 1.0,
                'paydown_debt_aggressiveness' => 1.0,
            ],
        };

        // MACROECONOMIC REALITY CHECK
        // The economy dictates limits on the CEO's ambitions.
        $outputGap = $macroState['output_gap_ema'] ?? 0.0;
        if ($outputGap < 0.0) {
            // In a recession, expansion cools down and buybacks drop as cash becomes king
            $recessionSeverity = abs($outputGap) * 10.0; // e.g., 2% gap = 0.2 penalty
            $penalty = max(0.2, 1.0 - $recessionSeverity);
            
            $directive['organic_expansion_prob'] *= $penalty;
            $directive['m_and_a_prob_multiplier'] *= $penalty;
            $directive['buyback_aggressiveness'] *= $penalty;
        }

        // FINANCIAL PHYSICS OVERRIDES
        // Even an expanding CEO cannot issue debt if the bond market refuses to buy it.
        if (!$canIssueDebt || $wantsToPaydownDebt) {
            $directive['issue_debt_for_expansion'] = false;
            $directive['m_and_a_prob_multiplier'] *= 0.1;
            $directive['organic_expansion_prob'] *= 0.2;
        }

        // EXPLICIT ACTIONABLE FLAGS
        // These serve as direct boolean triggers for the downstream execution engines
        $directive['should_seek_m_and_a'] = $directive['m_and_a_prob_multiplier'] > 0.8 && $canIssueDebt;
        $directive['should_divest'] = $directive['divestiture_prob_multiplier'] >= 1.5 || $isDistressed;
        $directive['should_buyback'] = $directive['buyback_aggressiveness'] > 0.5 && !$isDistressed && !$wantsToPaydownDebt;
        $directive['should_expand_organically'] = $directive['organic_expansion_prob'] > 0.3 && !$isDistressed;

        return $directive;
    }
}