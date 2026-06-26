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
                "An unexpected bankruptcy of a major global institution triggers a sudden market panic.",
                "A severe, localized financial collapse sends shockwaves through the global economy.",
                "A sudden disruption in global supply chains sparks a sharp contraction in economic output."
            ]),
            ShockEvent::INFLATION_CRISIS => $this->getRandomPhrase([
                "A sudden surge in global energy prices triggers a sharp, unexpected spike in inflation.",
                "Unexpected resource shortages cause a rapid and destabilizing increase in the cost of goods.",
                "A severe localized commodity crisis ripples through the market, driving up global inflation."
            ]),
            ShockEvent::EMERGENCY_STIMULUS => $this->getRandomPhrase([
                "A large liquidity injection by government initiative catches the market by surprise.",
                "Central banks unexpectedly announce a targeted stimulus program to support market stability.",
                "Authorities deploy an emergency quantitative easing measure, flooding the market with capital."
            ]),
            ShockEvent::SURPRISE_STRONG_ECONOMY => $this->getRandomPhrase([
                "A major breakthrough in global trade agreements sparks a sudden surge in economic optimism.",
                "Unexpectedly strong consumer spending data catches the market off guard, accelerating growth.",
                "A sudden technological boom triggers a massive wave of global investment and expansion."
            ]),
            default => "Experienced an unexpected market event."
        };
    }

    private function getRandomPhrase(array $phrases): string
    {
        return $phrases[array_rand($phrases)];
    }
}
