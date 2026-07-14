<?php

declare(strict_types=1);

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
    public const CAPTURED_NEW_DEPOSITS = 'captured_new_deposits';
    public const PERFORMANCE_FEE_SURGE = 'performance_fee_surge';
    public const FUND_OUTFLOWS = 'fund_outflows';

    // Sector-Specific Shocks
    public const VOLATILITY_SURGE = 'volatility_surge';
    public const ADVISORY_CRASH = 'advisory_crash';
    public const BIOTECH_DRUG_APPROVAL = 'biotech_drug_approval';
    public const BIOTECH_TRIAL_SETBACK = 'biotech_trial_setback';
    public const CLEARING_SYSTEMIC_DEFAULT = 'clearing_systemic_default';
    public const CLEARING_PANIC_FEES = 'clearing_panic_fees';
    public const LUXURY_BRAND_DILUTION = 'luxury_brand_dilution';
    public const LUXURY_CULTURAL_DOMINANCE = 'luxury_cultural_dominance';
    public const PRODUCT_RECALL = 'product_recall';
    public const SHIPPING_PORT_CONGESTION = 'shipping_port_congestion';
    public const SHIPPING_CAPACITY_GLUT = 'shipping_capacity_glut';
    public const SEMICONDUCTOR_FAB_SHORTAGE = 'semiconductor_fab_shortage';
    public const SEMICONDUCTOR_INVENTORY_CORRECTION = 'semiconductor_inventory_correction';
    public const REIT_TENANT_BANKRUPTCIES = 'reit_tenant_bankruptcies';
    public const REIT_ELEVATED_VACANCIES = 'reit_elevated_vacancies';
    public const CATASTROPHIC_CLAIM_LOSSES = 'catastrophic_claim_losses';
    public const ELEVATED_CLAIM_PAYOUTS = 'elevated_claim_payouts';
    public const PE_CARRIED_INTEREST_SURGE = 'pe_carried_interest_surge';
    public const PE_DEAL_DROUGHT = 'pe_deal_drought';
    public const DEFENSE_CONTRACT_LOSS = 'defense_contract_loss';
    public const DEFENSE_CONTRACT_WIN = 'defense_contract_win';
    public const CREDIT_UNSECURED_PROVISIONS = 'credit_unsecured_provisions';
    public const IB_REGULATORY_SETTLEMENT = 'ib_regulatory_settlement';
    public const IB_MNA_LANDMARK_MANDATE = 'ib_mna_landmark_mandate';
    public const IB_MNA_MANDATE_LOSS = 'ib_mna_mandate_loss';
    public const IB_MNA_SYNDICATION_BOOM = 'ib_mna_syndication_boom';
    public const IB_DCM_UNDERWRITING_BOOM = 'ib_dcm_underwriting_boom';
    public const IB_PROP_TRADING_SURGE = 'ib_prop_trading_surge';
    public const DISTRESSED_DEBT_RESTRUCTURING = 'distressed_debt_restructuring';

    // District Lore Systemic Shocks
    public const COUNCIL_TIGHTENING = 'council_tightening';
    public const DISTRICT_OVERHEATING = 'district_overheating';
    public const TITAN_INTERVENTION = 'titan_intervention';
    public const SOVEREIGN_WEALTH_DEPLOYMENT = 'sovereign_wealth_deployment';
}

