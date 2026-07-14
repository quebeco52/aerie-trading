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
            ShockEvent::PE_DEAL_DROUGHT => $this->getRandomPhrase([
                "Suffered a severe deal drought as frozen credit markets prevented portfolio exits.",
                "Lack of debt financing stalled leveraged buyout deployment and exit realizations.",
                "Extended portfolio holding periods delayed carried interest crystallization."
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
            ShockEvent::DISTRESSED_DEBT_RESTRUCTURING => $this->getRandomPhrase([
                "Executed massive restructuring deals on defaulted corporate debt, unlocking extraordinary turnaround gains.",
                "Capitalized on corporate credit spread blowouts by acquiring senior debt at steep discounts.",
                "Orchestrated successful debtor-in-possession restructurings across distressed assets."
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
