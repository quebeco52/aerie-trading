<?php

namespace App\Service\Event;

class NarrativeEngine
{
    public function generateLore(string $eventType, array $context = []): string
    {
        return match ($eventType) {
            ShockEvent::VIRAL_GROWTH => $this->getRandomPhrase([
                "Achieved viral product-market fit with a major new software breakthrough.",
                "Experienced unprecedented viral adoption of a new product feature.",
                "Captured massive market attention following a breakthrough software release."
            ]),
            ShockEvent::REGULATORY_FINE => $this->getRandomPhrase([
                "Suffered a massive anti-trust fine and sweeping data privacy restrictions.",
                "Hit with severe regulatory penalties after a major data breach investigation.",
                "Forced to pay record fines following an anti-competitive practices ruling."
            ]),
            ShockEvent::SEVERE_CHURN => $this->getRandomPhrase([
                "Experienced severe decline due to shifting user trends.",
                "Faced a massive wave of user churn as competitors captured market share.",
                "Reported a steep drop in active users following negative product reception."
            ]),
            ShockEvent::BANK_RUN => $this->getRandomPhrase([
                "Suffered a bank run. Forced into emergency borrowing of \${$context['amount']}B to cover deposit flight.",
                "Faced a sudden liquidity crisis as depositors withdrew \${$context['amount']}B in a panic.",
                "Experienced severe capital flight requiring a \${$context['amount']}B emergency liquidity injection."
            ]),
            ShockEvent::MASSIVE_CREDIT_PROVISION => $this->getRandomPhrase([
                "Took a massive provision for credit losses due to rising loan defaults.",
                "Forced to drastically increase loan loss reserves amid deteriorating credit quality.",
                "Wrote down a significant portion of its loan book following a surge in commercial defaults."
            ]),
            ShockEvent::ELEVATED_LOAN_DEFAULTS => $this->getRandomPhrase([
                "Elevated loan defaults negatively impacted quarterly margins.",
                "Saw a moderate uptick in consumer defaults squeezing net interest margins.",
                "Reported higher than expected non-performing loans this quarter."
            ]),
            ShockEvent::CUSTOMER_DEPOSIT_FLIGHT => $this->getRandomPhrase([
                "Suffered \$" . ($context['amount'] ?? '0.00') . "B in customer deposit flight.",
                "Depositors fled, draining \$" . ($context['amount'] ?? '0.00') . "B from the balance sheet.",
                "Experienced a capital outflow of \$" . ($context['amount'] ?? '0.00') . "B to higher-yielding funds."
            ]),
            ShockEvent::CAPTURED_NEW_DEPOSITS => $this->getRandomPhrase([
                "Captured \$" . ($context['amount'] ?? '0.00') . "B in new customer deposits.",
                "Attracted \$" . ($context['amount'] ?? '0.00') . "B in fresh deposit inflows from competitors.",
                "Saw a surge in safety-seeking capital, adding \$" . ($context['amount'] ?? '0.00') . "B in deposits."
            ]),
            ShockEvent::COUNCIL_TIGHTENING => $this->getRandomPhrase([
                "The Council unexpectedly tightens the district money supply, triggering a sharp economic contraction.",
                "A sudden regulatory crackdown by the Council chokes off credit, sending shockwaves through Glasswater Row.",
                "The governing body forcibly halts municipal infrastructure spending, sparking a sudden localized recession."
            ]),
            ShockEvent::DISTRICT_OVERHEATING => $this->getRandomPhrase([
                "A sudden supply chain bottleneck at Iron Beak Heavy Industries triggers a rapid spike in district inflation.",
                "Massive labor shortages across the district's industrial zones drive up the cost of raw materials.",
                "A severe grid failure at Bird Power Inc. forces energy prices to skyrocket across the autonomous zone."
            ]),
            ShockEvent::TITAN_INTERVENTION => $this->getRandomPhrase([
                // Lakebird Strings
                "Lakebird Bank forcibly injects massive subsidized liquidity into the market, rescuing failing institutions.",
                "Lakebird executed an emergency bailout of municipal infrastructure, flooding the ecosystem with cheap capital.",
                "Lakebird intentionally suppresses interbank lending rates, sparking a massive wave of frictionless corporate expansion.",
                // Black Swan Strings
                "Black Swan Capital deploys billions in predatory private equity, orchestrating a massive wave of leveraged buyouts.",
                "The Obsidian Desk at Black Swan initiates a coordinated liquidity squeeze on short sellers, triggering explosive market growth.",
                "Black Swan forcefully recapitalizes distressed corporate assets, igniting a sudden and violent district-wide expansion."
            ]),
            ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT => $this->getRandomPhrase([
                "The Council unexpectedly deploys its Sovereign Wealth Fund, triggering a massive wave of frictionless economic expansion.",
                "A sweeping new Council policy mandate drastically lowers corporate operating friction, accelerating growth across Glasswater Row.",
                "The district's governing body injects billions from the Sovereign Wealth Fund into core infrastructure, sparking a golden era of high growth."
            ]),
            default => "Experienced an unexpected market event."
        };
    }

    private function getRandomPhrase(array $phrases): string
    {
        return $phrases[array_rand($phrases)];
    }
}
