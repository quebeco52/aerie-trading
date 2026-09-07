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
                "Suffered a bank run. Forced into emergency borrowing of \$" . ($context['amount'] ?? '0.00') . "B to cover deposit flight.",
                "Faced a sudden liquidity crisis as depositors withdrew \$" . ($context['amount'] ?? '0.00') . "B in a panic.",
                "Experienced severe capital flight requiring a \$" . ($context['amount'] ?? '0.00') . "B emergency liquidity injection."
            ]),
            ShockEvent::BANK_SEIZURE => $this->getRandomPhrase([
                "Breached statutory minimum capital requirements. Regulators stepped in with an emergency seizure.",
                "Seized by banking regulators following severe capital depletion and balance sheet insolvency.",
                "Fell below statutory Basel capital minimums, triggering an immediate regulatory takeover."
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

            ShockEvent::VOLATILITY_SURGE => $this->getRandomPhrase([
                "Record trading volumes driven by extreme market volatility resulted in massive fee generation.",
                "Captured extraordinary commission and order-flow revenue amid heightened market volatility.",
                "Trading desks surged as client volatility hedging drove record transaction volume."
            ]),
            ShockEvent::ADVISORY_CRASH => $this->getRandomPhrase([
                "Suffered a steep decline in investment banking deal flow and advisory fees.",
                "Experienced a severe advisory fee drought as corporate M&A activity ground to a halt.",
                "Quarterly deal syndications dropped sharply amid tightening credit conditions."
            ]),
            ShockEvent::BIOTECH_DRUG_APPROVAL => $this->getRandomPhrase([
                "Received landmark regulatory approval for a blockbuster specialty drug pipeline.",
                "Secured accelerated regulatory clearance for a high-margin clinical therapeutic.",
                "Phase III clinical success unlocked major commercial licensing milestones."
            ]),
            ShockEvent::BIOTECH_TRIAL_SETBACK => $this->getRandomPhrase([
                "Suffered a major clinical trial setback and patent cliff generic erosion.",
                "Lead pipeline therapeutic failed to meet efficacy endpoints, prompting asset write-downs.",
                "Faced severe generic price competition following primary patent expiration."
            ]),
            ShockEvent::CLEARING_SYSTEMIC_DEFAULT => $this->getRandomPhrase([
                "A massive systemic default breached the initial margin pool, forcing the clearinghouse to cover billions in toxic settlements.",
                "Emergency default fund drawdowns were triggered after a member firm insolvency.",
                "Absorbed significant member settlement losses during a violent liquidation cascade."
            ]),
            ShockEvent::CLEARING_PANIC_FEES => $this->getRandomPhrase([
                "Record transaction volume driven by market panic generated massive clearing fees.",
                "Heightened derivative clearing and margin re-hypothecation drove outsized fee income.",
                "Capitalized on surge in intraday margin settlement volume across institutional desks."
            ]),
            ShockEvent::LUXURY_BRAND_DILUTION => $this->getRandomPhrase([
                "Suffered brand dilution and inventory write-downs following a poorly received creative direction.",
                "High-end retail foot traffic declined sharply, forcing promotional markdowns.",
                "Experienced weakening pricing power across flagship luxury collections."
            ]),
            ShockEvent::LUXURY_CULTURAL_DOMINANCE => $this->getRandomPhrase([
                "Captured immense global demand with a culturally dominant fashion collection.",
                "Flagship luxury boutiques reported record full-price sell-through rates.",
                "Brand desirability reached new highs, allowing aggressive price hikes across core lines."
            ]),
            ShockEvent::PRODUCT_RECALL => $this->getRandomPhrase([
                "Suffered a massive product recall due to severe supply chain contamination.",
                "Forced into a costly national product recall following quality inspection failures.",
                "Faced sudden regulatory scrutiny and fines over product health concerns."
            ]),
            ShockEvent::SHIPPING_PORT_CONGESTION => $this->getRandomPhrase([
                "Capitalized on severe global port congestion with record-breaking container spot rates.",
                "Supply chain bottlenecks drove charter and freight spot yields to historical highs.",
                "Captured massive premium surcharges amid global logistics constraints."
            ]),
            ShockEvent::SHIPPING_CAPACITY_GLUT => $this->getRandomPhrase([
                "Suffered operating losses due to a severe global vessel capacity glut and collapsing freight rates.",
                "Excess container tonnage pressured global spot charter rates lower.",
                "Fleet utilization dropped as oversupply weighed on international shipping margins."
            ]),
            ShockEvent::SEMICONDUCTOR_FAB_SHORTAGE => $this->getRandomPhrase([
                "Achieved 100% fab capacity utilization amid a global technological hardware shortage.",
                "Soaring wafer pricing power and fully booked cleanroom lines drove record foundry margins.",
                "High-performance compute demand triggered multi-year advanced node pre-orders."
            ]),
            ShockEvent::SEMICONDUCTOR_INVENTORY_CORRECTION => $this->getRandomPhrase([
                "Suffered severe margin drag from underutilized cleanrooms during an industry inventory correction.",
                "Customer destocking across consumer electronics reduced wafer shipment volumes.",
                "Faced pricing pressure as downstream partners worked off excess chip inventories."
            ]),
            ShockEvent::REIT_TENANT_BANKRUPTCIES => $this->getRandomPhrase([
                "Suffered a sudden wave of anchor tenant bankruptcies and commercial lease defaults.",
                "Major commercial portfolio defaults required emergency lease restructurings.",
                "Elevated tenant delinquencies and bad debt provisions weighed on quarterly NOI."
            ]),
            ShockEvent::REIT_ELEVATED_VACANCIES => $this->getRandomPhrase([
                "Elevated commercial vacancies and unpaid rent impacted quarterly NOI.",
                "Leasing velocity slowed as corporate occupiers downsized square footage.",
                "Offering increased tenant improvement allowances squeezed net rental margins."
            ]),
            ShockEvent::CATASTROPHIC_CLAIM_LOSSES => $this->getRandomPhrase([
                "Suffered catastrophic claim losses from a major systemic disaster.",
                "Record weather and casualty claim events severely impacted quarterly combined ratio.",
                "Reinsurance treaty attachments were triggered following severe insured catastrophe losses."
            ]),
            ShockEvent::ELEVATED_CLAIM_PAYOUTS => $this->getRandomPhrase([
                "Elevated claim payouts negatively impacted quarterly underwriting margins.",
                "Rising casualty claim severity frequency pressured underwriting profitability.",
                "Saw higher-than-expected insurance loss reserves across commercial lines."
            ]),
            ShockEvent::PE_CARRIED_INTEREST_SURGE => $this->getRandomPhrase([
                "Generated massive carried interest fees following a series of highly successful portfolio exits.",
                "Landmark portfolio company IPO and strategic sales crystallized substantial carry.",
                "Fund realizations exceeded hurdle rates, driving exceptional performance fee income."
            ]),
            ShockEvent::PE_HURDLE_RATE_MISSED => $this->getRandomPhrase([
                "Failed to clear the minimum hurdle rate, resulting in zero carried interest crystallization.",
                "Missed performance targets triggered a complete wipeout of performance fees for the quarter.",
                "Underperforming portfolio assets caused the firm to miss its preferred return hurdle, zeroing out carry."
            ]),
            ShockEvent::PE_DEAL_DROUGHT => $this->getRandomPhrase([
                "Suffered a severe deal drought as frozen credit markets prevented portfolio exits.",
                "Lack of debt financing stalled leveraged buyout deployment and exit realizations.",
                "Extended portfolio holding periods delayed carried interest crystallization."
            ]),
            ShockEvent::HF_QUANT_ALPHA_SURGE => $this->getRandomPhrase([
                "Black-box quantitative trading desks exploited massive market volatility, generating historic stat-arb alpha.",
                "High-frequency algorithmic market-making desks minted record profits amid violent price swings.",
                "Proprietary volatility arbitrage models captured unprecedented spreads across fragmented markets."
            ]),
            ShockEvent::HF_MARGIN_CALL => $this->getRandomPhrase([
                "Spreading credit contagion and blowing credit spreads triggered severe prime broker margin calls.",
                "Forced de-grossing and fire-sale liquidations locked in steep mark-to-market trading losses.",
                "Prime brokers aggressively hiked collateral haircuts, forcing emergency portfolio de-leveraging."
            ]),
            ShockEvent::HF_PERFORMANCE_FEE_CRYSTALLIZATION => $this->getRandomPhrase([
                "Crystallized massive 20% incentive fees as flagship funds set new all-time high-water marks.",
                "Explosive trading gains cleared high-water marks, unlocking substantial performance fee allocations.",
                "Exceptional risk-adjusted fund returns triggered historic quarterly incentive fee payouts."
            ]),
            ShockEvent::HF_DIRECTIONAL_BLOWUP => $this->getRandomPhrase([
                "Heavily leveraged directional bets blew up following an unanticipated macroeconomic regime shift.",
                "Aggressive long/short equity exposure suffered catastrophic drawdown against surging macro headwinds.",
                "Concentrated directional positions collapsed under violent market rotation, wiping out trading equity."
            ]),
            ShockEvent::DEFENSE_CONTRACT_LOSS => $this->getRandomPhrase([
                "Lost a multi-billion dollar next-generation government defense contract to a rival.",
                "Major aerospace program cancellation reduced forward backlog significantly.",
                "Budgetary reallocation by defense procurement officials impacted quarterly order flow."
            ]),
            ShockEvent::DEFENSE_CONTRACT_WIN => $this->getRandomPhrase([
                "Secured a massive, multi-decade international defense contract.",
                "Awarded prime contractor status on a critical next-generation defense platform.",
                "Surging geopolitical demand drove record order intake across munitions and systems."
            ]),
            ShockEvent::CREDIT_UNSECURED_PROVISIONS => $this->getRandomPhrase([
                "Took massive provisions for unsecured credit defaults as consumer health deteriorated.",
                "Rising consumer delinquency rates required substantial credit card loss reserves.",
                "Wrote down unsecured loan balances amid deteriorating household cash flows."
            ]),
            ShockEvent::IB_REGULATORY_SETTLEMENT => $this->getRandomPhrase([
                "Faced a major regulatory settlement, adding significant legal and compliance costs to the quarter.",
                "Agreed to a high-profile compliance penalty, weighing heavily on non-interest expenses.",
                "Incurred substantial litigation reserve charges following regulatory inquiries."
            ]),
            ShockEvent::IB_MNA_LANDMARK_MANDATE => $this->getRandomPhrase([
                "Secured a landmark multi-billion dollar M&A advisory mandate, generating record quarterly fee income.",
                "Led strategic M&A advisory on the quarter's largest megadeal, capturing outsized advisory retainers.",
                "Top-ranked league table advisory performance drove exceptional fee generation."
            ]),
            ShockEvent::IB_MNA_MANDATE_LOSS => $this->getRandomPhrase([
                "Lost a high-profile advisory mandate to a rival, triggering a sharp advisory revenue drought.",
                "Key advisory clients postponed strategic transactions, depressing investment banking fees.",
                "Experienced a sudden lull in completed advisory mandates."
            ]),
            ShockEvent::IB_MNA_SYNDICATION_BOOM => $this->getRandomPhrase([
                "Orchestrated a massive wave of corporate mergers and IPO syndications, capturing record advisory fees.",
                "Booming equity capital markets drove strong IPO underwriting and syndication revenue.",
                "Coordinated landmark cross-border financing facilities for major enterprise clients."
            ]),
            ShockEvent::IB_DCM_UNDERWRITING_BOOM => $this->getRandomPhrase([
                "A sharply steepening yield curve sparked a debt capital markets boom, driving record bond underwriting volumes.",
                "Corporate refinancing wave boosted debt underwriting and origination fees.",
                "Captured dominant market share in institutional bond issuance syndications."
            ]),
            ShockEvent::IB_PROP_TRADING_SURGE => $this->getRandomPhrase([
                "Proprietary trading desks generated billions in market-making and arbitrage revenue during severe market panic.",
                "Fixed income, currency, and commodities (FICC) trading desks captured record bid-ask spreads.",
                "Macro trading desks capitalized on currency and interest rate volatility."
            ]),
            ShockEvent::IB_COUNTERPARTY_DEFAULT => $this->getRandomPhrase([
                "Suffered catastrophic prime brokerage losses following the sudden liquidation and default of a highly leveraged family office.",
                "Emergency liquidation of concentrated counterparty swap positions resulted in severe prime brokerage write-downs.",
                "Incurred massive counterparty credit losses as a multi-billion dollar hedge fund client failed to meet margin calls."
            ]),
            ShockEvent::DISTRESSED_DEBT_RESTRUCTURING => $this->getRandomPhrase([
                "Executed massive restructuring deals on defaulted corporate debt, unlocking extraordinary turnaround gains.",
                "Capitalized on corporate credit spread blowouts by acquiring senior debt at steep discounts.",
                "Orchestrated successful debtor-in-possession restructurings across distressed assets."
            ]),
            ShockEvent::INFRASTRUCTURE_FAILURE => $this->getRandomPhrase([
                "Suffered a major grid infrastructure failure and catastrophic wildfire liability charges.",
                "Grid failure and wildfire damage claims severely impacted quarterly operating margins.",
                "Faced sudden wildfire liability reserves following catastrophic grid equipment failure."
            ]),
            ShockEvent::SECURITY_BREACH => $this->getRandomPhrase([
                "Suffered a catastrophic security breach and VIP protection failure, prompting immediate contract terminations.",
                "High-profile tactical failure triggered intense legal scrutiny, lawsuits, and client flight.",
                "Faced severe reputational damage and client cancellations following a publicized security breakdown."
            ]),
            ShockEvent::GEOPOLITICAL_CONFLICT => $this->getRandomPhrase([
                "Surge in global geopolitical conflict drove record demand for private military extraction and expeditionary security.",
                "Outbreak of regional hostilities triggered an explosion in high-margin expeditionary deployment contracts.",
                "Escalating international tensions fueled massive contract awards for specialized private security forces."
            ]),
            ShockEvent::LABOR_STRIKE => $this->getRandomPhrase([
                "Fulfillment center labor strikes and unionization votes severely disrupted logistics operations.",
                "Mass warehouse worker walkouts and picket lines halted regional package distribution.",
                "Coordinated labor strikes across key fulfillment hubs triggered widespread delivery delays and emergency overtime costs."
            ]),
            ShockEvent::GEOPOLITICAL_EXPORT_BAN => $this->getRandomPhrase([
                "Targeted arms export embargoes and congressional export restrictions curtailed foreign shipments.",
                "Geopolitical export licensing bans halted international military platform deliveries.",
                "Foreign military sales were frozen following new congressional defense export sanctions."
            ]),
            ShockEvent::PROJECT_DELAY => $this->getRandomPhrase([
                "Unanticipated engineering defects and schedule overruns triggered ASC 606 reach-forward project losses.",
                "Complex developmental milestone delays and supply chain logjams led to programmatic forward loss charges.",
                "Project delays on fixed-price development contracts resulted in significant cost overrun provisions."
            ]),

            ShockEvent::CONGLOMERATE_PORTFOLIO_REALIGNMENT => $this->getRandomPhrase([
                "Completed a landmark bolt-on acquisition, consolidating a new industrial subsidiary into the group.",
                "Portfolio realignment unlocked substantial gains on the divestiture of a non-core operating segment.",
                "Opportunistic acquisition of a distressed competitor materially expanded the industrial subsidiary base."
            ]),
            ShockEvent::CONGLOMERATE_SUBSIDIARY_WRITEDOWN => $this->getRandomPhrase([
                "Multi-subsidiary restructuring charges and goodwill impairments weighed on group operating costs.",
                "Operational bottlenecks across the industrial segment triggered inventory write-offs and severance provisions.",
                "Consolidation of overlapping subsidiary operations incurred significant one-time restructuring costs."
            ]),

            ShockEvent::RESERVE_RELEASE => $this->getRandomPhrase([
                "Released credit and loan loss reserves as asset quality outperformed expectations.",
                "Strong macroeconomic tailwinds allowed substantial reversal of previous credit reserves.",
                "Lower non-performing loan formations prompted a quarterly credit reserve release."
            ]),
            ShockEvent::PERFORMANCE_FEE_SURGE => $this->getRandomPhrase([
                "Surging asset management performance fees drove record quarterly non-interest income.",
                "Outperforming benchmark hurdles unlocked outsized quarterly incentive fee allocations.",
                "Strong fund alpha generation triggered significant performance fee crystallization."
            ]),
            ShockEvent::FUND_OUTFLOWS => $this->getRandomPhrase([
                "Experienced elevated institutional fund outflows and redemption pressures.",
                "Asset management AUM contracted following market volatility and client rebalancing.",
                "Net redemption outflows reduced recurring management fee revenues."
            ]),
            ShockEvent::TITAN_INTERVENTION => $this->getRandomPhrase([
                "Emergency liquidity support and capital injections from District Titans restored market confidence.",
                "Coordinated institutional backstop facility averted systemic credit contagion.",
                "Strategic cornerstone investment by major financial institutions stabilized asset prices."
            ]),
            ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT => $this->getRandomPhrase([
                "Sovereign wealth funds deployed massive capital reserves to stabilize distressed assets.",
                "Large-scale sovereign capital injections supported district balance sheets during market turmoil.",
                "Strategic sovereign liquidity facilities bolstered broad market liquidity."
            ]),
            ShockEvent::PE_LEVERAGE_RECAPITALIZATION => $this->getRandomPhrase([
                "Executed dividend recapitalizations across private equity portfolio assets, unlocking substantial liquidity.",
                "Completed opportunistic debt refinancing and special dividend payouts across core portfolio holdings.",
                "Optimized capital structure across buyout assets to return cash to fund LPs."
            ]),
            ShockEvent::PE_PORTFOLIO_MARKDOWN => $this->getRandomPhrase([
                "Marked down private equity portfolio company valuations amid multiple compression.",
                "Unrealized fair-value adjustments across technology and growth investments weighed on portfolio net worth.",
                "Weakened public comps required conservative valuation markdowns across private holdings."
            ]),
            ShockEvent::GEOPOLITICAL_SANCTIONS => $this->getRandomPhrase([
                "Sweeping international trade sanctions disrupted global business lines and contract fulfillment.",
                "Imposition of cross-border trade restrictions halted key international shipment routes.",
                "Compliance mandates and sanctions enforcement restricted access to foreign enterprise markets."
            ]),
            ShockEvent::REINSURANCE_ATTACHMENT_BREACH => $this->getRandomPhrase([
                "Severe catastrophe losses breached reinsurance attachment points, transferring risk to retrocessionaires.",
                "Excess-of-loss reinsurance claims exceeded primary retention layers.",
                "Industry-wide catastrophe events exhausted primary treaty deductibles."
            ]),
            ShockEvent::LITIGATION_SETTLEMENT_WIN => $this->getRandomPhrase([
                "Secured a favorable legal settlement, unlocking substantial contingency fee income.",
                "Landmark patent infringement verdict awarded significant damages to the firm.",
                "Resolution of major class-action claims resulted in outsized legal contingency revenues."
            ]),
            ShockEvent::LITIGATION_SETTLEMENT_LOSS => $this->getRandomPhrase([
                "Suffered an adverse litigation verdict, requiring significant legal liability provisions.",
                "Court ruling against the firm triggered substantial damage awards and settlement charges.",
                "Unanticipated legal liability findings increased quarterly litigation reserves."
            ]),
            ShockEvent::AUTO_SUPPLY_CHAIN_DISRUPTION => $this->getRandomPhrase([
                "Semiconductor and parts shortages disrupted assembly lines and delayed vehicle deliveries.",
                "Critical component supply bottlenecks forced temporary factory idling and lower unit volumes.",
                "Tier-1 supplier delivery delays reduced quarterly automotive production."
            ]),
            ShockEvent::AUTO_SUBPRIME_DEFAULT_SURGE => $this->getRandomPhrase([
                "Elevated subprime auto loan defaults pressured captive finance margins.",
                "Surge in vehicle repossessions and credit delinquencies increased captive lending provisions.",
                "Deteriorating consumer credit health reduced financing income across automotive desks."
            ]),
            ShockEvent::AUTO_PRICING_POWER_SURGE => $this->getRandomPhrase([
                "Strong vehicle pricing power and high-margin trim demand drove outsized auto operating margins.",
                "Favorable vehicle model mix and lean dealer inventories supported record average selling prices.",
                "Robust consumer demand for flagship models expanded gross automotive margins."
            ]),
            ShockEvent::CRISIS_MANAGEMENT_BOOM => $this->getRandomPhrase([
                "Corporate restructuring and crisis management consulting retainers surged amid corporate distress.",
                "High-profile corporate turnarounds and turnaround advisory engagements drove record consulting billings.",
                "Advisory practices captured strong demand for operational restructuring and debtor advisory."
            ]),
            ShockEvent::TALENT_PLACEMENT_BOOM => $this->getRandomPhrase([
                "Executive recruitment and specialized talent placement fees reached record highs.",
                "Strong corporate demand for leadership recruitment expanded professional search revenues.",
                "Surge in C-suite placements across growth sectors drove outsized placement retainers."
            ]),
            ShockEvent::SUBSIDY_CUT => $this->getRandomPhrase([
                "Government subsidy reductions and tariff phase-outs squeezed operating margins.",
                "Regulatory policy shifts eliminated key tax credits and government incentive programs.",
                "Reduced public grant allocations increased net operational expenses."
            ]),
            ShockEvent::LOGISTICS_SURGE_PRICING => $this->getRandomPhrase([
                "Tight freight capacity enabled dynamic surge pricing across core shipping lanes.",
                "Peak season carrier capacity constraints drove record contractual and spot freight yields.",
                "High lane utilization and expedited freight premiums expanded logistics operating margins."
            ]),
            ShockEvent::INFRASTRUCTURE_BILL_WIN => $this->getRandomPhrase([
                "Secured landmark federal infrastructure grants and public-private partnership concessions.",
                "Awarded multi-billion dollar municipal transportation and civil works construction contracts.",
                "National infrastructure spending legislation unlocked significant forward project backlog."
            ]),
            ShockEvent::ENVIRONMENTAL_DISASTER => $this->getRandomPhrase([
                "Remediation and cleanup liabilities following an environmental incident weighed on earnings.",
                "Regulatory fines and remediation mandates following a containment leak increased operating costs.",
                "Incurred significant environmental compliance charges and containment expenses."
            ]),
            ShockEvent::TELECOM_PRICE_WAR => $this->getRandomPhrase([
                "Aggressive price cuts and promotional discounting triggered margin erosion across telecom tiers.",
                "Intense competitive discounting and unlimited plan promotions compressed subscriber ARPU.",
                "Subscriber retention promotions and device subsidy wars squeezed telecom service margins."
            ]),
            ShockEvent::SPECTRUM_AUCTION => $this->getRandomPhrase([
                "Secured prime 5G spectrum licenses in a competitive government auction, expanding network reach.",
                "Acquired high-capacity mid-band spectrum blocks to expand mobile broadband leadership.",
                "Strategic spectrum license acquisitions reinforced long-term wireless network capacity."
            ]),
            ShockEvent::MANDATORY_HEALTHCARE_EXPANSION => $this->getRandomPhrase([
                "Mandatory healthcare coverage expansions and higher patient admissions boosted operating volumes.",
                "Expanded statutory health insurance coverage drove higher patient utilization across medical centers.",
                "Increased healthcare enrollment supported robust inpatient and outpatient procedure growth."
            ]),
            ShockEvent::HEALTHCARE_AUDIT_CLAWBACK => $this->getRandomPhrase([
                "Regulatory billing audits and reimbursement clawbacks reduced net medical service revenue.",
                "Statutory Medicare/Medicaid reimbursement revisions resulted in retrospective revenue adjustments.",
                "Payer claims review findings necessitated reserves for disputed clinical billing codes."
            ]),
            ShockEvent::APPAREL_SUPPLY_CHAIN_DISRUPTION => $this->getRandomPhrase([
                "Port logjams and fabric supply disruptions delayed seasonal apparel collections.",
                "Textile import bottlenecks and transit delays required costly airfreight expedited shipping.",
                "Supply logjams led to inventory stockouts across high-demand seasonal product lines."
            ]),
            ShockEvent::APPAREL_VIRAL_PRODUCT => $this->getRandomPhrase([
                "Viral social media momentum and influencer adoption triggered an explosive sell-through of flagship fashion lines.",
                "Breakthrough viral demand drove record full-price sell-through rates across core apparel collections.",
                "Culturally trending footwear and apparel releases sold out instantly across digital and retail channels."
            ]),
            ShockEvent::CHEMICAL_CRACK_SPREAD_SQUEEZE => $this->getRandomPhrase([
                "Surging hydrocarbon feedstock costs squeezed petrochemical crack spreads.",
                "Elevated natural gas and crude input pricing compressed chemical processing margins.",
                "Upstream commodity inflation narrowed refining and cracking unit margins."
            ]),
            ShockEvent::CHEMICAL_PLANT_TURNAROUND => $this->getRandomPhrase([
                "Unplanned manufacturing outages and extended plant maintenance turnarounds curtailed chemical output.",
                "Scheduled complex catalyst replacement and facility maintenance temporarily reduced production volumes.",
                "Extended chemical refinery turnaround cycles resulted in temporary shipment deferrals."
            ]),
            ShockEvent::CHEMICAL_AGRI_BOOM => $this->getRandomPhrase([
                "Surging global fertilizer demand and agricultural commodity prices drove outsized specialty chemical margins.",
                "Strong crop nutrient pricing and international agronomic demand expanded chemical operating earnings.",
                "High fertilizer utilization and agricultural input pricing power drove record specialty chemical profits."
            ]),

            default => "Experienced an unexpected market event."
        };
    }

    private function getRandomPhrase(array $phrases): string
    {
        return $phrases[array_rand($phrases)];
    }
}
