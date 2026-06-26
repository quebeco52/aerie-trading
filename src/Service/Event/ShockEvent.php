<?php

namespace App\Service\Event;

class ShockEvent
{
    public const VIRAL_GROWTH = 'viral_growth';
    public const REGULATORY_FINE = 'regulatory_fine';
    public const SEVERE_CHURN = 'severe_churn';
    public const BANK_RUN = 'bank_run';
    public const MASSIVE_CREDIT_PROVISION = 'massive_credit_provision';
    public const ELEVATED_LOAN_DEFAULTS = 'elevated_loan_defaults';
    public const CUSTOMER_DEPOSIT_FLIGHT = 'customer_deposit_flight';
    public const CAPTURED_NEW_DEPOSITS = 'captured_new_deposITS';

    // Global Macro Systemic Shocks
    public const GLOBAL_RECESSION = 'global_recession';
    public const INFLATION_CRISIS = 'inflation_crisis';
    public const EMERGENCY_STIMULUS = 'emergency_stimulus';
    public const SURPRISE_STRONG_ECONOMY = 'surprise_strong_economy';
}
