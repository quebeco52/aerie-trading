<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Long-form company profiles, written in-world by Tickbird Research for terminal subscribers.
 *
 * StockInfo::DESCRIPTIONS is the exchange's short business summary; these carry the history, the people and the
 * relationships between firms. Lore sets facts, never the model: every figure an article quotes is pinned to the
 * seed data or StockModelTuning by CompanyResearchTest. Current executives go unnamed, since the board replaces
 * them during play; founders and past figures may be named.
 */
final class CompanyResearch
{
    /**
     * Ticker => article. `related` lists every listed company the text names, so the page can link them.
     *
     * @var array<string, array{headline: string, standfirst: string, sections: list<array{heading: string, body: string}>, related: list<string>}>
     */
    public const ARTICLES = [

        'LAKE' => [
            'headline' => 'Lakebird Bank: the balance sheet the District was built on',
            'standfirst' => 'The District\'s largest lender holds most of its deposits, publishes its benchmark index and owns part of the companies it once lent to. Its shareholders are, in practice, holding the District.',
            'sections' => [
                [
                    'heading' => 'Born in a merger',
                    'body' => "The District began as a proposal nobody wanted. The mainland was deep in a bust that showed no sign of ending: banks were failing faster than they could be wound up, and the funds that might have bought their assets were short of money themselves. Leonard Ashcombe, an economist in the mainland's finance department, proposed a remedy his colleagues thought unserious, a self-governing enclave on the coast with light rules and low taxes, where capital would return because it was allowed to. He put it to government and to private industry, and both turned him down.\n\nThe proposal was about to be shelved when the Breakwater family agreed to put their name to it. Their Breakwater Trust already owned the foundries and deepwater berths on the peninsula, and with the family behind it a coalition of banks with nothing left to lose signed on. A business-friendly government on the mainland agreed to try it. The banks' condition for entry was that they merge, and Lakebird is the result: more than a dozen lenders combined into one, bringing their deposits, their branches and their bad loans with them.\n\nThe shares issued in the merger went into founders' trusts that the Charter barred from selling for a generation, and most were kept after the bar lapsed. About half the bank is still held that way and almost never changes hands, so the float is small for a bank of its size and the shares can move further on a large order than the balance sheet would suggest. Ashcombe was given no stake and asked for none. His portrait hangs in the Lakebird boardroom, one floor below the founders'.",
                ],
                [
                    'heading' => 'The Syndicate',
                    'body' => "The bank's habit of turning bad loans into ownership dates from its first years, when the merged banks' problem loans were still on its books. When industrial and logistics borrowers could not repay, Lakebird swapped the debt for equity rather than writing it off, and kept the stakes. The companies it collected are known as the Lakebird Syndicate, and their dividends now reach the bank as a steady fifth of its revenue. On Glasswater Row it is said that a Syndicate board meets twice, once in its own offices and once on the fourteenth floor at Lakebird.\n\nThe same instinct runs through its other relationships. Lakebird and Black Swan together own a majority of Iron Beak, the contractor on most of the civic projects the bank finances. The bank sponsored Plaza Civic River Trust, which carries much of the municipal borrowing it arranges, and few analysts treat the trust as independent. Several of Condor Extraction's foreign mining concessions came from governments that defaulted on Lakebird loans and surrendered the land they had pledged.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "Most of the profit is the spread between what Lakebird pays depositors and what it earns on loans and securities. Residential mortgages make up close to a third of the loan book; the rest is consumer credit, commercial property and the syndicated corporate loans the bank leads for the District's largest companies. Fees from payments, payroll and the licence for the Lakebird 30 index add a further fifth of revenue.\n\nBy District standards the bank is run conservatively. It holds capital well above the Financial Regulator's requirement, pays out about 40% of earnings and keeps the rest for the next downturn. Plover Savings Bank is its only real competitor for mortgages, and Brine Pool Capital lends where Lakebird is held back by the Regulator's capital rules.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "Lakebird's loans reach most of the District economy, so its losses arrive at the same time as everyone else's. The shares have fallen hardest when rates rose quickly into a slowdown, flattening the yield curve and lifting defaults together.\n\nThe bank has never needed outside capital. Shareholders say this as reassurance. Regulators have been heard to say it as a worry, since nobody in the District is large enough to supply it if it ever did.",
                ],
            ],
            'related' => ['BRKW', 'SWAN', 'IBHI', 'PLZA', 'CNDR', 'PLVR', 'POOL'],
        ],

        'SWAN' => [
            'headline' => 'Black Swan Capital: the shareholder boards check for first',
            'standfirst' => 'The District\'s largest hedge fund manager was assembled from the funds that helped found it. It takes new money by invitation only, and tends to appear in a company\'s debt before it appears on the share register.',
            'sections' => [
                [
                    'heading' => 'The other half of the coalition',
                    'body' => "Black Swan was formed the same way as Lakebird Bank, from the other half of the coalition that carried Leonard Ashcombe's proposal to the mainland government once the Breakwater family had put their name to it. The bust had starved the hedge funds: investors had pulled their money, prime brokers had cut their lending, and several of the funds were weeks from closing. Apart, none of them could afford the move to an untried jurisdiction. Together they had enough capital to be taken seriously, so they merged into one manager and opened on Glasswater Row in the District's first year.\n\nThe partners of the merged funds took shares for their stakes, and the founding partners still hold about half the shares. The Obsidian Desk was once a fund of its own, the smallest in the merger and the one the others had least wanted to take; within a decade it was the part of the firm everyone on Glasswater Row knew by name. The firm says its own name refers to the improbable events its funds are built to profit from. Its targets say it describes how the firm prefers to arrive.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "Management fees on its funds bring in about 60% of revenue, and they are paid whatever the markets do. The rest comes from the funds' own results: statistical arbitrage books that trade District shares at high frequency, and directional positions, which are where the large performance fees are earned.\n\nPerformance fees are booked when a position closes, so earnings jump in the quarter a takeover completes or a restructuring pays out. The shares carry a beta well above the market's and react to the firm's deal calendar more than to its funds' monthly figures.",
                ],
                [
                    'heading' => 'The Obsidian Desk',
                    'body' => "The Obsidian Desk runs the firm's activist campaigns. Its method has changed little in the District's history. It builds a position through offshore vehicles and total return swaps, which do not have to be disclosed until late, then buys the target's senior loans, usually from banks glad to be rid of them. By the time a board learns who it is dealing with, Black Swan is often its largest creditor as well as a large shareholder.\n\nThe Desk then publishes its case, funds litigation if it needs to, and offers to drop both if management changes or a subsidiary is sold. Most boards settle. The ones that did not are the source of the firm's reputation: several went through loan-to-own restructurings that ended with Black Swan in control. District boards plan their balance sheets with the Desk in mind, since surplus cash and unused borrowing capacity are what it looks for first.",
                ],
                [
                    'heading' => 'Allies and neighbours',
                    'body' => "Black Swan and Lakebird jointly own a majority of Iron Beak, which builds the property redevelopments Black Swan leads, and loans from the two firms to foreign governments are how Condor Extraction came to hold many of its concessions. Corvid Capital is widely believed to sell research to the Obsidian Desk, which neither firm confirms. Vulture Capital Recovery frequently buys what is left of a company after a campaign has finished with it.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "The firm's earnings are only as good as its last few campaigns. In a long calm market there are fewer mispriced companies and fewer distressed ones, management fees carry the results and the shares drift. A credit crisis is the opposite case, which is why the shares have at times risen while the rest of the board fell. The risk subscribers ask about most is a campaign that fails in public while the firm holds a large block of the target's debt.",
                ],
            ],
            'related' => ['LAKE', 'IBHI', 'CNDR', 'CORV', 'VULT'],
        ],

        'SAFE' => [
            'headline' => 'Safe Harbor Reinsurance: the backstop',
            'standfirst' => 'Every balance sheet in the District assumes that the worst losses will be absorbed somewhere. Safe Harbor is where they go, and nobody stands behind it.',
            'sections' => [
                [
                    'heading' => 'A condition of the Charter',
                    'body' => "When the mainland government agreed to try Leonard Ashcombe's proposal, it attached one condition the founding coalition did not like: the mainland would not rescue the District. Whatever the new jurisdiction lost, it would have to absorb itself. The banks that became Lakebird and the funds that became Black Swan could not insure each other, since they were the risk, so they put up the capital for a reinsurer to stand behind all of them and called it Safe Harbor.\n\nIt began as a mutual, owned by the institutions it covered. When it listed, the mutual kept about 30% of the shares, and its board still includes a director nominated by each of the two founding houses. The arrangement has been described in a Diet committee as the District insuring itself with its own money. Safe Harbor's reply is that this is what insurance is.",
                ],
                [
                    'heading' => 'What it covers',
                    'body' => "Primary insurers pass it the catastrophe layers of their books: the hurricanes that come in off the sound, floods, and industrial losses too large for any one insurer to carry. It also writes cover that few reinsurers elsewhere would, protecting banks and non-bank lenders against credit events and against a freeze in interbank funding, and it sells retrocession to other reinsurers. White Dove Insurance, once its retail arm, was spun off so that a household's car insurance would not depend on the District's worst year. It still sends its catastrophe exposure back to its former parent.\n\nAbout 60% of revenue comes from these reinsurance treaties. The rest comes from catastrophe bonds, which Safe Harbor structures to pass part of the risk to outside investors and on which it earns a spread.",
                ],
                [
                    'heading' => 'The True 10-Year Yield',
                    'body' => "The yield on Safe Harbor's catastrophe bonds is quoted on Glasswater Row as the True 10-Year Yield. The Exchequer's ten-year rate, the saying goes, tells you what the District will pay you; Safe Harbor's tells you whether the District will be there to pay. Traders watch the gap between the two as the market's own reading of tail risk.",
                ],
                [
                    'heading' => 'How it is run',
                    'body' => "Management is conservative even by the standards of reinsurance. Premiums are invested mainly in Exchequer bonds and other high-grade reserve assets, capital is held well beyond what the rules require, and the company pays out about 30% of earnings, keeping the rest for the year it will need it. In quiet years this looks like hoarding, and shareholders say so at most annual meetings.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "Results follow the frequency of large losses rather than the business cycle, so the shares have a beta of about 0.2 and spend long stretches doing very little. The risk is the year they do something. A large hurricane can erase several years of profit. A financial crisis is worse, because claims from the banks it insures arrive just as the assets it holds to pay them are falling, and its largest clients are also its founders.\n\nSafe Harbor is the District's answer to the mainland's condition. Nobody stands behind it, and in most years the shares are priced as though nobody needs to.",
                ],
            ],
            'related' => ['LAKE', 'SWAN', 'DOVE'],
        ],

        'BRKW' => [
            'headline' => 'Breakwater Trust: the name on the proposal',
            'standfirst' => 'The oldest money in the District was there before the District. It lent its name to the founding, controls companies it has held for generations, and says as little about any of it as it can.',
            'sections' => [
                [
                    'heading' => 'Before the District',
                    'body' => "Breakwater began more than a century ago as a syndicate that built and ran the deepwater berths and foundries on the peninsula, when nobody thought of the peninsula as anything but a coast. The Breakwater family has run it ever since. The Breakwater Foundation, a non-profit the family controls, holds about a quarter of the shares and all of the super-voting Class A stock, which puts the Trust's direction beyond the reach of whoever owns the rest.\n\nIts offices are at the southern bend of Glasswater Row, behind a stone front carved with the family motto, Esse, non videri: to be, rather than to be seen.",
                ],
                [
                    'heading' => 'The name on the proposal',
                    'body' => "Leonard Ashcombe's proposal for a self-governing enclave had been turned down by government and by industry and was about to be filed away when Margaret Breakwater, then chair of the Foundation, agreed to put the family's name to it. It was the name, more than the money, that the banks that became Lakebird and the funds that became Black Swan needed. A family that had owned the coast for a century and expected to own it for another was evidence that the experiment was meant to last.\n\nThe family has always described its support as a civic duty. Analysts who have studied the period note that the Trust's berths were empty at the time, its foundries were on short hours, and every plan for the new District needed a port, a railway and a power grid on land the Trust already owned. Both accounts may be true.",
                ],
                [
                    'heading' => 'What it holds',
                    'body' => "The Trust owns half of Crossbill Precision Tooling, its oldest holding, and half of Alca Compression Dynamics. It holds just over a third of four more: Albatross Deepwaters, whose ships use the berths the syndicate first built; Kestrel Civic Lines, held for the right of way more than for the trains; Bird Power, whose electric concession the Trust took up when the District was chartered; and Erne Network Systems, which makes the equipment the grid talks over. It also owns concessions and property outright.\n\nIt works through boards rather than plant floors. It nominates executives, sets the return on capital each company is expected to earn and backs long programmes, such as grid electrification, that a shorter-term owner would not fund. It calls this industrial stewardship.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "Income is mostly dividends from the companies it holds and distributions from the concessions it owns. The Trust pays out about 40% of what it earns, and the dividend has risen steadily. It carries little debt and a large reserve of cash and Exchequer bonds. In a credit crunch the reserve is the point: when banks stop lending, Breakwater takes up its full share of every rights issue its companies make, which can be the difference between an issue that clears and one that fails.\n\nThe shares have usually traded below the value of what the Trust owns. It has never shown much interest in closing the gap, since it does not intend to sell anything.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "Breakwater's results follow its companies, and they follow industry, freight and power demand, so the shares move with the District economy, with a beta of about 0.9, if more slowly than most. The question subscribers ask is succession. The Foundation's control means the Trust is only as good as the family member who chairs it, and the family has never said how that person is chosen.",
                ],
            ],
            'related' => ['LAKE', 'SWAN', 'CBIL', 'ALCA', 'ALBT', 'KSTL', 'BIRD', 'ERNE'],
        ],

        'IBHI' => [
            'headline' => 'Iron Beak Heavy Industries: the District had to be built first',
            'standfirst' => 'The largest builder in the District is owned by the two houses that finance most of what it builds. It is the most cyclical large company on the board, and Glasswater Row reads its share price as a reading of the economy.',
            'sections' => [
                [
                    'heading' => 'The third merger',
                    'body' => "Before the District could be governed it had to be built, and the coalition behind the Charter had no builder. The bust had bankrupted most of the mainland's large contractors, so in the District's first year Lakebird Bank and Black Swan Capital bought dredging fleets, crane yards and a shipyard out of receivership, at a fraction of what they had cost, and merged them into one company. Iron Beak was the coalition's third merger, after the banks and the funds, and the only one it made by buying rather than joining.\n\nIts first contract was the channel to Breakwater Trust's deepwater berths, followed by the roads, the transit lines and the first towers on Glasswater Row. Many of its welders and crane operators are the children and grandchildren of the crews who did that work, and most of the District's skilled trades still work for it. By most estimates they vote for Iron Harbor, which is no friend of the banks that own their employer. Neither side has found this a problem.",
                ],
                [
                    'heading' => 'Its owners, its customers',
                    'body' => "Lakebird and Black Swan still hold about 55% of the shares between them. Lakebird arranges much of the municipal borrowing that pays for civic projects, and Black Swan leads many of the District's property redevelopments, so a large part of Iron Beak's order book comes from projects its owners have financed. A courthouse built for Plaza Civic River Trust with money arranged by Lakebird pays Lakebird twice, once in interest and once in dividends from the contractor.\n\nPublic tenders are open to every bidder, and Iron Beak usually wins them. Rival contractors have complained to the Diet on several occasions. The Diet has asked for a report on each occasion.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "About 60% of revenue comes from civil infrastructure: highways, transit lines, ports and public buildings. A quarter comes from commercial work, mainly office towers and fabrication yards, and the remaining 15% from maintenance contracts for municipal works and dry docks. Its shipyards also build ocean-going tankers.\n\nThe yards, docks and equipment fleet carry heavy fixed costs and need steady capital spending, and much of the work is bid at a fixed price, so cost overruns fall on Iron Beak rather than the client. The operating margin is about 9%. A small fall in volume therefore takes a large share of the profit with it.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "Orders follow construction and public works budgets and stop quickly when budgets are cut, which gives the shares a beta of about 1.8, among the highest on the board. Traders who want a quick view of the economy look at Iron Beak before they look at the Statistical Office.\n\nThe owners are the other question. As long as Lakebird is lending and Black Swan is redeveloping, the order book refills. In a downturn both stop at once, and the company that built the District finds out how many of its customers were the same two firms.",
                ],
            ],
            'related' => ['LAKE', 'SWAN', 'BRKW', 'PLZA'],
        ],

        'PERE' => [
            'headline' => 'Peregrine Prime Securities: the other side of your option',
            'standfirst' => 'If you have bought an option on the Aerie Exchange, Peregrine probably wrote it. The firm makes markets, sells volatility, and has its best quarters when everyone else is having their worst.',
            'sections' => [
                [
                    'heading' => 'New money',
                    'body' => "Peregrine is not a founding house, and it has never wanted to be mistaken for one. It was started a generation after the Charter by a group of mathematicians and engineers who had left the founding houses' trading floors, convinced that the houses were too slow to price the risks they were taking. It began by quoting options on the Exchange's largest companies when few others would, and found that the people who buy options pay more for them, on average, than the options turn out to be worth.\n\nIts partners were among the younger members who broke with the Vanguard over the founding houses' privileges, and the firm helped pay for New Horizon's first campaign, against a rise in the stamp duty on share trades. The rise was reversed. Peregrine, which trades more often than anyone else in the District, has never claimed to be disinterested.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "About 60% of revenue comes from writing options: premium paid by retail traders buying calls and by funds buying protection against a fall, which Peregrine keeps when the market turns out calmer than the price it charged. The other 40% comes from trading, the bid-ask spread on the orders it fills and the retail order flow it internalises.\n\nThe firm takes no view on direction. It hedges continuously to keep its book close to delta-neutral, on its own trading hardware and a private microwave network. The operating margin is about 35%, and it pays out about 40% of earnings.",
                ],
                [
                    'heading' => 'The hedge that pays twice',
                    'body' => "Peregrine is one of very few listed companies in the District with a beta of about -0.2: its shares tend to rise when the market falls. Calm bull markets bring modest, steady results. Sell-offs lift volumes, widen spreads and raise the price of protection, and the firm has reported its best quarters during market stress. Fund managers hold the shares as a hedge, which means some of them are paying Peregrine for protection twice.\n\nIts rival for the tape is Rook Proprietary Trading, which trades the same names with its own capital. The two firms hire from the same universities and do not otherwise speak. Peregrine is also one of the few large desks in the District that does not use a Tickbird terminal, which this desk records without comment.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "A firm that sells insurance against large moves earns a little on most days and pays out on the day the move arrives. Peregrine's losses come when prices gap faster than its hedges can be adjusted: an overnight jump, a halted stock, a market that opens far below where it closed. The question for shareholders is whether the premiums of the calm years cover that day. So far they have, and the firm's partners point out that this is what the people who sold them their first options thought too.",
                ],
            ],
            'related' => ['ROOK', 'TICK'],
        ],

        'CORV' => [
            'headline' => 'Corvid Capital: what everybody else finds out next week',
            'standfirst' => 'Corvid advises on deals, restructures debt and trades for its own account, and all three businesses seem to know a great deal about each other. Nothing it does has ever been found to break a rule.',
            'sections' => [
                [
                    'heading' => 'The examiner',
                    'body' => "Corvid was founded in the District's early years by Harlan Vickery, who had spent a decade as an examiner reading the confidential filings of Glasswater Row's banks. He left, in his own account, because the work had stopped teaching him anything. He took no documents with him, and nobody has ever suggested he needed to. He hired two former colleagues and opened an advisory boutique two streets from his old office.\n\nVickery retired long ago. The firm describes his whereabouts as a private matter. His partners and their successors still hold about 15% of the shares.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "Corvid presents itself as an advisory firm. It advises on cross-border mergers, private equity transactions and debt restructurings, and those fees are about a quarter of revenue. The other 75% comes from its own trading desks, which take positions through options and credit default swaps.\n\nThe operating margin is about 45%, and the firm pays out about 30% of earnings. A few large positions produce most of the profit, so results swing from quarter to quarter, though rarely in the same direction as the market.",
                ],
                [
                    'heading' => 'The research unit',
                    'body' => "The trading desks are fed by a corporate research unit that tracks supply problems, regulatory inquiries and management turnover at District companies. Its sources are former executives, contractors, suppliers' staff and anyone else who has recently left a building Corvid is interested in. The District's insider-dealing rules cover information obtained from a company's own officers. They are silent on information obtained from its former officers, its auditors' junior staff, its caterers and its cleaners, and Corvid has done more than anyone to establish how much the silence covers.\n\nCompanies that have tried to trace a leak of board information to Corvid have found that the trail ends at nominee trusts and offshore companies. Black Swan Capital is widely believed to be a client of the research unit, which neither firm confirms.",
                ],
                [
                    'heading' => 'Both sides of the wall',
                    'body' => "The debt advisory arm is often hired to manage the restructurings of companies whose trouble the trading desks noticed first. Corvid says the two businesses are separated by an information barrier, and it is a real one: it runs the length of the third floor. Visitors have noted that it has a door.\n\nRestructuring fees rise when more companies are in distress, and the trading desks tend to be positioned for distress before it is announced. The firm earns from a failure twice, once on the way down and once on the way out.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "The shares have low volatility and a beta of about -0.1, and they are usually quiet. Glasswater Row has a long-standing belief that unusual volume in Corvid comes before the failure of another listed company. Whether it works is debated, and this desk has looked at the question and decided not to publish the answer.\n\nThe risk is the silence in the rules. Every few years a member of the Diet proposes extending them to former officers and outside staff. No such bill has yet reached a vote. Corvid has never been asked to comment, though it is generally believed to know how each member intends to vote.",
                ],
            ],
            'related' => ['SWAN'],
        ],

        'WATCH' => [
            'headline' => 'Bird Watch Security: the District\'s police, by contract',
            'standfirst' => 'Bird Watch guards the District\'s ports, borders and boardrooms under public concession and private retainer. Its customers rarely cut the bill, because their competitors would notice.',
            'sections' => [
                [
                    'heading' => 'Policing put out to tender',
                    'body' => "The District was founded with a small public administration and the expectation that private firms would do much of what governments elsewhere do for themselves. Policing was among the first services put out to tender. Bird Watch, then a guarding company serving the banks on Glasswater Row, won the contracts for the ports and for the Trade & Migration Office's border checkpoints, and has renewed them ever since.\n\nIts officers patrol alongside the municipal police. In some boroughs residents find it hard to say which is which, and the company has never seen a commercial reason to make it easier.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "Public concessions bring in about 40% of revenue and private retainers about half. The remainder comes from assignments abroad, often alongside Osprey Global Vanguard. Retainers run for several years and clients treat them as fixed overhead, so revenue changes little from one year to the next.\n\nThe operating margin is about 17%, modest for a business with such loyal customers, because most of the cost is people and their pay.",
                ],
                [
                    'heading' => 'Black Label',
                    'body' => "The Black Label tier is armed close protection, rapid-response air units and counter-surveillance for executives and their families. It is a small part of the headcount and the part of the business analysts find hardest to forecast, because it sells itself. When one company puts its chief executive under Black Label cover, its competitors' boards are asked why they have not, and they usually sign within the year. No client is known to have dropped the service.",
                ],
                [
                    'heading' => 'Osprey',
                    'body' => "Osprey Global Vanguard was spun off from Bird Watch's overseas division so that the legal and diplomatic risk of foreign work would sit in a separate company. The two still share communications systems, buy much of their equipment together from Gryphon Defense Systems and exchange staff freely. A career that starts guarding a mine for Osprey often ends guarding a chief executive for Bird Watch.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "Demand barely moves with the economy, and the shares have about half the market's beta. The risk is a single failure: a breach at a protected site or an attack on a Black Label client. Bird Watch's prices rest on a record of not failing, and a visible lapse could send clients back to contracts they have not read in years.\n\nThe concessions are granted by the District's public bodies, so a government that wanted policing back in public hands could take a large part of the book with it. No cabinet has yet tried.",
                ],
            ],
            'related' => ['OSPR', 'GRIP'],
        ],

        'TRIV' => [
            'headline' => 'Three Rivers Manufacturing: several hundred businesses and one habit',
            'standfirst' => 'Three Rivers makes adhesives, abrasives, medical films and the signs on District roads. Its products are everywhere and its shares rarely move, which is most of the appeal.',
            'sections' => [
                [
                    'heading' => 'From sandpaper outward',
                    'body' => "Three Rivers began as an abrasives works where three rivers meet the sound. It made sandpaper, then the adhesive that held the grit, then tape from the adhesive, and found at each step that the next product could be sold to customers it already had. The company has followed that reasoning ever since and now runs several hundred divisions, few of which have anything to do with sandpaper.\n\nIt was already old when the District was founded, and is one of the few large listed companies whose history does not begin with the founding banks. Its head office is still beside the original works, a long way from Glasswater Row, and the company has kept its distance from the District's finance houses in other ways too. It borrows little, has never been the subject of a Black Swan campaign, and its annual report runs to fewer pages than its product catalogue.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "About 60% of revenue comes from industrial products sold to manufacturers, contractors and hospitals, and about 30% from household goods such as tapes, adhesives and cleaning pads. The Works Ministry is a large customer for road signs, reflective film and safety equipment.\n\nEach division runs its own plants and sales force and sends its cash to head office, which decides where it goes next. The operating margin is about 16%, and the company pays out about half its earnings as dividends.",
                ],
                [
                    'heading' => 'Signs and film',
                    'body' => "Three Rivers makes most of the road signs in the District, and the reflective film on almost all of them. The Works Ministry buys both, along with barriers, cones and the safety equipment its crews wear, under framework contracts that are retendered every few years and that Three Rivers has rarely lost. Rivals point out that the Ministry's specification for reflective film was drafted with help from Three Rivers' own engineers. The company points out that nobody else made the film at the time.",
                ],
                [
                    'heading' => 'The habit',
                    'body' => "Head office prefers buying businesses to building them, and management is judged on growth more than on returns. In most years a large share of free cash flow goes on acquisitions, usually small makers of specialised products that become new divisions. No single purchase is large enough to matter, which is how investors have tended to excuse each one. Whether the total earns its cost is the main debate on the stock, and it is not one the company joins.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "No division is large enough to move the group, so Three Rivers is among the least volatile industrials on the Aerie Exchange, with a beta of about 0.6. Earnings follow industrial output and public works budgets, at a distance. The risks are the ones diversification is meant to rule out: a product liability case large enough to cross divisions, or a long run of overpaying for growth.",
                ],
            ],
            'related' => ['SWAN'],
        ],

        'TICK' => [
            'headline' => 'Tickbird Data Systems: a note on ourselves',
            'standfirst' => 'Tickbird\'s terminals sit on most trading desks in the District, including the one that wrote this profile. The author is an employee.',
            'sections' => [
                [
                    'heading' => 'Per screen',
                    'body' => "Tickbird began as a price feed for the banks on Glasswater Row in the District's first years, when every dealing room kept its own prices and none could see the others'. It sold the banks a screen that showed every quote at once and charged them for each screen. It has not changed that arrangement since.\n\nThe first terminals went to Lakebird's dealing room and to Black Swan's, and both firms have kept them on every desk since. Over time the screen added news, analytics, messaging between traders and, eventually, the ability to trade without leaving it. Each addition was sold as a reason to keep the terminal, and each was priced into the next renewal.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "About 90% of revenue is subscriptions, billed per terminal and per data feed; the rest is transaction fees from the trading platforms built into the terminal. Subscriptions are rarely cancelled, because a desk without live prices cannot trade, and they renew at a higher price in most years. The operating margin is about 55%.",
                ],
                [
                    'heading' => 'Competition',
                    'body' => "Tickbird has grown by buying smaller data and analytics vendors, usually before they were large enough to compete. Where a rival could not be bought, Tickbird has sued over patents and, according to the rivals, made its feeds work less well with their software. Tickbird's position, which this desk is obliged to report, is that the patents were valid and the feeds were always compatible.\n\nIts data centres run on immersion-cooled clusters from Penguin Computing, and Tickbird is that company's largest customer by some distance.",
                ],
                [
                    'heading' => 'The ones it cannot buy',
                    'body' => "Two companies sell data that Tickbird would rather sell itself. Aerie Central Clearing publishes the prices of everything it clears, and the Sovereign Reserve Fund's stake in it means it is not for sale. Shrike Standard Ratings owns the credit ratings that District investors are required to consult. Tickbird carries both on its terminals under licence, pays for the privilege, and describes both companies, in its own sales material, as valued partners.\n\nThe arrangement suits all three. Tickbird's subscribers get the data in the screen they already pay for, and the two suppliers are paid by every desk that has one.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "Revenue changes little whether markets rise or fall. The shares are another matter: they trade at technology multiples, with a beta of about 1.45, and fall with the technology sector even when subscriptions hold. A profile of Tickbird written by Tickbird is not a recommendation, and subscribers are reminded that this one is not either.",
                ],
            ],
            'related' => ['LAKE', 'SWAN', 'ACC', 'SHRK', 'PENG'],
        ],

        'GRIP' => [
            'headline' => 'Gryphon Defense Systems: the arms maker in a place with no army',
            'standfirst' => 'The District fields no armed forces, yet its best-connected company builds warplanes. Gryphon\'s customers are the mainland and its allies, and it has spent most of a century making sure they keep buying.',
            'sections' => [
                [
                    'heading' => 'Two rulebooks',
                    'body' => "Gryphon was founded a few years after the Charter by Walter Harrow, who had spent his career financing the mainland's defence contractors and had come to think their problem was money rather than engineering. Mainland contractors raised capital slowly and under close scrutiny. The District had just been built to make capital cheap and rules light. Harrow's idea was a defence contractor whose finances answered to the District and whose hangars answered to the mainland.\n\nThe Charter left defence to the mainland: the District raises no army, and the mainland defends it. Harrow spent two years persuading the mainland's defence ministry that work done on the peninsula could still be classified. He came away with an agreement that put Gryphon's works under the mainland's security rules. Its engineers hold mainland clearances, and mainland inspectors audit its classified programmes. Its accounts, borrowing and tax are the District's business, and the District asks fewer questions. Critics on the mainland call this the best of both rulebooks. Harrow called it the reason to move.\n\nHarrow took the company public within a decade and later sold his stake. Almost all of the shares now trade freely, held mostly by pension funds and income investors who own the company for its dividend, which has risen steadily.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "About 60% of revenue comes from cost-plus programmes for the mainland and its allies: Gryphon is reimbursed for what it spends and paid a fee on top, with incentive payments for meeting cost and schedule targets. A fifth comes from fixed-price development contracts, where Gryphon designs the next aircraft or missile system at an agreed price and absorbs any overrun. The last fifth is exports to other governments, the most profitable work, since each aircraft off a mature line costs less to build than the one before.\n\nThe build is only the start. A platform stays in service for decades, and the spares, upgrades and maintenance sold over that life earn more than the original sale. The operating margin is about 14%, and the company pays out about 45% of earnings. Revenue bunches in the third quarter, when the mainland's procurement agencies hurry to commit their money before the fiscal year closes.",
                ],
                [
                    'heading' => 'Friends in the capital',
                    'body' => "Gryphon's critics on the mainland call it a government department that pays a dividend. The company calls itself a partner to the services it equips. The two descriptions are closer than either side admits.\n\nGryphon writes much of the technical specification that procurement agencies adopt for new programmes, so by the time a tender is issued it is often the only bidder qualified to meet it. Retired generals and former procurement officials sit on its board and its advisory councils, and its engineers leave to run the programme offices that buy from it, then come back. It funds security policy institutes on the mainland whose threat assessments are quoted in the budget debates that pay for its programmes. It also places its subcontracts with care: suppliers to its fighter programme are spread across most of the mainland's legislative districts, so a vote to cancel it is a vote against an employer in nearly every member's constituency. Cancelling a Gryphon programme has been attempted several times. The programmes have usually outlasted the legislators who tried.\n\nAt home it keeps a lower profile. Nothing it needs is decided in the Diet, and it is one of the few large companies on the board that gives nothing to any party there.",
                ],
                [
                    'heading' => 'Neighbours',
                    'body' => "Bird Watch Security and Osprey Global Vanguard buy much of their equipment from Gryphon jointly, which gives the two security companies a discount and Gryphon a steady order for rotorcraft and surveillance kit. Ptarmigan Land Systems builds for the same customers on the ground, on different terms: most of its book is fixed-price exports won in open competition, the kind of contract Gryphon has spent decades arranging not to need.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "Orders follow mainland and allied defence budgets rather than the District economy, and a multi-year backlog carries earnings through District recessions. The shares have a beta of about 0.35. They tend to rise when international tensions do, since a crisis abroad brings export orders forward, and to drift lower on talk of a settlement that would let allied governments cut their budgets.\n\nThe nearer risks are procedural. A stopgap budget on the mainland freezes new programme starts until the legislature agrees a full one. A refused export licence can take half the export book with it. A programme that runs over can have its progress payments withheld until the fault is fixed, which ties up cash at the worst moment, and a grounded aircraft type puts a whole line of spares and service revenue at risk. Gryphon's connections have softened each of these in the past. Its shareholders are paying for the assumption that they will again.",
                ],
            ],
            'related' => ['WATCH', 'OSPR', 'PTAR'],
        ],

        'OSPR' => [
            'headline' => 'Osprey Global Vanguard: District money, guarded abroad',
            'standfirst' => 'Osprey was spun off so that Bird Watch would not have to answer in court for what happens overseas. It guards the mines and ships District money owns in places that would like them back, and it earns most when the rest of the world is doing worst.',
            'sections' => [
                [
                    'heading' => 'Someone had to stand on the land',
                    'body' => "When a foreign government defaulted on a Lakebird Bank or Black Swan Capital loan, the land it had pledged passed to the lenders. Owning a concession on paper was one thing. Working it, in a country whose government had just lost it, was another, and somebody had to stand on the land. Bird Watch Security, then guarding the lenders' offices on Glasswater Row, opened an overseas division to do it.\n\nThe division was run by Marcus Vane, a former officer in the mainland's special forces who had joined Bird Watch to train its close protection teams. Under Vane it grew from a few guard posts into a mechanised field force with its own vehicles, aircraft and supply lines. It also grew lawsuits. Host governments began suing Bird Watch in their own courts over incidents at the sites, and Bird Watch's board decided that a shooting at a mine should not be able to reach the company that guards the District's boardrooms. The division was spun off as Osprey, registered offshore, with Vane as its first chief executive.\n\nVane and the officers who left with him took shares in the spin-off. Staff and former officers still hold about a tenth of the company.",
                ],
                [
                    'heading' => 'How it earns',
                    'body' => "About 80% of revenue comes from deployments abroad: guarding mines and industrial sites, escorting merchant ships through disputed straits and getting staff out when a country turns against them. The work is billed as supply-chain security and logistics, and emergency deployments carry a mobilisation surcharge on top. Government contracts and standing retainers make up a tenth each.\n\nThe operating margin is about 22%, high for a business whose main cost is people, because clients facing the loss of a mine do not negotiate hard over the guard bill. The company pays out about 45% of earnings.",
                ],
                [
                    'heading' => 'The collateral business',
                    'body' => "Condor Extraction is Osprey's largest client, and the reason is the way Condor came by its concessions. Many are the land that defaulted governments pledged to Lakebird and Black Swan, and the two lenders together own a majority of Condor. Osprey's largest client is therefore owned by the two firms whose loans created the sites it guards. The governments that lost the land have rarely accepted the loss, and every threat to nationalise a Condor mine is, from Osprey's side of the ledger, a sales call.\n\nAlbatross Deepwaters hires its escorts for the straits where blockades are threatened, and Osprey's ships sail with the container fleet when a passage is contested.",
                ],
                [
                    'heading' => 'Still close to home',
                    'body' => "The spin-off separated the liabilities more than the companies. Osprey and Bird Watch share intelligence and communications systems, buy their equipment jointly from Gryphon Defense Systems and trade staff in both directions. A career that starts on a mine perimeter for Osprey often ends in executive protection for Bird Watch, and some of Bird Watch's most expensive Black Label officers learned the work on Osprey's convoys.",
                ],
                [
                    'heading' => 'What the desk is watching',
                    'body' => "Osprey's demand comes from fear: of conflict abroad, of a credit crisis that pushes indebted governments towards seizing foreign assets, of a market panic that makes owners check what their overseas sites are worth. When those rise, so do deployments, which gives the shares a beta of about -0.5. They have often risen while the rest of the board fell, and drift in long calm years as deployments wind down.\n\nThe risk is the field. A deployment that goes wrong in public, with civilians hurt or a client's staff killed, brings cancellations, legal claims and a diplomatic problem the District would rather not have. The spin-off was designed to keep that risk away from Bird Watch. It was not designed to keep it away from Osprey's shareholders.",
                ],
            ],
            'related' => ['LAKE', 'SWAN', 'WATCH', 'CNDR', 'ALBT', 'GRIP'],
        ],
    ];

    /**
     * @return array{headline: string, standfirst: string, sections: list<array{heading: string, body: string}>, related: list<string>}|null
     */
    public static function for(string $ticker): ?array
    {
        return self::ARTICLES[$ticker] ?? null;
    }
}
