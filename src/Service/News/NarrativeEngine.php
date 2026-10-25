<?php

declare(strict_types=1);

namespace App\Service\News;

use App\Data\District\Institutions;
use App\Data\Politics\AerieCouncil;
use App\Service\Event\ShockEvent;
use App\Service\Math\MathUtility;

class NarrativeEngine
{
    public function __construct(private readonly MathUtility $math = new MathUtility()) {}

    /**
     * The story an event makes, in one of its phrasings.
     *
     * @param array<string, string|float> $context what the story quotes: a district story's readings already printed
     *                                           (SystemicEventReporter), a company story's amounts in dollars
     */
    public function generateLore(string $eventType, array $context = []): string
    {
        // A dollar amount the story quotes, passed in dollars.
        $amount = WireFormat::money((float) ($context['amount'] ?? 0.0));

        return match ($eventType) {
            ShockEvent::VIRAL_GROWTH => $this->getRandomPhrase([
                "Achieved viral product-market fit with a major new software breakthrough.",
                "Experienced unprecedented viral adoption of a new product feature.",
                "Captured massive market attention following a breakthrough software release.",
            ]),
            ShockEvent::GOODWILL_IMPAIRMENT => $this->getRandomPhrase([
                "Booked a {$amount} non-cash goodwill impairment after the annual test found acquired businesses earning below their cost of capital.",
                "Wrote down {$amount} of acquisition goodwill, conceding that past deals are not earning their keep.",
                "Took a {$amount} goodwill charge as returns on acquired capital fell short of the hurdle rate.",
            ]),
            ShockEvent::REGULATORY_FINE => $this->getRandomPhrase([
                "Suffered a massive anti-trust fine and sweeping data privacy restrictions.",
                "Hit with severe regulatory penalties after a major data breach investigation.",
                "Forced to pay record fines following an anti-competitive practices ruling.",
            ]),
            ShockEvent::SEVERE_CHURN => $this->getRandomPhrase([
                "Experienced severe decline due to shifting user trends.",
                "Faced a massive wave of user churn as competitors captured market share.",
                "Reported a steep drop in active users following negative product reception.",
            ]),
            ShockEvent::BANK_RUN => $this->getRandomPhrase([
                "Suffered a bank run. Forced into emergency borrowing of {$amount} to cover deposit flight.",
                "Faced a sudden liquidity crisis as depositors withdrew {$amount} in a panic.",
                "Experienced severe capital flight requiring a {$amount} emergency liquidity injection.",
            ]),
            ShockEvent::CAPITAL_BELOW_MINIMUM => $this->getRandomPhrase([
                "Fell below the minimum capital requirement. The " . Institutions::FINANCIAL_REGULATOR . " barred dividends and buybacks and ordered a capital plan.",
                "Losses took core capital under the regulatory minimum. The bank must rebuild it before paying shareholders again.",
                "Breached its minimum capital ratio. The " . Institutions::FINANCIAL_REGULATOR . " placed it under restriction until capital is restored.",
            ]),
            ShockEvent::MASSIVE_CREDIT_PROVISION => $this->getRandomPhrase([
                "Took a massive provision for credit losses due to rising loan defaults.",
                "Forced to drastically increase loan loss reserves amid deteriorating credit quality.",
                "Wrote down a significant portion of its loan book following a surge in commercial defaults.",
            ]),
            ShockEvent::ELEVATED_LOAN_DEFAULTS => $this->getRandomPhrase([
                "Elevated loan defaults negatively impacted quarterly margins.",
                "Saw a moderate uptick in consumer defaults squeezing net interest margins.",
                "Reported higher than expected non-performing loans this quarter.",
            ]),
            ShockEvent::CUSTOMER_DEPOSIT_FLIGHT => $this->getRandomPhrase([
                "Suffered {$amount} in customer deposit flight.",
                "Depositors fled, draining {$amount} from the balance sheet.",
                "Experienced a capital outflow of {$amount} to higher-yielding funds.",
            ]),
            ShockEvent::CAPTURED_NEW_DEPOSITS => $this->getRandomPhrase([
                "Captured {$amount} in new customer deposits.",
                "Attracted {$amount} in fresh deposit inflows from competitors.",
                "Saw a surge in safety-seeking capital, adding {$amount} in deposits.",
            ]),

            ShockEvent::VOLATILITY_SURGE => $this->getRandomPhrase([
                "Record trading volumes driven by extreme market volatility resulted in massive fee generation.",
                "Captured extraordinary commission and order-flow revenue amid heightened market volatility.",
                "Trading desks surged as client volatility hedging drove record transaction volume.",
            ]),
            ShockEvent::ADVISORY_CRASH => $this->getRandomPhrase([
                "Suffered a steep decline in investment banking deal flow and advisory fees.",
                "Experienced a severe advisory fee drought as corporate M&A activity ground to a halt.",
                "Quarterly deal syndications dropped sharply amid tightening credit conditions.",
            ]),
            ShockEvent::BIOTECH_DRUG_APPROVAL => $this->getRandomPhrase([
                "Received landmark regulatory approval for a blockbuster specialty drug pipeline.",
                "Secured accelerated regulatory clearance for a high-margin clinical therapeutic.",
                "Phase III clinical success unlocked major commercial licensing milestones.",
            ]),
            ShockEvent::BIOTECH_TRIAL_SETBACK => $this->getRandomPhrase([
                "Suffered a major clinical trial setback and patent cliff generic erosion.",
                "Lead pipeline therapeutic failed to meet efficacy endpoints, prompting asset write-downs.",
                "Faced severe generic price competition following primary patent expiration.",
            ]),
            ShockEvent::BIOTECH_PATENT_CLIFF => $this->getRandomPhrase([
                "Primary marketing exclusivity lapsed on a lead franchise, opening the door to generic entrants.",
                "Loss of exclusivity began eroding branded prescription volumes as biosimilar filings cleared review.",
                "Generic entry against an off-patent flagship therapy triggered rapid script erosion and price concessions.",
            ]),
            ShockEvent::CLEARING_SYSTEMIC_DEFAULT => $this->getRandomPhrase([
                "A massive systemic default breached the initial margin pool, forcing the clearinghouse to cover billions in toxic settlements.",
                "Emergency default fund drawdowns were triggered after a member firm insolvency.",
                "Absorbed significant member settlement losses during a violent liquidation cascade.",
            ]),
            ShockEvent::LUXURY_BRAND_DILUTION => $this->getRandomPhrase([
                "Suffered brand dilution and inventory write-downs following a poorly received creative direction.",
                "High-end retail foot traffic declined sharply, forcing promotional markdowns.",
                "Experienced weakening pricing power across flagship luxury collections.",
            ]),
            ShockEvent::LUXURY_CULTURAL_DOMINANCE => $this->getRandomPhrase([
                "Captured immense global demand with a culturally dominant fashion collection.",
                "Flagship luxury boutiques reported record full-price sell-through rates.",
                "Brand desirability reached new highs, allowing aggressive price hikes across core lines.",
            ]),
            ShockEvent::PRODUCT_RECALL => $this->getRandomPhrase([
                "Suffered a massive product recall due to severe supply chain contamination.",
                "Forced into a costly national product recall following quality inspection failures.",
                "Faced sudden regulatory scrutiny and fines over product health concerns.",
            ]),
            ShockEvent::SHIPPING_PORT_CONGESTION => $this->getRandomPhrase([
                "Capitalised on severe global port congestion with record-breaking container spot rates.",
                "Supply chain bottlenecks drove charter and freight spot yields to historical highs.",
                "Captured massive premium surcharges amid global logistics constraints.",
            ]),
            ShockEvent::SHIPPING_CAPACITY_GLUT => $this->getRandomPhrase([
                "Suffered operating losses due to a severe global vessel capacity glut and collapsing freight rates.",
                "Excess container tonnage pressured global spot charter rates lower.",
                "Fleet utilisation dropped as oversupply weighed on international shipping margins.",
            ]),
            ShockEvent::SEMICONDUCTOR_FAB_SHORTAGE => $this->getRandomPhrase([
                "Achieved 100% fab capacity utilisation amid a global technological hardware shortage.",
                "Soaring wafer pricing power and fully booked cleanroom lines drove record foundry margins.",
                "High-performance compute demand triggered multi-year advanced node pre-orders.",
            ]),
            ShockEvent::SEMICONDUCTOR_INVENTORY_CORRECTION => $this->getRandomPhrase([
                "Suffered severe margin drag from underutilised cleanrooms during an industry inventory correction.",
                "Customer destocking across consumer electronics reduced wafer shipment volumes.",
                "Faced pricing pressure as downstream partners worked off excess chip inventories.",
            ]),
            ShockEvent::REIT_TENANT_BANKRUPTCIES => $this->getRandomPhrase([
                "Suffered a sudden wave of anchor tenant bankruptcies and commercial lease defaults.",
                "Major commercial portfolio defaults required emergency lease restructurings.",
                "Elevated tenant delinquencies and bad debt provisions weighed on quarterly NOI.",
            ]),
            ShockEvent::GAMING_CRACKDOWN => $this->getRandomPhrase([
                "Gaming regulators restricted high-limit play and the agents who bring in premium players.",
                "New licence conditions closed private salons and capped credit to premium players.",
                "Tighter gaming rules cut table play from premium visitors for the foreseeable future.",
            ]),
            ShockEvent::REIT_ELEVATED_VACANCIES => $this->getRandomPhrase([
                "Elevated commercial vacancies and unpaid rent impacted quarterly NOI.",
                "Leasing velocity slowed as corporate occupiers downsized square footage.",
                "Offering increased tenant improvement allowances squeezed net rental margins.",
            ]),
            ShockEvent::CATASTROPHIC_CLAIM_LOSSES => $this->getRandomPhrase([
                "Suffered catastrophic claim losses from a major systemic disaster.",
                "Record weather and casualty claim events severely impacted quarterly combined ratio.",
                "Reinsurance treaty attachments were triggered following severe insured catastrophe losses.",
            ]),
            ShockEvent::ELEVATED_CLAIM_PAYOUTS => $this->getRandomPhrase([
                "Elevated claim payouts negatively impacted quarterly underwriting margins.",
                "Rising casualty claim severity frequency pressured underwriting profitability.",
                "Saw higher-than-expected insurance loss reserves across commercial lines.",
            ]),
            ShockEvent::PE_CARRIED_INTEREST_SURGE => $this->getRandomPhrase([
                "Generated massive carried interest fees following a series of highly successful portfolio exits.",
                "Landmark portfolio company IPO and strategic sales crystallised substantial carry.",
                "Fund realisations exceeded hurdle rates, driving exceptional performance fee income.",
            ]),
            ShockEvent::PE_HURDLE_RATE_MISSED => $this->getRandomPhrase([
                "Failed to clear the minimum hurdle rate, resulting in zero carried interest crystallisation.",
                "Missed performance targets triggered a complete wipeout of performance fees for the quarter.",
                "Underperforming portfolio assets caused the firm to miss its preferred return hurdle, zeroing out carry.",
            ]),
            ShockEvent::PE_DEAL_DROUGHT => $this->getRandomPhrase([
                "Suffered a severe deal drought as frozen credit markets prevented portfolio exits.",
                "Lack of debt financing stalled leveraged buyout deployment and exit realisations.",
                "Extended portfolio holding periods delayed carried interest crystallisation.",
            ]),
            ShockEvent::HF_QUANT_ALPHA_SURGE => $this->getRandomPhrase([
                "Black-box quantitative trading desks exploited massive market volatility, generating historic stat-arb alpha.",
                "High-frequency algorithmic market-making desks minted record profits amid violent price swings.",
                "Proprietary volatility arbitrage models captured unprecedented spreads across fragmented markets.",
            ]),
            ShockEvent::HF_MARGIN_CALL => $this->getRandomPhrase([
                "Spreading credit contagion and blowing credit spreads triggered severe prime broker margin calls.",
                "Forced de-grossing and fire-sale liquidations locked in steep mark-to-market trading losses.",
                "Prime brokers aggressively hiked collateral haircuts, forcing emergency portfolio de-leveraging.",
            ]),
            ShockEvent::HF_PERFORMANCE_FEE_CRYSTALLIZATION => $this->getRandomPhrase([
                "Crystallised massive 20% incentive fees as flagship funds set new all-time high-water marks.",
                "Explosive trading gains cleared high-water marks, unlocking substantial performance fee allocations.",
                "Exceptional risk-adjusted fund returns triggered historic quarterly incentive fee payouts.",
            ]),
            ShockEvent::HF_DIRECTIONAL_BLOWUP => $this->getRandomPhrase([
                "Heavily leveraged directional bets blew up following an unanticipated macroeconomic regime shift.",
                "Aggressive long/short equity exposure suffered catastrophic drawdown against surging macro headwinds.",
                "Concentrated directional positions collapsed under violent market rotation, wiping out trading equity.",
            ]),
            ShockEvent::DEFENSE_CONTRACT_LOSS => $this->getRandomPhrase([
                "Lost a multi-billion dollar next-generation government defence contract to a rival.",
                "Major aerospace programme cancellation reduced forward backlog significantly.",
                "Budgetary reallocation by defence procurement officials impacted quarterly order flow.",
            ]),
            ShockEvent::DEFENSE_CONTRACT_WIN => $this->getRandomPhrase([
                "Secured a massive, multi-decade international defence contract.",
                "Awarded prime contractor status on a critical next-generation defence platform.",
                "Surging geopolitical demand drove record order intake across munitions and systems.",
            ]),
            ShockEvent::CREDIT_UNSECURED_PROVISIONS => $this->getRandomPhrase([
                "Took massive provisions for unsecured credit defaults as consumer health deteriorated.",
                "Rising consumer delinquency rates required substantial credit card loss reserves.",
                "Wrote down unsecured loan balances amid deteriorating household cash flows.",
            ]),
            ShockEvent::IB_REGULATORY_SETTLEMENT => $this->getRandomPhrase([
                "Faced a major regulatory settlement, adding significant legal and compliance costs to the quarter.",
                "Agreed to a high-profile compliance penalty, weighing heavily on non-interest expenses.",
                "Incurred substantial litigation reserve charges following regulatory inquiries.",
            ]),
            ShockEvent::IB_MNA_LANDMARK_MANDATE => $this->getRandomPhrase([
                "Secured a landmark multi-billion dollar M&A advisory mandate, generating record quarterly fee income.",
                "Led strategic M&A advisory on the quarter's largest megadeal, capturing outsized advisory retainers.",
                "Top-ranked league table advisory performance drove exceptional fee generation.",
            ]),
            ShockEvent::IB_MNA_MANDATE_LOSS => $this->getRandomPhrase([
                "Lost a high-profile advisory mandate to a rival, triggering a sharp advisory revenue drought.",
                "Key advisory clients postponed strategic transactions, depressing investment banking fees.",
                "Experienced a sudden lull in completed advisory mandates.",
            ]),
            ShockEvent::IB_MNA_SYNDICATION_BOOM => $this->getRandomPhrase([
                "Orchestrated a massive wave of corporate mergers and IPO syndications, capturing record advisory fees.",
                "Booming equity capital markets drove strong IPO underwriting and syndication revenue.",
                "Coordinated landmark cross-border financing facilities for major enterprise clients.",
            ]),
            ShockEvent::IB_DCM_UNDERWRITING_BOOM => $this->getRandomPhrase([
                "A sharply steepening yield curve sparked a debt capital markets boom, driving record bond underwriting volumes.",
                "Corporate refinancing wave boosted debt underwriting and origination fees.",
                "Captured dominant market share in institutional bond issuance syndications.",
            ]),
            ShockEvent::IB_PROP_TRADING_SURGE => $this->getRandomPhrase([
                "Proprietary trading desks generated billions in market-making and arbitrage revenue during severe market panic.",
                "Fixed income, currency and commodities trading desks captured record bid-ask spreads.",
                "Macro trading desks capitalised on currency and interest rate volatility.",
            ]),
            ShockEvent::IB_COUNTERPARTY_DEFAULT => $this->getRandomPhrase([
                "Suffered catastrophic prime brokerage losses following the sudden liquidation and default of a highly leveraged family office.",
                "Emergency liquidation of concentrated counterparty swap positions resulted in severe prime brokerage write-downs.",
                "Incurred massive counterparty credit losses as a multi-billion dollar hedge fund client failed to meet margin calls.",
            ]),
            ShockEvent::DISTRESSED_DEBT_RESTRUCTURING => $this->getRandomPhrase([
                "Executed massive restructuring deals on defaulted corporate debt, unlocking extraordinary turnaround gains.",
                "Capitalised on corporate credit spread blowouts by acquiring senior debt at steep discounts.",
                "Orchestrated successful debtor-in-possession restructurings across distressed assets.",
            ]),
            ShockEvent::INFRASTRUCTURE_FAILURE => $this->getRandomPhrase([
                "Suffered a major grid infrastructure failure and catastrophic wildfire liability charges.",
                "Grid failure and wildfire damage claims severely impacted quarterly operating margins.",
                "Faced sudden wildfire liability reserves following catastrophic grid equipment failure.",
            ]),
            ShockEvent::SECURITY_BREACH => $this->getRandomPhrase([
                "Suffered a catastrophic security breach and VIP protection failure, prompting immediate contract terminations.",
                "High-profile tactical failure triggered intense legal scrutiny, lawsuits, and client flight.",
                "Faced severe reputational damage and client cancellations following a publicised security breakdown.",
            ]),
            ShockEvent::GEOPOLITICAL_CONFLICT => $this->getRandomPhrase([
                "Surge in global geopolitical conflict drove record demand for private military extraction and expeditionary security.",
                "Outbreak of regional hostilities triggered an explosion in high-margin expeditionary deployment contracts.",
                "Escalating international tensions fueled massive contract awards for specialised private security forces.",
            ]),
            ShockEvent::LABOR_STRIKE => $this->getRandomPhrase([
                "Fulfilment centre labour strikes and unionisation votes severely disrupted logistics operations.",
                "Mass warehouse worker walkouts and picket lines halted regional package distribution.",
                "Coordinated labour strikes across key fulfilment hubs triggered widespread delivery delays and emergency overtime costs.",
            ]),
            ShockEvent::DOCK_STRIKE => $this->getRandomPhrase([
                "Dock crews walked off the container terminals over pay and shift rosters, idling the cranes.",
                "A strike at the free port's container quays left ships waiting at anchor and lines diverting calls to rival hubs.",
                "Terminal throughput fell sharply while the dockworkers' strike held, with fixed crane and quay costs still running.",
            ]),
            ShockEvent::GENERATION_OUTAGE => $this->getRandomPhrase([
                "A large generating unit tripped and was taken out of service for repairs, cutting output sold into the wholesale market.",
                "Inspectors found a fault at a major station, forcing an extended outage and purchases of replacement power.",
                "An unplanned outage at one of the company's largest units removed output it had already sold forward.",
            ]),
            ShockEvent::GEOPOLITICAL_EXPORT_BAN => $this->getRandomPhrase([
                "Targeted arms export embargoes and mainland export restrictions curtailed foreign shipments.",
                "Geopolitical export licensing bans halted international military platform deliveries.",
                "Foreign military sales were frozen following new mainland defence export sanctions.",
            ]),
            ShockEvent::PROJECT_DELAY => $this->getRandomPhrase([
                "Unanticipated engineering defects and schedule overruns triggered reach-forward losses on fixed-price programmes.",
                "Complex developmental milestone delays and supply chain logjams led to programmatic forward loss charges.",
                "Project delays on fixed-price development contracts resulted in significant cost overrun provisions.",
            ]),

            ShockEvent::CONGLOMERATE_SUBSIDIARY_WRITEDOWN => $this->getRandomPhrase([
                "Multi-subsidiary restructuring charges and goodwill impairments weighed on group operating costs.",
                "Operational bottlenecks across the industrial segment triggered inventory write-offs and severance provisions.",
                "Consolidation of overlapping subsidiary operations incurred significant one-time restructuring costs.",
                "A counterparty failure on a physical cargo book forced provisions against unwound trade receivables.",
            ]),

            ShockEvent::RESERVE_RELEASE => $this->getRandomPhrase([
                "Released credit and loan loss reserves as asset quality outperformed expectations.",
                "Strong macroeconomic tailwinds allowed substantial reversal of previous credit reserves.",
                "Lower non-performing loan formations prompted a quarterly credit reserve release.",
            ]),
            ShockEvent::PERFORMANCE_FEE_SURGE => $this->getRandomPhrase([
                "Surging asset management performance fees drove record quarterly non-interest income.",
                "Outperforming benchmark hurdles unlocked outsized quarterly incentive fee allocations.",
                "Strong fund alpha generation triggered significant performance fee crystallisation.",
            ]),
            ShockEvent::FUND_OUTFLOWS => $this->getRandomPhrase([
                "Experienced elevated institutional fund outflows and redemption pressures.",
                "Asset management AUM contracted following market volatility and client rebalancing.",
                "Net redemption outflows reduced recurring management fee revenues.",
            ]),
            ShockEvent::BANKING_CRISIS => $this->getRandomPhrase(
                [
                    "Banking crisis: With household credit {$context['credit_gap_pct']} points of income above trend, lenders are failing and the credit boom has gone bust.",
                    "Credit boom gone bust: Leverage {$context['credit_gap_pct']} points over its trend has turned into a banking crisis; wholesale funding is running and borrowers are cut off.",
                    "Financial crisis declared: Bank failures spread as the credit build-up of {$context['credit_gap_pct']} points of income unwinds; households and firms deleverage.",
                ],
            ),
            ShockEvent::SYSTEMIC_LIQUIDITY_FREEZE => $this->getRandomPhrase(
                [
                    "Interbank liquidity freeze: Short-term wholesale funding spread spiked to {$context['interbank_spread_bps']} bps, reflecting acute money-market distress.",
                    "Wholesale funding seized: Interbank liquidity spread widened to {$context['interbank_spread_bps']} bps as institutions hoarded cash and restricted counterparty lending.",
                    "Money market distress: Short-term interbank spreads expanded to {$context['interbank_spread_bps']} bps, freezing unsecured wholesale financing.",
                ],
            ),
            ShockEvent::CREDIT_MARKET_SEIZURE => $this->getRandomPhrase(
                [
                    "Credit market seizure: High-yield credit spreads blew out to {$context['hy_spread_pct']}%, shutting speculative-grade primary debt issuance.",
                    "High-yield debt freeze: Speculative corporate bond spreads widened to {$context['hy_spread_pct']}%, halting corporate refinancing.",
                    "Corporate credit freeze: Speculative-grade bond yields surged ({$context['hy_spread_pct']}% spread), locking leveraged borrowers out of debt markets.",
                ],
            ),
            ShockEvent::RECESSION_DECLARED => $this->getRandomPhrase(
                // The quarter's fall comes only with the quarter that made the call; a late report falls back on the
                // rest.
                isset($context['gdp_fall_pct']) ? [
                    "Recession confirmed: Output shrank {$context['gdp_fall_pct']}% last quarter, the second fall in a row, with unemployment at " . $context['unemployment_pct'] . "%.",
                    "Two quarters of contraction: The " . Institutions::STATISTICAL_OFFICE . " declares a recession after output fell {$context['gdp_fall_pct']}% on the quarter; output stands " . $context['output_gap_pct'] . "% against potential.",
                    "Recession confirmed: A second straight quarterly fall in output, down {$context['gdp_fall_pct']}%, puts the economy in recession; the jobless rate is " . $context['unemployment_pct'] . "%.",
                ] : [
                    "Recession confirmed: Macroeconomic indicators signaled sustained output contraction and rising economic slack.",
                    "Technical recession confirmed: Contracting aggregate output and deteriorating labour conditions met formal recession criteria.",
                    "Macroeconomic downturn confirmed: Consecutive periods of output contraction confirmed the economy has entered recession.",
                ],
            ),
            ShockEvent::YIELD_CURVE_INVERSION_ALARM => $this->getRandomPhrase(
                [
                    "Yield curve inversion alert: Benchmark sovereign yield curve has remained inverted for {$context['inversion_months']} consecutive months, signaling heightened recession risk.",
                    "Persistent yield curve inversion: Short rates exceeded long-term sovereign yields for {$context['inversion_months']} months, historically a reliable downturn indicator.",
                    "Yield curve warning: Sustained term spread inversion reached {$context['inversion_months']} months, reflecting tight monetary conditions and future growth deceleration.",
                ],
            ),
            ShockEvent::HOUSEHOLD_DELEVERAGING => $this->getRandomPhrase(
                [
                    "Household deleveraging: With debt service at {$context['dsr_pct']}% of income on debt of {$context['debt_to_income_pct']}% of income, households are repaying rather than spending.",
                    "Credit cycle turns: Debt service reached {$context['dsr_pct']}% of income and household borrowing is now contracting, the bust that follows a credit boom.",
                    "Consumers pull back: Servicing debt at {$context['dsr_pct']}% of income, households cut spending to pay it down; lenders' order books thin.",
                ],
            ),
            ShockEvent::NATURAL_CATASTROPHE => $this->getRandomPhrase(
                [
                    "Natural catastrophe: A storm struck the District with insured losses of {$context['cat_severity']}x an average year's, hitting carriers, landlords and the grid at once.",
                    "Disaster declared: Physical damage across the District ran to {$context['cat_severity']}x an average year's insured losses; rebuilding contracts will follow the claims.",
                    "Catastrophe losses mount: A single event cost {$context['cat_severity']}x a normal year's claims, and every underwriter in the District is exposed to the same one.",
                ],
            ),
            ShockEvent::SOVEREIGN_DOWNGRADE => $this->getRandomPhrase(
                [
                    "Sovereign downgrade: Rating agencies cut the District's credit standing as the fiscal risk premium on its bonds reached {$context['sovereign_spread_bps']} bps with debt at {$context['debt_to_gdp_pct']}% of GDP.",
                    "Fiscal re-rating: The sovereign risk premium widened to {$context['sovereign_spread_bps']} bps, lifting every borrower's cost of funds through the sovereign ceiling.",
                    "Debt sustainability warning: Investors demanded {$context['sovereign_spread_bps']} bps over the risk-free level to hold district debt, and the currency sold off with it.",
                ],
            ),
            ShockEvent::ELECTION_HELD => $this->getRandomPhrase(
                isset($context['majority_party'], $context['largest_seats'], $context['mover']) ? [
                    "Diet election: {$context['majority_party']} wins {$context['largest_seats']} of {$context['diet_seats']} seats, a majority of its own, and will govern alone. The biggest move of the night was {$context['mover']}, {$context['mover_swing_pp']} points.",
                    "Landslide in the Diet: {$context['majority_party']} takes {$context['largest_seats']} seats and needs no partner. {$context['mover']} moved {$context['mover_swing_pp']} points.",
                ] : (isset($context['largest_party'], $context['largest_seats'], $context['mover'], $context['talks_opening'], $context['bloc_leader']) ? [
                    "Diet election: No party holds the {$context['majority_seats']} seats a government needs, so {$context['talks_opening']}. The biggest move of the night was {$context['mover']}, {$context['mover_swing_pp']} points.",
                    "Hung Diet: {$context['largest_party']} leads with {$context['largest_seats']} of {$context['diet_seats']} seats, and the parties begin talks on a government. The outgoing cabinet stays on as caretaker, and policy uncertainty holds at {$context['epu_index']} until a new one takes office.",
                    "The District has voted, and the bargaining begins: {$context['largest_party']} is largest with {$context['largest_seats']} seats, short of the {$context['majority_seats']} it needs. {$context['mover']} moved {$context['mover_swing_pp']} points.",
                    "The blocs are counted: {$context['bloc_leader']}'s side holds {$context['bloc_seats']} of {$context['diet_seats']} seats, and {$context['talks_opening']}.",
                ] : (isset($context['epu_index']) ? [
                    "District election held: Voters returned a Diet for a fresh term, with the policy-uncertainty index at {$context['epu_index']} into the vote.",
                    "Polls closed: A new legislative term begins, with the policy-uncertainty index at {$context['epu_index']}.",
                ] : [
                    "District election held: Voters returned a Diet for a fresh term.",
                    "Polls closed: A new legislative term begins.",
                ])),
            ),
            ShockEvent::GOVERNMENT_FELL => $this->getRandomPhrase(
                isset($context['fallen_cabinet'], $context['fallen_months'], $context['talks_lead']) ? (($context['fallen_support'] ?? '') !== '' ? [
                    "Government falls: The minority cabinet of {$context['fallen_cabinet']} loses the Diet after {$context['fallen_months']} months, as the support of {$context['fallen_support']} gives way. It stays on as caretaker while {$context['talks_lead']} opens talks on a new cabinet; no election is called.",
                    "Cabinet collapses: Without the votes of {$context['fallen_support']}, the cabinet of {$context['fallen_cabinet']} can no longer carry a budget through the Diet. The parties talk again on the seats they hold, and policy uncertainty holds at {$context['epu_index']}.",
                ] : [
                    "Coalition breaks up: The cabinet of {$context['fallen_cabinet']} falls after {$context['fallen_months']} months. It stays on as caretaker while {$context['talks_lead']} opens talks on a new cabinet; the next vote stays on the calendar.",
                    "Government falls: The partners in the cabinet of {$context['fallen_cabinet']} part ways after {$context['fallen_months']} months, and the Diet's parties return to the table with no election called.",
                ]) : [
                    "Government falls: The cabinet has lost the Diet and stays on as caretaker while the parties talk; no election is called.",
                ],
            ),
            ShockEvent::GOVERNMENT_FORMED => $this->getRandomPhrase(
                isset($context['cabinet'], $context['talk_days'], $context['minority']) ? ($context['minority'] === 'yes' ? [
                    "Government formed: After {$context['talk_days']} days of talks, {$context['lead_party']} forms a minority cabinet of {$context['cabinet']} with {$context['cabinet_seats']} seats, governing with the support of {$context['support']} ({$context['supported_seats']} of {$context['diet_seats']}).",
                    "Minority government takes office {$context['attempts_phrase']}: {$context['cabinet']} will govern on {$context['cabinet_seats']} seats, with {$context['support']} backing its budgets from outside the cabinet.",
                ] : [
                    "Government formed: After {$context['talk_days']} days of talks, a cabinet of {$context['cabinet']} takes office with {$context['cabinet_seats']} of {$context['diet_seats']} seats.",
                    "Coalition agreed {$context['attempts_phrase']}: {$context['cabinet']} will govern with {$context['cabinet_seats']} seats, ending {$context['talk_days']} days of caretaker government.",
                ]) : [
                    "Government formed: A new cabinet has taken office after the coalition talks.",
                ],
            ) . (isset($context['cabinet']) && ($context['prime_minister'] ?? '') !== '' ? " {$context['prime_minister']} is prime minister." : ''),
            ShockEvent::BUDGET_ENACTED => $this->getRandomPhrase(
                isset($context['government'], $context['tax_rate_pct'], $context['tariff_pct']) ? ([

                    "Budget passed: The {$context['government']} government sets the corporate rate at {$context['tax_rate_pct']}% and the average tariff at {$context['tariff_pct']}%, with its immigration rules growing the labour force {$context['labor_growth_pct']}% a year.",
                    "The Diet votes the budget: Firms will pay {$context['tax_rate_pct']}% on their profits and importers a {$context['tariff_pct']}% average duty under the {$context['government']} government.",
                    ...(($context['carbon_price'] ?? '0.00') !== '0.00' ? [
                        "Budget passed: The {$context['government']} government prices carbon at \${$context['carbon_price']} a tonne, with the corporate rate at {$context['tax_rate_pct']}%.",
                    ] : []),
                ]) : [
                    "Budget passed: The Diet has voted the government's budget.",
                ],
            ),
            ShockEvent::GOVERNOR_APPOINTED => $this->getRandomPhrase(
                isset($context['governor'], $context['governor_age'], $context['governor_stance'], $context['governor_passed_over']) ? [
                    "New governor: The Council names {$context['governor']}, {$context['governor_age']}, {$context['governor_stance']}, to lead the Monetary Authority until {$context['governor_term_ends']}, passing over {$context['governor_passed_over']}. The governor will fill the rate committee's seats as they fall vacant.",
                    "The Council picks its central banker: {$context['governor']}, {$context['governor_stance']}, takes the Monetary Authority for a single term ending {$context['governor_term_ends']}, chosen over {$context['governor_passed_over']}.",
                ] : [
                    "New governor: The Council has named a new governor of the Monetary Authority.",
                ],
            ),
            ShockEvent::FUND_HEAD_APPOINTED => $this->getRandomPhrase(
                isset($context['fund_head'], $context['fund_head_age'], $context['fund_head_stance'], $context['fund_mix_change']) ? [
                    "New head for the reserves: The Council names {$context['fund_head']}, {$context['fund_head_age']}, {$context['fund_head_stance']}, to run the Sovereign Reserve Fund until {$context['fund_head_term_ends']}. The fund {$context['fund_mix_change']}." . ($context['fund_head_passed_over'] !== '' ? " Passed over: {$context['fund_head_passed_over']}." : ''),
                    "The Council picks who runs the reserves: {$context['fund_head']}, {$context['fund_head_stance']}, takes the Sovereign Reserve Fund for a single term, and the fund {$context['fund_mix_change']}.",
                ] : [
                    "New head for the reserves: The Council has named a new head of the Sovereign Reserve Fund.",
                ],
            ),
            ShockEvent::EXCHEQUER_HEAD_APPOINTED => $this->getRandomPhrase(
                isset($context['exchequer_head'], $context['exchequer_head_age'], $context['exchequer_head_stance'], $context['debt_maturity_change']) ? [
                    "New head at the Exchequer: The Council names {$context['exchequer_head']}, {$context['exchequer_head_age']}, {$context['exchequer_head_stance']}, to fund the District's debt until {$context['exchequer_head_term_ends']}. The Exchequer {$context['debt_maturity_change']}." . ($context['exchequer_head_passed_over'] !== '' ? " Passed over: {$context['exchequer_head_passed_over']}." : ''),
                    "The Council picks who funds the debt: {$context['exchequer_head']}, {$context['exchequer_head_stance']}, takes the Exchequer for a single term, and it {$context['debt_maturity_change']}.",
                ] : [
                    "New head at the Exchequer: The Council has named a new head of the Exchequer.",
                ],
            ),
            ShockEvent::FAIR_TRADING_HEAD_APPOINTED => $this->getRandomPhrase(
                isset($context['fair_trading_head'], $context['fair_trading_head_age'], $context['fair_trading_head_stance'], $context['merger_review_change']) ? [
                    "New head at the Fair Trading Board: The Council names {$context['fair_trading_head']}, {$context['fair_trading_head_age']}, {$context['fair_trading_head_stance']}, to review the District's mergers until {$context['fair_trading_head_term_ends']}. The Board {$context['merger_review_change']}." . ($context['fair_trading_head_passed_over'] !== '' ? " Passed over: {$context['fair_trading_head_passed_over']}." : ''),
                    "The Council picks who polices concentration: {$context['fair_trading_head']}, {$context['fair_trading_head_stance']}, takes the Fair Trading Board for a single term, and it {$context['merger_review_change']}.",
                ] : [
                    "New head at the Fair Trading Board: The Council has named a new head of the Fair Trading Board.",
                ],
            ),
            ShockEvent::SECURITIES_HEAD_APPOINTED => $this->getRandomPhrase(
                isset($context['securities_head'], $context['securities_head_age'], $context['securities_head_stance'], $context['securities_rules_change']) ? [
                    "New head at the Securities Commission: The Council names {$context['securities_head']}, {$context['securities_head_age']}, {$context['securities_head_stance']}, to oversee the Aerie Exchange until {$context['securities_head_term_ends']}. From {$context['securities_rules_from']} the Commission {$context['securities_rules_change']}." . ($context['securities_head_passed_over'] !== '' ? " Passed over: {$context['securities_head_passed_over']}." : ''),
                    "The Council picks who polices the Exchange: {$context['securities_head']}, {$context['securities_head_stance']}, takes the Securities Commission for a single term, and from {$context['securities_rules_from']} it {$context['securities_rules_change']}.",
                ] : [
                    "New head at the Securities Commission: The Council has named a new head of the Securities Commission.",
                ],
            ),
            ShockEvent::SECURITY_DIRECTOR_APPOINTED => $this->getRandomPhrase(
                isset($context['security_director'], $context['security_director_age'], $context['security_director_term_ends']) ? [
                    "New director at the Security Bureau: The Council names {$context['security_director']}, {$context['security_director_age']}, to lead the District's fight against organised crime and foreign agents until {$context['security_director_term_ends']}." . ($context['security_director_passed_over'] !== '' ? " Passed over: {$context['security_director_passed_over']}." : ''),
                    "The Council picks the District's chief investigator: {$context['security_director']} takes the Security Bureau for a single ten-year term, ending {$context['security_director_term_ends']}.",
                ] : [
                    "New director at the Security Bureau: The Council has named a new director of the Security Bureau.",
                ],
            ),
            ShockEvent::COAST_GUARD_COMMANDANT_APPOINTED => $this->getRandomPhrase(
                isset($context['coast_guard_commandant'], $context['coast_guard_commandant_age'], $context['coast_guard_commandant_term_ends']) ? [
                    "New commandant at the Coast Guard: The Council names {$context['coast_guard_commandant']}, {$context['coast_guard_commandant_age']}, to command the District's waters until {$context['coast_guard_commandant_term_ends']}." . ($context['coast_guard_commandant_passed_over'] !== '' ? " Passed over: {$context['coast_guard_commandant_passed_over']}." : ''),
                    "The Council picks who holds the sounds and the shelf: {$context['coast_guard_commandant']} takes command of the Coast Guard for a single term, ending {$context['coast_guard_commandant_term_ends']}.",
                ] : [
                    "New commandant at the Coast Guard: The Council has named a new commandant of the Coast Guard.",
                ],
            ),
            ShockEvent::STATE_GUARD_COMMANDER_APPOINTED => $this->getRandomPhrase(
                isset($context['state_guard_commander'], $context['state_guard_commander_age'], $context['state_guard_commander_term_ends']) ? [
                    "New commander at the State Guard: The Council names {$context['state_guard_commander']}, {$context['state_guard_commander_age']}, to command the District's standing force until {$context['state_guard_commander_term_ends']}." . ($context['state_guard_commander_passed_over'] !== '' ? " Passed over: {$context['state_guard_commander_passed_over']}." : ''),
                    "The Council picks the commander it alone can order out: {$context['state_guard_commander']} takes the State Guard for a single term, ending {$context['state_guard_commander_term_ends']}.",
                ] : [
                    "New commander at the State Guard: The Council has named a new commander of the State Guard.",
                ],
            ),
            ShockEvent::REGULATOR_APPOINTED => $this->getRandomPhrase(
                isset($context['regulator'], $context['regulator_age'], $context['regulator_stance'], $context['requirement_change']) ? [
                    "New bank regulator: The Council names {$context['regulator']}, {$context['regulator_age']}, {$context['regulator_stance']}, to head the Financial Regulator until {$context['regulator_term_ends']}. The new head {$context['requirement_change']}." . ($context['regulator_passed_over'] !== '' ? " Passed over: {$context['regulator_passed_over']}." : ''),
                    "The Council picks its bank supervisor: {$context['regulator']}, {$context['regulator_stance']}, takes the Financial Regulator for a single term and {$context['requirement_change']}.",
                ] : [
                    "New bank regulator: The Council has named a new head of the Financial Regulator.",
                ],
            ),
            ShockEvent::AUTHORITY_MAJORITY_SHIFT => $this->getRandomPhrase(
                isset($context['committee_majority'], $context['committee_counts'], $context['governor']) ? match ($context['committee_majority']) {
                    'hawkish' => [
                        "Rate committee turns hawkish: With {$context['committee_counts']}, Governor {$context['governor']}'s committee now holds a hawkish majority, slower to cut when the economy slackens.",
                        "Hawks take the Monetary Authority: The committee's newest seat gives {$context['committee_counts']} on the panel, and a majority that would rather hold rates up through a slowdown.",
                    ],
                    'dovish' => [
                        "Rate committee turns dovish: With {$context['committee_counts']}, Governor {$context['governor']}'s committee now holds a dovish majority, slower to answer rising prices.",
                        "Doves take the Monetary Authority: The committee's newest seat gives {$context['committee_counts']} on the panel, and a majority readier to let inflation run.",
                    ],
                    default => [
                        "Rate committee rebalanced: With {$context['committee_counts']}, neither hawks nor doves now hold sway on Governor {$context['governor']}'s committee.",
                    ],
                } : [
                    "Rate committee rebalanced: The Monetary Authority's committee has a new balance of hawks and doves.",
                ],
            ),
            ShockEvent::PRIME_MINISTER_CHANGED => $this->getRandomPhrase(
                isset($context['leader'], $context['leader_age'], $context['leader_party'], $context['outgoing_leader'], $context['outgoing_led']) ? [
                    "New prime minister: {$context['outgoing_leader']} steps down as leader of {$context['leader_party']} after {$context['outgoing_led']}, and the party's choice, {$context['leader']}, {$context['leader_age']}, takes over the government. No election is called; the cabinet and its budget stand.",
                    "{$context['leader']} is prime minister: {$context['leader_party']} has a new leader, {$context['leader_age']}, in place of {$context['outgoing_leader']}, who led the party for {$context['outgoing_led']}. The premiership passes with the party's lead, without a vote.",
                ] : [
                    "New prime minister: The governing party has a new leader, who takes over the government without an election.",
                ],
            ),
            ShockEvent::PARTY_LEADER_CHANGED => $this->getRandomPhrase(
                isset($context['leader'], $context['leader_age'], $context['leader_party'], $context['outgoing_leader'], $context['outgoing_led']) ? [
                    "New leader: {$context['leader']}, {$context['leader_age']}, takes over {$context['leader_party']} from {$context['outgoing_leader']}, who led it for {$context['outgoing_led']}.",
                    "Change at the top of {$context['leader_party']}: {$context['outgoing_leader']} steps down after {$context['outgoing_led']} as leader, and the party chooses {$context['leader']}, {$context['leader_age']}.",
                ] : [
                    "New leader: One of the Diet's parties has chosen a new leader.",
                ],
            ),
            ShockEvent::COUNCILLOR_SEATED => $this->getRandomPhrase(
                isset($context['councillor'], $context['councillor_age'], $context['councillor_stance'], $context['predecessor'], $context['vacancy_cause'], $context['councillor_term_ends']) ? [
                    ($context['vacancy_cause'] === 'died' ? "Council vacancy filled: After the death of {$context['predecessor']}" : "Council vacancy filled: After the resignation of {$context['predecessor']}") . ", the councillors elect {$context['councillor']}, {$context['councillor_age']}, {$context['councillor_stance']}, to serve out the term to {$context['councillor_term_ends']}.",
                    "{$context['councillor']} joins the Aerie Council in the seat {$context['predecessor']} " . ($context['vacancy_cause'] === 'died' ? 'held until their death' : 'gave up') . "; the newcomer, {$context['councillor_age']}, is {$context['councillor_stance']} on money and sits until {$context['councillor_term_ends']}.",
                ] : (isset($context['councillor'], $context['councillor_age'], $context['councillor_stance'], $context['councillor_passed_over']) ? [
                    "Council succession: The sitting councillors elect {$context['councillor']}, {$context['councillor_age']}, {$context['councillor_stance']}, to a " . (int) AerieCouncil::TERM_YEARS . "-year seat, passing over {$context['councillor_passed_over']}.",
                    "{$context['councillor']} joins the Aerie Council: elected by its members to succeed a retiring colleague, the newcomer is {$context['councillor_stance']} on money.",
                ] : [
                    "Council succession: The Aerie Council has elected a new member.",
                ]),
            ),
            ShockEvent::MONETARY_DECISION => $this->getRandomPhrase(
                isset($context['rate_move'], $context['vote_split'], $context['dissent_phrase']) ? [
                    "Monetary Authority {$context['rate_move']}: The committee voted {$context['vote_split']}; {$context['dissent_phrase']}.",
                    "Rate decision: Governor {$context['governor']}'s committee {$context['rate_move']} on a {$context['vote_split']} vote, and {$context['dissent_phrase']}.",
                ] : [
                    "Rate decision: The Monetary Authority's committee has set the policy rate.",
                ],
            ),
            ShockEvent::TITAN_INTERVENTION => $this->getRandomPhrase([
                "Bond purchases begin: With its rate at the floor, the " . Institutions::MONETARY_AUTHORITY . " is buying bonds to bring long-term yields down and ease credit; output stands {$context['output_gap_pct']}% against potential.",
                "Emergency easing: The " . Institutions::MONETARY_AUTHORITY . " has begun large-scale bond purchases to steady credit markets and lower borrowing costs, with output {$context['output_gap_pct']}% against potential.",
                "Liquidity support: The " . Institutions::MONETARY_AUTHORITY . " is buying assets to support liquidity and counter the downturn; output stands {$context['output_gap_pct']}% against potential.",
            ]),
            ShockEvent::SOVEREIGN_WEALTH_DEPLOYMENT => $this->getRandomPhrase(
                [
                    "Sovereign Reserve Fund rebalances into equities: Its board holding fell to {$context['swf_weight_pct']}% of the fund against a {$context['swf_target_pct']}% policy weight, so it will buy about {$context['swf_trade_pct']}% of the free float over the next {$context['swf_months']} months.",
                    "Rule-based buying: The Sovereign Reserve Fund's domestic equities slipped to {$context['swf_weight_pct']}% of the fund (policy weight {$context['swf_target_pct']}%), triggering purchases of roughly {$context['swf_trade_pct']}% of the float spread over {$context['swf_months']} months.",
                    "Reserve fund tops up the board: With domestic equities at {$context['swf_weight_pct']}% of a fund worth " . $context['swf_size_gdp_pct'] . "% of GDP, the Sovereign Reserve Fund will add about {$context['swf_trade_pct']}% of the float to return to {$context['swf_target_pct']}%.",
                ],
            ),
            ShockEvent::SOVEREIGN_WEALTH_TRIM => $this->getRandomPhrase(
                [
                    "Sovereign Reserve Fund trims equities: Its board holding rose to {$context['swf_weight_pct']}% of the fund against a {$context['swf_target_pct']}% policy weight, so it will sell about {$context['swf_trade_pct']}% of the free float over the next {$context['swf_months']} months.",
                    "Rule-based selling: The Sovereign Reserve Fund's domestic equities climbed to {$context['swf_weight_pct']}% of the fund (policy weight {$context['swf_target_pct']}%), triggering sales of roughly {$context['swf_trade_pct']}% of the float spread over {$context['swf_months']} months.",
                    "Reserve fund takes profits by rule: With domestic equities up to {$context['swf_weight_pct']}% of a fund worth " . $context['swf_size_gdp_pct'] . "% of GDP, the Sovereign Reserve Fund will sell about {$context['swf_trade_pct']}% of the float to return to {$context['swf_target_pct']}%.",
                ],
            ),
            ShockEvent::PE_LEVERAGE_RECAPITALIZATION => $this->getRandomPhrase([
                "Executed dividend recapitalisations across private equity portfolio assets, unlocking substantial liquidity.",
                "Completed opportunistic debt refinancing and special dividend payouts across core portfolio holdings.",
                "Optimised capital structure across buyout assets to return cash to fund LPs.",
            ]),
            ShockEvent::PE_PORTFOLIO_MARKDOWN => $this->getRandomPhrase([
                "Marked down private equity portfolio company valuations amid multiple compression.",
                "Unrealised fair-value adjustments across technology and growth investments weighed on portfolio net worth.",
                "Weakened public comps required conservative valuation markdowns across private holdings.",
            ]),
            ShockEvent::GEOPOLITICAL_SANCTIONS => $this->getRandomPhrase([
                "Sweeping international trade sanctions disrupted global business lines and contract fulfilment.",
                "Imposition of cross-border trade restrictions halted key international shipment routes.",
                "Compliance mandates and sanctions enforcement restricted access to foreign enterprise markets.",
            ]),
            ShockEvent::REINSURANCE_ATTACHMENT_BREACH => $this->getRandomPhrase([
                "Severe catastrophe losses breached reinsurance attachment points, transferring risk to retrocessionaires.",
                "Excess-of-loss reinsurance claims exceeded primary retention layers.",
                "Industry-wide catastrophe events exhausted primary treaty deductibles.",
            ]),
            ShockEvent::LITIGATION_SETTLEMENT_WIN => $this->getRandomPhrase([
                "Secured a favourable legal settlement, unlocking substantial contingency fee income.",
                "Landmark patent infringement verdict awarded significant damages to the firm.",
                "Resolution of major class-action claims resulted in outsized legal contingency revenues.",
            ]),
            ShockEvent::LITIGATION_SETTLEMENT_LOSS => $this->getRandomPhrase([
                "Suffered an adverse litigation verdict, requiring significant legal liability provisions.",
                "Court ruling against the firm triggered substantial damage awards and settlement charges.",
                "Unanticipated legal liability findings increased quarterly litigation reserves.",
            ]),
            ShockEvent::LOGISTICS_SURGE_PRICING => $this->getRandomPhrase([
                "Tight freight capacity enabled dynamic surge pricing across core shipping lanes.",
                "Peak season carrier capacity constraints drove record contractual and spot freight yields.",
                "High lane utilisation and expedited freight premiums expanded logistics operating margins.",
            ]),
            ShockEvent::COMPONENT_SHORTAGE => $this->getRandomPhrase([
                "Shortages of chips and parts at suppliers held back shipments, leaving orders unfilled at the quarter's end.",
                "Suppliers rationed scarce components, and finished goods waited on the last parts to arrive.",
                "Parts shortages slowed assembly lines and pushed deliveries into the next quarter.",
            ]),
            ShockEvent::INDUSTRIAL_ORDER_SURGE => $this->getRandomPhrase([
                "Factory customers stepped up tooling orders as plants added shifts.",
                "Busy production lines wore through cutting tools and consumables faster, lifting replacement orders.",
                "Manufacturers restocked tooling ahead of new production runs, filling the order book.",
            ]),
            ShockEvent::MAJOR_EQUIPMENT_ORDER => $this->getRandomPhrase([
                "Won the equipment package for a major tunnelling and transit project.",
                "Booked a multi-year order for the machinery behind a large power and water scheme.",
                "Secured the main equipment contract for a new industrial plant, adding years of work to the order book.",
            ]),
            ShockEvent::DATA_CENTRE_ORDER_SURGE => $this->getRandomPhrase([
                "Server and storage orders surged as large customers expanded their data centres.",
                "Cloud and enterprise buyers placed record orders for computing and storage hardware.",
                "A wave of data centre construction filled the order book for high-end servers.",
            ]),
            ShockEvent::INFRASTRUCTURE_BILL_WIN => $this->getRandomPhrase([
                "Secured landmark public infrastructure grants and public-private partnership concessions.",
                "Awarded multi-billion dollar municipal transportation and civil works construction contracts.",
                "National infrastructure spending legislation unlocked significant forward project backlog.",
            ]),
            ShockEvent::ENVIRONMENTAL_DISASTER => $this->getRandomPhrase([
                "Remediation and cleanup liabilities following an environmental incident weighed on earnings.",
                "Regulatory fines and remediation mandates following a containment leak increased operating costs.",
                "Incurred significant environmental compliance charges and containment expenses.",
            ]),
            ShockEvent::TELECOM_PRICE_WAR => $this->getRandomPhrase([
                "Deep price cuts and promotional discounting eroded margins across telecom tiers.",
                "Intense competitive discounting and unlimited plan promotions compressed subscriber ARPU.",
                "Subscriber retention promotions and device subsidy wars squeezed telecom service margins.",
            ]),
            ShockEvent::SPECTRUM_AUCTION => $this->getRandomPhrase([
                "Secured prime 5G spectrum licences in a competitive government auction, expanding network reach.",
                "Acquired high-capacity mid-band spectrum blocks to expand mobile broadband leadership.",
                "Strategic spectrum licence acquisitions reinforced long-term wireless network capacity.",
            ]),
            ShockEvent::NETWORK_ROLLOUT_WAVE => $this->getRandomPhrase([
                "Carrier customers launched a generational network rollout, stepping up radio access equipment order intake.",
                "Operators committed multi-year deployment budgets for next-generation base stations and core routing.",
                "A new wireless generation entered mass deployment, lifting the network equipment backlog.",
            ]),
            ShockEvent::SEP_ROYALTY_DISPUTE => $this->getRandomPhrase([
                "A flagship device maker withheld standard-essential patent royalties pending licence renegotiation.",
                "A major licensee halted royalty payments and challenged the patent portfolio's licensing terms in court.",
                "Royalty collections fell as a leading handset manufacturer disputed standard-essential patent rates.",
            ]),
            ShockEvent::SEP_ROYALTY_SETTLEMENT => $this->getRandomPhrase([
                "Settled the patent licensing dispute, collecting most of the withheld royalty arrears in a catch-up payment.",
                "A renewed multi-year licence agreement released back royalties accrued during the dispute.",
                "Resolution of the licensing litigation restored royalty flows and recovered accumulated arrears.",
            ]),
            ShockEvent::FLIGHT_CREW_STRIKE => $this->getRandomPhrase([
                "A pilots' strike grounded part of the fleet, cancelling flights and refunding tickets already sold.",
                "Cabin crew walked out over pay, forcing the airline to cancel a share of its schedule for two weeks.",
                "Flight cancellations during a crew strike cut passenger and cargo revenue before a new contract was agreed.",
            ]),
            ShockEvent::CARRIAGE_BLACKOUT => $this->getRandomPhrase([
                "A carriage fee dispute took the network's channels off a major distributor's system for part of the quarter.",
                "Viewers on a large cable and broadband system lost the channels while the carriage contract was renegotiated.",
                "Carriage fees and advertising audience fell during a blackout on a leading distributor before a new deal was signed.",
            ]),
            ShockEvent::MANDATORY_HEALTHCARE_EXPANSION => $this->getRandomPhrase([
                "Mandatory healthcare coverage expansions and higher patient admissions boosted operating volumes.",
                "Expanded statutory health insurance coverage drove higher patient utilisation across medical centres.",
                "Increased healthcare enrolment supported robust inpatient and outpatient procedure growth.",
            ]),
            ShockEvent::HEALTHCARE_AUDIT_CLAWBACK => $this->getRandomPhrase([
                "Regulatory billing audits and reimbursement clawbacks reduced net medical service revenue.",
                "Statutory public health reimbursement revisions resulted in retrospective revenue adjustments.",
                "Payer claims review findings necessitated reserves for disputed clinical billing codes.",
            ]),
            ShockEvent::APPAREL_SUPPLY_CHAIN_DISRUPTION => $this->getRandomPhrase([
                "Port logjams and fabric supply disruptions delayed seasonal apparel collections.",
                "Textile import bottlenecks and transit delays required costly airfreight expedited shipping.",
                "Supply logjams led to inventory stockouts across high-demand seasonal product lines.",
            ]),
            ShockEvent::APPAREL_VIRAL_PRODUCT => $this->getRandomPhrase([
                "Viral social media momentum and influencer adoption triggered an explosive sell-through of flagship fashion lines.",
                "Breakthrough viral demand drove record full-price sell-through rates across core apparel collections.",
                "Culturally trending footwear and apparel releases sold out instantly across digital and retail channels.",
            ]),
            ShockEvent::CHEMICAL_CRACK_SPREAD_SQUEEZE => $this->getRandomPhrase([
                "Surging hydrocarbon feedstock costs squeezed petrochemical crack spreads.",
                "Elevated natural gas and crude input pricing compressed chemical processing margins.",
                "Upstream commodity inflation narrowed refining and cracking unit margins.",
            ]),
            ShockEvent::CHEMICAL_AGRI_BOOM => $this->getRandomPhrase([
                "Surging global fertiliser demand and agricultural commodity prices drove outsized specialty chemical margins.",
                "Strong crop nutrient pricing and international agronomic demand expanded chemical operating earnings.",
                "High fertiliser utilisation and agricultural input pricing power drove record specialty chemical profits.",
            ]),

            default => "Experienced an unexpected market event.",
        };
    }

    /**
     * One of the story's phrasings, on one draw from the simulation's stream.
     *
     * @param array<string> $phrases
     */
    private function getRandomPhrase(array $phrases): string
    {
        $phrases = array_values($phrases);

        return $phrases[$this->math->generateIndex(count($phrases))];
    }
}
