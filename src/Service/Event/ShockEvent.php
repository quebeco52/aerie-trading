<?php

declare(strict_types=1);

namespace App\Service\Event;

class ShockEvent
{
    public const VIRAL_GROWTH = 'viral_growth';
    public const REGULATORY_FINE = 'regulatory_fine';
    public const SEVERE_CHURN = 'severe_churn';
    public const BANK_RUN = 'bank_run';
    public const BANK_SEIZURE = 'bank_seizure';
    public const MASSIVE_CREDIT_PROVISION = 'massive_credit_provision';
    public const ELEVATED_LOAN_DEFAULTS = 'elevated_loan_defaults';
    public const RESERVE_RELEASE = 'reserve_release';
    public const CUSTOMER_DEPOSIT_FLIGHT = 'customer_deposit_flight';
    public const CAPTURED_NEW_DEPOSITS = 'captured_new_deposits';
    public const PERFORMANCE_FEE_SURGE = 'performance_fee_surge';
    public const FUND_OUTFLOWS = 'fund_outflows';
    
    public const TITAN_INTERVENTION = 'titan_intervention';
    public const SOVEREIGN_WEALTH_DEPLOYMENT = 'sovereign_wealth_deployment';

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
    public const PE_HURDLE_RATE_MISSED = 'pe_hurdle_rate_missed';
    public const PE_DEAL_DROUGHT = 'pe_deal_drought';
    public const PE_LEVERAGE_RECAPITALIZATION = 'pe_leverage_recapitalization';
    public const PE_PORTFOLIO_MARKDOWN = 'pe_portfolio_markdown';
    public const HF_QUANT_ALPHA_SURGE = 'hf_quant_alpha_surge';
    public const HF_MARGIN_CALL = 'hf_margin_call';
    public const HF_PERFORMANCE_FEE_CRYSTALLIZATION = 'hf_performance_fee_crystallization';
    public const HF_DIRECTIONAL_BLOWUP = 'hf_directional_blowup';
    public const DEFENSE_CONTRACT_LOSS = 'defense_contract_loss';
    public const DEFENSE_CONTRACT_WIN = 'defense_contract_win';
    public const CREDIT_UNSECURED_PROVISIONS = 'credit_unsecured_provisions';
    public const IB_REGULATORY_SETTLEMENT = 'ib_regulatory_settlement';
    public const IB_MNA_LANDMARK_MANDATE = 'ib_mna_landmark_mandate';
    public const IB_MNA_MANDATE_LOSS = 'ib_mna_mandate_loss';
    public const IB_MNA_SYNDICATION_BOOM = 'ib_mna_syndication_boom';
    public const IB_DCM_UNDERWRITING_BOOM = 'ib_dcm_underwriting_boom';
    public const IB_PROP_TRADING_SURGE = 'ib_prop_trading_surge';
    public const IB_COUNTERPARTY_DEFAULT = 'ib_counterparty_default';
    public const DISTRESSED_DEBT_RESTRUCTURING = 'distressed_debt_restructuring';
    public const GEOPOLITICAL_EXPORT_BAN = 'geopolitical_export_ban';
    public const GEOPOLITICAL_SANCTIONS = 'geopolitical_sanctions';
    public const REINSURANCE_ATTACHMENT_BREACH = 'reinsurance_attachment_breach';
    
    public const LITIGATION_SETTLEMENT_WIN = 'litigation_settlement_win';
    public const LITIGATION_SETTLEMENT_LOSS = 'litigation_settlement_loss';

    public const AUTO_SUPPLY_CHAIN_DISRUPTION = 'auto_supply_chain_disruption';
    public const AUTO_SUBPRIME_DEFAULT_SURGE = 'auto_subprime_default_surge';
    public const AUTO_PRICING_POWER_SURGE = 'auto_pricing_power_surge';

    public const CRISIS_MANAGEMENT_BOOM = 'crisis_management_boom';
    public const TALENT_PLACEMENT_BOOM = 'talent_placement_boom';
    public const SUBSIDY_CUT = 'subsidy_cut';
    public const LOGISTICS_SURGE_PRICING = 'logistics_surge_pricing';

    public const INFRASTRUCTURE_BILL_WIN = 'infrastructure_bill_win';
    public const PROJECT_DELAY = 'project_delay';
    public const INFRASTRUCTURE_FAILURE = 'grid_failure_wildfire';

    public const ENVIRONMENTAL_DISASTER = 'environmental_disaster';
    public const TELECOM_PRICE_WAR = 'telecom_price_war';
    public const SPECTRUM_AUCTION = 'spectrum_auction';

    public const SECURITY_BREACH = 'security_breach';
    public const GEOPOLITICAL_CONFLICT = 'geopolitical_conflict';
    public const LABOR_STRIKE = 'labor_strike';

    public const MANDATORY_HEALTHCARE_EXPANSION = 'mandatory_healthcare_expansion';
    public const HEALTHCARE_AUDIT_CLAWBACK = 'healthcare_audit_clawback';

    public const APPAREL_SUPPLY_CHAIN_DISRUPTION = 'apparel_supply_chain_disruption';
    public const APPAREL_VIRAL_PRODUCT = 'apparel_viral_product';

    public const CHEMICAL_CRACK_SPREAD_SQUEEZE = 'chemical_crack_spread_squeeze';
    public const CHEMICAL_PLANT_TURNAROUND = 'chemical_plant_turnaround';
    public const CHEMICAL_AGRI_BOOM = 'chemical_agri_boom';

    public const CONGLOMERATE_PORTFOLIO_REALIGNMENT = 'conglomerate_portfolio_realignment';
    public const CONGLOMERATE_SUBSIDIARY_WRITEDOWN = 'conglomerate_subsidiary_writedown';
}



