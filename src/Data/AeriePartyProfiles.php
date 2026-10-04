<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Who the Diet's parties are, as the District's own civic guide would put it: their tradition, their voters, their
 * history before Year 1, what they make of each question, their agenda and the company they keep. Prose only; where
 * each party stands and what it would enact come from the politics engine, and every plank of an agenda is a law the
 * Diet's budget sets or a power the Charter gives it, so a page never promises what no government could do.
 *
 * Kept apart from AerieDiet so that rewording a profile never changes the fingerprint of the economy's parameters.
 */
final class AeriePartyProfiles
{
    // --- Profiles ---
    /** Each party's address, family, motto, founding, voters and heartland, background, history before Year 1, a line on each question, agenda, and friends and rivals. */
    public const PROFILES = [
        AerieDiet::CIVIC => [
            'slug' => 'civic-front',
            'family' => 'Social democrat',
            'motto' => 'Public services, paid for by the profits of Glasswater Row.',
            'founded' => 'In the ferry strike, the District\'s first winter',
            'voters' => 'Teachers, nurses, transit and ferry crews, municipal staff, and renters in the inner wards',
            'heartland' => 'The inner wards and the ferry towns of the Albemarle shore',
            'about' => [
                'The Civic Front is the District\'s largest party of the left and the political voice of its working class and public sector. It grew out of the teachers\', nurses\', ferry crews\' and municipal unions that kept the peninsula\'s schools, clinics and crossings running in the years after the Charter, when the financial houses governed for themselves and treated public services as a cost to be contracted out.',
                'It holds that a financial centre this rich can afford a state that carries its people through the bad years, and that the firms which gain most from the District\'s light rules should pay most toward it. Its economists accept the open markets the Charter created; what they reject is the idea that the profits of Glasswater Row belong only to those who book them.',
                'The Front is a disciplined party with a large membership, much of it through the unions, and it is used to governing from a minority, passing its budgets with the votes of the smaller parties of its bloc. Its weakness is the other side of its strength: when public-sector pay is the issue of the day, voters in the private economy remember whose party it is.',
            ],
            'history' => [
                ['title' => 'The ferry strike', 'text' => 'In the District\'s first winter the crossing crews stopped the ferries for six weeks over pay cuts imposed by the new port authority. The unions that fed the strikers formed a joint committee, and at the next election the committee stood candidates as the Civic Front.'],
                ['title' => 'The bank levy', 'text' => 'The Front was born in the shadow of the collapse that created the District, and from its first manifesto it proposed a levy on the largest banks\' balance sheets, so that the next rescue would be paid for in advance. It has been the Front\'s signature demand ever since.'],
                ['title' => 'The first Civic cabinet', 'text' => 'Its first government raised the corporate tax to pay for the schools and the crossings. The next Vanguard cabinet cut the tax back, but the schools kept their budget, and the Front has campaigned on that record since.'],
            ],
            'questions' => [
                AerieDiet::AXIS_STATE => 'Its founding principle: the biggest state of any party, paid for by taxes on profits, on share trading and on the banks.',
                AerieDiet::AXIS_OPENNESS => 'Slightly wary of the open door: it accepts trade and migration, but would put a light tariff on imports to protect jobs.',
                AerieDiet::AXIS_COUNCIL => 'Neither loyal nor hostile: it respects the Council\'s independence, but expects it to answer for its decisions.',
                AerieDiet::AXIS_ENVIRONMENT => 'Greener than any party but the Tideline Accord: a moderate carbon price, and some protection for open land.',
            ],
            'agenda' => [
                ['title' => 'A higher corporate tax', 'text' => 'Raise the rate on corporate profits to pay for schools, clinics and the crossings.'],
                ['title' => 'A levy on the banks', 'text' => 'Charge the largest balance sheets for the public guarantee they enjoy.'],
                ['title' => 'A duty on share trades', 'text' => 'Raise the stamp duty on share trading, paid into the Sovereign Reserve.'],
                ['title' => 'A price on carbon', 'text' => 'A moderate carbon price on the District\'s power.'],
            ],
            'relations' => 'The Front leads the left bloc in almost every Diet and is the Vanguard\'s rival for the premiership; the two will not govern together. Its usual partners are Iron Harbor and the Tideline Accord, which share its suspicion of Glasswater Row if not its faith in the state. It counts on the Common Lot\'s votes from outside the cabinet, and offers it nothing more.',
        ],
        AerieDiet::VANGUARD => [
            'slug' => 'vanguard',
            'family' => 'Liberal conservative',
            'motto' => 'Low taxes, light rules, and a state that stays out of the way.',
            'founded' => 'At the Charter convention',
            'voters' => 'Business owners, the senior staff of the financial houses, the professions, and homeowners in the waterfront wards',
            'heartland' => 'Glasswater Row and the waterfront wards',
            'about' => [
                'The Vanguard is the party of the District\'s founding bargain: light regulation, low taxes and a small state, the terms on which the banks and funds agreed to build here. Its founders sat at the Charter convention as delegates of the houses that wrote the Charter\'s economic clauses, and it remains the party of old money and the established houses of Glasswater Row.',
                'It argues that the District\'s prosperity rests on capital choosing to come here, and that every point of tax is a reason for it to leave. It would cut the corporate tax, keep share trading cheap, leave the banks unlevied, and let the District build.',
                'Inside the party the old houses and the newer business owners argue about the Council, which the old houses trust and the newer members find slow. The youngest of those members left to found New Horizon, which still votes with the Vanguard more often than against it.',
            ],
            'history' => [
                ['title' => 'The Charter convention', 'text' => 'The houses that founded the District sent delegates to draft its Charter. Those delegates wrote the low-tax, light-rule clauses, and at the first Diet election they stood together as the Vanguard.'],
                ['title' => 'The long premiership', 'text' => 'The Vanguard held the premiership for most of the years between the Charter and Year 1, usually governing alone with the votes of the Exchange and the Chartists.'],
                ['title' => 'The split', 'text' => 'A generation of younger members from the new trading firms broke with the party over its defence of the founding houses\' privileges, and left to found New Horizon.'],
                ['title' => 'Year 1', 'text' => 'When the markets opened the Vanguard governed alone, carried from the benches by the Exchange, the Chartists and New Horizon.'],
            ],
            'questions' => [
                AerieDiet::AXIS_STATE => 'Its founding principle: the smallest state of any party, with the lowest taxes on profits and on trading, and no levy on the banks.',
                AerieDiet::AXIS_OPENNESS => 'Open or closed is not its question: it defends free trade as good for business, but has little enthusiasm for more migration.',
                AerieDiet::AXIS_COUNCIL => 'Trusts the Council, which the founding houses helped design, though less devotedly than the Chartists.',
                AerieDiet::AXIS_ENVIRONMENT => 'Growth first, more than any other party: no carbon price, no new rules on drilling, and as few refused housing schemes as the law allows.',
            ],
            'agenda' => [
                ['title' => 'A lower corporate tax', 'text' => 'Cut the rate on profits to keep capital in the District.'],
                ['title' => 'No levy on the banks', 'text' => 'Leave the banks\' balance sheets untaxed, as the Charter\'s founders intended.'],
                ['title' => 'Cheap share trading', 'text' => 'Hold the stamp duty on share trades at its founding rate.'],
                ['title' => 'Let the District build', 'text' => 'Approve housing schemes, and put no price on carbon.'],
            ],
            'relations' => 'The Vanguard leads the right bloc and is the Civic Front\'s rival for the premiership; the two will not govern together. The Exchange and New Horizon are its natural partners, and the Chartists back its cabinets from outside. It has little in common with Iron Harbor beyond a dislike of the Civic Front\'s taxes, and nothing at all with the Common Lot.',
        ],
        AerieDiet::IRON_HARBOR => [
            'slug' => 'iron-harbor',
            'family' => 'Agrarian, protectionist',
            'motto' => 'Make more of what the District consumes.',
            'founded' => 'In the years after the free port opened, from the harbour and farm leagues',
            'voters' => 'Farmers, fishermen, shipyard and dock workers, the freight trades, and the small towns of the peninsula',
            'heartland' => 'The old harbour and the farm and fishing towns of the Pamlico shore',
            'about' => [
                'Iron Harbor began as an alliance of the peninsula\'s farmers, fishermen, shipyard mechanics and the freight trades of the old harbour, people whose livelihoods long predate Glasswater Row. Its leagues were old when the Charter was written, and they entered the Diet together, which is why the party still calls itself a coalition.',
                'It is the Diet\'s party of the closed economy. It wants the District to depend less on the mainland and the world: a protective tariff for its farms, fisheries, yards and workshops, and immigration no faster than the District can house and employ people. It is no friend of the banks, but its quarrel is with the open door rather than with the size of the state.',
                'It is a working party, not a green one. The harbour lives on dredged channels and the yards on work from the offshore shelf, and the Harbor resists any rule that would price or restrict either. That sets it against the Tideline Accord, whose voters live along the same coast.',
            ],
            'history' => [
                ['title' => 'The import flood', 'text' => 'When the free port opened, cheap goods from abroad emptied the peninsula\'s workshops within a few years. The farm, fishing and shipwrights\' leagues answered by running joint candidates, and the Iron Harbor Coalition was born.'],
                ['title' => 'The last yard', 'text' => 'The sale of the old harbour\'s last large shipyard to a mainland owner, who closed it within the year, gave the Harbor its best result before Year 1 and a lasting distrust of foreign buyers.'],
                ['title' => 'The tariff years', 'text' => 'Each time the Harbor joined a cabinet before Year 1 it won a tariff, and each time the next cabinet repealed it. The District opened Year 1 with none.'],
            ],
            'questions' => [
                AerieDiet::AXIS_STATE => 'Close to the middle: it would tax the banks somewhat, but its argument is about the border, not the budget.',
                AerieDiet::AXIS_OPENNESS => 'Its founding principle: the most closed economy of any party, with a high tariff and the tightest immigration regime.',
                AerieDiet::AXIS_COUNCIL => 'Suspicious of the Council\'s technocrats, whom it sees as Glasswater Row\'s appointees, though it stops short of the Common Lot\'s hostility.',
                AerieDiet::AXIS_ENVIRONMENT => 'Growth first: no carbon price, and no new rules on the offshore shelf.',
            ],
            'agenda' => [
                ['title' => 'A protective tariff', 'text' => 'A tariff on imports to protect the District\'s farms, fisheries, yards and workshops.'],
                ['title' => 'Immigration at the pace of housing', 'text' => 'Admit workers no faster than the District can house and employ them.'],
                ['title' => 'Keep the coast working', 'text' => 'No carbon price, and no new rules on drilling and mining.'],
            ],
            'relations' => 'Iron Harbor sits in the left bloc, closer to the Civic Front on the banks than to the Vanguard on anything, and it joins Civic cabinets when the arithmetic needs it. Its natural enemy is the Exchange, the party of the open door. With the Tideline Accord it shares a coast and a dislike of Glasswater Row, and quarrels over what the coast is for. In a bad year for the Civic Front, the Harbor can out-poll it and lead the left itself.',
        ],
        AerieDiet::EXCHANGE => [
            'slug' => 'exchange',
            'family' => 'Liberal',
            'motto' => 'An open District: free trade, free capital, free movement.',
            'founded' => 'With the free port, by the shipping lines and trading houses',
            'voters' => 'The trading houses and shipping lines, exporters, the international staff of the financial houses, and the District\'s newer citizens',
            'heartland' => 'The free port and the international quarter',
            'about' => [
                'The Exchange Party speaks for the District as a free port and meeting place: its trading houses, shipping lines and exporters, and the international staff who came to work in them. It is the Diet\'s party of the open economy.',
                'It holds that the District\'s fortune was made by openness and would be lost by closing the door to goods, capital or people. It defends the free port\'s zero tariff, wants a work permit for anyone with a job offer, and treats every proposed tariff as an invitation to the District\'s trading partners to tax its exports in return.',
                'On the size of the state it sits near the middle, which gives it room to govern with either bloc, though it usually campaigns beside the Vanguard. Many of its voters are newer citizens, and no party makes more of the District\'s international character.',
            ],
            'history' => [
                ['title' => 'The free-port law', 'text' => 'The shipping lines that came to the peninsula after the Charter pressed for a clause binding the District to zero tariffs. The Diet of the day passed it as ordinary law rather than writing it into the Charter, and the Exchange was founded to defend it.'],
                ['title' => 'The tariff fights', 'text' => 'Its sharpest contests before Year 1 were with Iron Harbor. Every tariff the Harbor won was repealed by the next cabinet the Exchange backed, which is why the free port opened Year 1 without one.'],
                ['title' => 'Year 1', 'text' => 'When the markets opened the Exchange was backing the Vanguard\'s cabinet from outside.'],
            ],
            'questions' => [
                AerieDiet::AXIS_STATE => 'Near the middle: it would leave taxes about where they are, with a modest levy on the banks.',
                AerieDiet::AXIS_OPENNESS => 'Its founding principle: the most open economy of any party, with no tariff and the widest door for migrants.',
                AerieDiet::AXIS_COUNCIL => 'Trusts the Council, which it sees as the guarantee that the District\'s markets stay open whoever governs.',
                AerieDiet::AXIS_ENVIRONMENT => 'Close to the middle: it accepts the rules in force, but would add no new ones.',
            ],
            'agenda' => [
                ['title' => 'Free trade', 'text' => 'No tariff on imports, so none in return on the District\'s exports.'],
                ['title' => 'Open migration', 'text' => 'A work permit for anyone with a job offer.'],
                ['title' => 'Steady taxes', 'text' => 'Keep the corporate tax near its long-standing rate: openness, not a low rate, is what brings business here.'],
            ],
            'relations' => 'The Exchange usually sits in the Vanguard\'s bloc and backs its cabinets, inside or out, but it is the party most able to cross over: on the state it is close enough to the Civic Front to make a government of the centre possible. It has rarely sat in a cabinet with Iron Harbor, whose tariffs it exists to oppose.',
        ],
        AerieDiet::CHARTISTS => [
            'slug' => 'chartists',
            'family' => 'Constitutionalist',
            'motto' => 'Defend the Charter. Trust the Council.',
            'founded' => 'After the first motion to remove a councillor',
            'voters' => 'Senior officials, financiers, academics, and the old families of the founding houses',
            'heartland' => 'The university quarter and the old houses of Glasswater Row',
            'about' => [
                'The Chartists take their name from the District\'s founding Charter and defend it as written: a Council of thirteen that elects its own successors and stands above the parties, much as an elected crown would. They are the Council\'s loyalists in the Diet and the steadiest opponents of any attempt to give the Diet power over it.',
                'Their voters are the District\'s senior officials, financiers and academics, who see in the Council a guarantee of sound money and long horizons. The Chartists would leave the Monetary Authority to set rates in peace, keep the debt brake, and would leave competition to the markets, letting the District\'s firms grow to world scale.',
                'Few parties will share a cabinet with them. Their deference to the Council is as awkward for the mainstream parties as the Common Lot\'s hostility, so the Chartists work from the benches, backing cabinets of the right and holding them to the Charter.',
            ],
            'history' => [
                ['title' => 'The first removal motion', 'text' => 'When the Common Lot\'s first deputies moved to remove the councillors who had defended the Merger Wave, the motion won barely a tenth of the Diet. It still frightened the Council\'s friends into founding a party to make sure no such motion ever came close.'],
                ['title' => 'The debt brake', 'text' => 'The Chartists wrote the convention under which the Council vetoes any budget that cuts revenue once the debt passes its line, and they have defended it in every Diet since.'],
                ['title' => 'Year 1', 'text' => 'When the markets opened the Chartists were backing the Vanguard\'s cabinet from outside, as they had backed most cabinets of the right before it.'],
            ],
            'questions' => [
                AerieDiet::AXIS_STATE => 'Leans small: low taxes, a light levy on the banks and cheap share trading, though it cares less about the size of the state than about who governs it.',
                AerieDiet::AXIS_OPENNESS => 'Open: the District\'s markets are the world\'s, and the Chartists want them to stay that way.',
                AerieDiet::AXIS_COUNCIL => 'Its founding principle: the Council\'s most loyal friends, who would never vote to remove a councillor.',
                AerieDiet::AXIS_ENVIRONMENT => 'Leans to growth: it would add no carbon price and no new rules on drilling.',
            ],
            'agenda' => [
                ['title' => 'The Charter as written', 'text' => 'Never vote to remove a councillor, and give the Diet no new power over the Council.'],
                ['title' => 'An Authority left alone', 'text' => 'Leave the Monetary Authority to set rates without pressure from the cabinet.'],
                ['title' => 'Light merger review', 'text' => 'Let the District\'s firms consolidate and compete abroad.'],
                ['title' => 'The debt brake', 'text' => 'Keep the Council\'s veto on budgets that cut revenue while the debt is high.'],
            ],
            'relations' => 'The Chartists sit in the right bloc and back its cabinets from outside; they rarely join one. Their natural allies are the Vanguard and the Exchange, who share their trust in the Council. The Common Lot is their opposite in every sense, and the two rarely vote together.',
        ],
        AerieDiet::COMMON_LOT => [
            'slug' => 'common-lot',
            'family' => 'Radical left, populist',
            'motto' => 'Break up Glasswater Row.',
            'founded' => 'In the shareholders\' revolt after the Merger Wave',
            'voters' => 'Small savers and retail shareholders, pensioners, and the young in the rented wards',
            'heartland' => 'The rented wards and the old company towns',
            'about' => [
                'The Common Lot is the party of the small saver and the retail shareholder, against the banks and funds that founded the District and, it says, still run it. It began as a shareholders\' revolt after a wave of mergers left a handful of firms in charge of whole industries, and the small holders of the firms they swallowed with little to show for it.',
                'It wants the cartels broken up, the strictest merger review the law allows, the banks charged for the guarantee they enjoy, and the Council\'s unelected technocrats made answerable to the Diet. In government it would press the Monetary Authority openly for cheaper money.',
                'The other parties see it as too radical to govern with, so it sits outside cabinets and lends its votes to the left when the terms suit it. Its vote swings further than any large party\'s, from a handful of seats to a real force in the Diet and back.',
            ],
            'history' => [
                ['title' => 'The Merger Wave', 'text' => 'In a few years of cheap credit the District\'s largest houses bought up their rivals in banking, shipping and retail. Small shareholders in the firms taken over were bought out at prices they had no power to refuse.'],
                ['title' => 'The shareholders\' revolt', 'text' => 'A campaign of small holders to block the last of those deals failed in the courts and turned to the ballot box. Its candidates entered the Diet as the Common Lot.'],
                ['title' => 'The first removal motion', 'text' => 'Its first deputies moved to remove the councillors who had defended the Merger Wave. The motion won barely a tenth of the Diet, but it was the first time anyone had tried, and it brought the Chartists into being to stop the next.'],
            ],
            'questions' => [
                AerieDiet::AXIS_STATE => 'Leans big: it would tax the banks and share trading heavily, though it cares less for the state than for breaking up the houses.',
                AerieDiet::AXIS_OPENNESS => 'Leans closed: it would tax imports and slow migration, distrusting a free port it sees as run for the houses.',
                AerieDiet::AXIS_COUNCIL => 'Its founding principle: the party most hostile to the Council, which it would make answerable to the Diet.',
                AerieDiet::AXIS_ENVIRONMENT => 'Close to the middle: a small carbon price, and little else.',
            ],
            'agenda' => [
                ['title' => 'The strictest merger review', 'text' => 'Block any deal that concentrates a market further.'],
                ['title' => 'Make the banks pay', 'text' => 'A heavy levy on the banks\' balance sheets, and a higher duty on share trading.'],
                ['title' => 'Cheaper money', 'text' => 'Press the Monetary Authority, openly, for lower interest rates.'],
                ['title' => 'An answerable Council', 'text' => 'Use the Diet\'s power to remove councillors, which no party has yet managed to use.'],
            ],
            'relations' => 'The Common Lot belongs to the left bloc but seldom if ever sits in a cabinet; the Civic Front relies on its votes from outside and offers it nothing more. It shares Iron Harbor\'s dislike of the free port and the Tideline Accord\'s dislike of the developers. The Chartists are its opposite, and New Horizon, which would break the cartels by competition rather than by law, is its most frequent sparring partner.',
        ],
        AerieDiet::TIDELINE => [
            'slug' => 'tideline-accord',
            'family' => 'Conservationist, localist',
            'motto' => 'Keep the foreshores, parks and headlands open to the sky.',
            'founded' => 'After the resort walls went up along the barrier islands',
            'voters' => 'Coastal and island residents, the marsh towns, naturalists, and younger voters across the District',
            'heartland' => 'The barrier islands and the marsh towns of the sounds',
            'about' => [
                'The Tideline Accord was founded by the peninsula\'s naturalists and coastal residents to protect the District\'s shoreline from the march of Glasswater Row. As resorts walled off beaches and dredging scarred the tidal bays, the Accord rallied its voters around a simple principle: the peninsula\'s coast belongs to everyone who lives on it.',
                'Its case is practical as well as scenic. The District is low-lying and sits in the path of Atlantic hurricanes, and the Accord argues that every salt marsh paved for housing is a stretch of sea wall the District will one day build at public expense. It would refuse more housing schemes on open land, put a full price on the carbon from the District\'s power, and hold offshore drilling and mining to the strictest rules.',
                'It sits with the Civic Front on public regulation but keeps its distance from the old industrial unions, and it has no patience for Iron Harbor\'s defence of the dredgers and the shelf. Prosperity, it says, cannot come at the cost of a poisoned coast.',
            ],
            'history' => [
                ['title' => 'The resort walls', 'text' => 'When the resort groups fenced off their stretches of the barrier islands, residents took the fences down at night. The court cases that followed made the movement\'s leaders known across the District.'],
                ['title' => 'The storm surge', 'text' => 'A hurricane\'s surge flooded the new wards built on filled marsh while the older towns behind the marshes stayed dry. The Accord\'s argument that the marshes were the District\'s flood defence won it its first seats.'],
                ['title' => 'The shelf spill', 'text' => 'A blowout on the offshore shelf fouled the sounds for a season, and strict rules on drilling became the Accord\'s third cause.'],
            ],
            'questions' => [
                AerieDiet::AXIS_STATE => 'Leans big: it trusts public regulation more than markets to protect the coast, and would tax the banks to pay for it.',
                AerieDiet::AXIS_OPENNESS => 'Leans a little closed: a modest tariff, and a slower pace of migration than the District has grown used to.',
                AerieDiet::AXIS_COUNCIL => 'Leans populist: it sees the Council as the voice of the houses that built on the marshes.',
                AerieDiet::AXIS_ENVIRONMENT => 'Its founding principle: the environment first, with the strictest planning rules, a full carbon price and the toughest rules on drilling.',
            ],
            'agenda' => [
                ['title' => 'Green belts', 'text' => 'Refuse housing schemes on the marshes, the headlands and open land.'],
                ['title' => 'A full carbon price', 'text' => 'Price the carbon from the District\'s power as high as the world\'s strictest schemes do.'],
                ['title' => 'Strict rules offshore', 'text' => 'Hold drilling and mining to the toughest standards, and make them pay for it.'],
            ],
            'relations' => 'The Accord sits in the left bloc and joins Civic cabinets as a junior partner, trading its votes on the budget for planning rules. It is closest to the Civic Front and the Common Lot and furthest from the Vanguard, but its bitterest arguments are with Iron Harbor, which shares its coast and its distrust of Glasswater Row and wants to use both differently.',
        ],
        AerieDiet::NEW_HORIZON => [
            'slug' => 'new-horizon',
            'family' => 'Techno-liberal, modernist',
            'motto' => 'Disrupt the cartels. Clear the runway for the next generation of capital.',
            'founded' => 'The youngest party, a breakaway from the Vanguard',
            'voters' => 'Founders and engineers, the trading firms and venture funds, and the young and well paid',
            'heartland' => 'The new towers at the edge of Glasswater Row',
            'about' => [
                'New Horizon is the political home of the District\'s financial technology founders, quantitative trading firms, venture funds and new money. It sees the economy as held back by the founding houses, which it calls cartels, and by rules written to protect them.',
                'Its answer to an incumbent is a challenger, not a regulator. It distrusts the merger courts nearly as much as the cartels, and would rather make it cheap to compete: a low duty on share trading, a visa for any founder or engineer who wants to come, no tariffs and a low corporate tax.',
                'It has no quarrel with the Council\'s technocrats, many of whom it regards as its own kind. Its quarrel is with the old houses the Vanguard still speaks for.',
            ],
            'history' => [
                ['title' => 'The split', 'text' => 'A generation of younger Vanguard members, many from the new trading firms, broke with the party over its defence of the founding houses\' privileges and left to found New Horizon.'],
                ['title' => 'The duty fight', 'text' => 'Its first campaign was against a rise in the stamp duty on share trades, which it called a tax on liquidity. The rise was reversed before Year 1, and cheap trading has been New Horizon\'s cause ever since.'],
                ['title' => 'Year 1', 'text' => 'When the markets opened New Horizon was backing the Vanguard\'s cabinet from outside, alongside the Exchange and the Chartists, its differences with its old party set aside for the budget.'],
            ],
            'questions' => [
                AerieDiet::AXIS_STATE => 'A founding principle: a small state, low taxes on profits, and cheap share trading.',
                AerieDiet::AXIS_OPENNESS => 'A founding principle: open to capital, goods and talent from anywhere.',
                AerieDiet::AXIS_COUNCIL => 'Leans technocratic: it trusts the Council\'s experts more than the Diet\'s populists.',
                AerieDiet::AXIS_ENVIRONMENT => 'Leans to growth: it would rather the District invented its way out of pollution than priced it.',
            ],
            'agenda' => [
                ['title' => 'Cheap trading', 'text' => 'Keep the stamp duty on share trades close to its founding rate.'],
                ['title' => 'Visas for talent', 'text' => 'Open the door to founders, engineers and their families.'],
                ['title' => 'A lower corporate tax', 'text' => 'Tax profits lightly, so that new firms keep what they earn while they grow.'],
            ],
            'relations' => 'New Horizon sits in the right bloc beside the Vanguard, which it left but still votes with on most budgets, and the Exchange, which shares its open door. It backs cabinets of the right from outside or joins them as a junior partner. Its sharpest exchanges are with the Common Lot, which wants the cartels broken by law rather than by competition, and with the Vanguard\'s old guard.',
        ],
    ];

    /** The party at an address, or null for none. */
    public static function partyForSlug(string $slug): ?string
    {
        foreach (self::PROFILES as $party => $profile) {
            if ($profile['slug'] === $slug) {
                return $party;
            }
        }

        return null;
    }
}
