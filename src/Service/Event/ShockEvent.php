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
    public const RESERVE_RELEASE = 'reserve_release';
    public const CUSTOMER_DEPOSIT_FLIGHT = 'customer_deposit_flight';
    public const CAPTURED_NEW_DEPOSITS = 'captured_new_deposITS';
    public const PERFORMANCE_FEE_SURGE = 'performance_fee_surge';
    public const FUND_OUTFLOWS = 'fund_outflows';

    // District Lore Systemic Shocks
    public const COUNCIL_TIGHTENING = 'council_tightening';
    public const DISTRICT_OVERHEATING = 'district_overheating';
    public const TITAN_INTERVENTION = 'titan_intervention';
    public const SOVEREIGN_WEALTH_DEPLOYMENT = 'sovereign_wealth_deployment';
}
