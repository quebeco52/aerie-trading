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
            ShockEvent::GLOBAL_RECESSION => $this->getRandomPhrase([
                "The global economy has officially entered a severe recession, triggering widespread panic.",
                "Global GDP contracts sharply as a severe economic recession takes hold across all sectors.",
                "A systemic global downturn begins, marked by plunging output and skyrocketing unemployment."
            ]),
            ShockEvent::INFLATION_CRISIS => $this->getRandomPhrase([
                "Inflation spirals out of control globally, devastating consumer purchasing power.",
                "A historic inflation crisis grips the world economy, forcing central banks into extreme measures.",
                "Runaway inflation destabilizes the global supply chain, causing a massive cost-of-living crisis."
            ]),
            ShockEvent::EMERGENCY_STIMULUS => $this->getRandomPhrase([
                "Central banks announce an unprecedented emergency stimulus package to rescue the economy.",
                "Global authorities deploy massive quantitative easing to flood the financial system with liquidity.",
                "Emergency rate cuts and trillions in stimulus are injected to prevent a total market collapse."
            ]),
            default => "Experienced an unexpected market event."
        };
    }

    private function getRandomPhrase(array $phrases): string
    {
        return $phrases[array_rand($phrases)];
    }
}
