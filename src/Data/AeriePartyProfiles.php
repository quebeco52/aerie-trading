<?php

declare(strict_types=1);

namespace App\Data;

/**
 * Who the Diet's parties are, as the District's own civic guide would put it: their tradition, their voters and their
 * agenda. Prose only; where each party stands and what it would enact come from the politics engine.
 *
 * Kept apart from AerieDiet so that rewording a profile never changes the fingerprint of the economy's parameters.
 */
final class AeriePartyProfiles
{
    // --- Profiles ---
    /** Each party's address, political family, motto, background in two paragraphs, and the planks of its platform. */
    public const PROFILES = [
        AerieDiet::CIVIC => [
            'slug' => 'civic-front',
            'family' => 'Social democrat',
            'motto' => 'Public services, paid for by the profits of Glasswater Row.',
            'about' => [
                'The Civic Front is the District\'s largest party of the left and the political voice of its working class and public sector. It was forged from the teachers\', nurses\', ferry crews\' and municipal trade unions that kept the peninsula\'s schools, clinics and infrastructure running while the financial houses governed for themselves.',
                'It holds that a financial centre this rich can afford a state that carries its people through the bad years, and that the firms which profit most from the District\'s light rules should pay the most toward it. It usually leads one of the Diet\'s two blocs, and is at home governing alone from a minority, with the smaller parties of its bloc passing its budgets.',
            ],
            'agenda' => [
                ['title' => 'A higher corporate tax', 'text' => 'Raise the rate on corporate profits to pay for the public sector.'],
                ['title' => 'Public services first', 'text' => 'Schools, clinics, transit and housing run by the state, not contracted out.'],
                ['title' => 'A state that steadies the cycle', 'text' => 'Hold public spending up when the markets turn down.'],
            ],
        ],
        AerieDiet::VANGUARD => [
            'slug' => 'vanguard',
            'family' => 'Liberal conservative',
            'motto' => 'Low taxes, light rules, and a state that stays out of the way.',
            'about' => [
                'The Vanguard is the party of the District\'s founding bargain: light regulation, low taxes and a small state, the terms on which the District was built. It is the full embodiment of old money and entrenched corporate interests in the District.',
                'It argues that the District\'s prosperity rests on capital choosing to come here, and that every point of tax is a reason for it to leave. It usually leads the other of the Diet\'s two blocs, and its leader is that bloc\'s candidate for the premiership.',
            ],
            'agenda' => [
                ['title' => 'A lower corporate tax', 'text' => 'Cut the rate on profits to keep capital in the District.'],
                ['title' => 'A smaller state', 'text' => 'Sell what the state need not own, and hold spending down.'],
                ['title' => 'Light-touch regulation', 'text' => 'Keep the rules that brought the banks to the peninsula.'],
            ],
        ],
        AerieDiet::IRON_HARBOR => [
            'slug' => 'iron-harbor',
            'family' => 'Agrarian, protectionist',
            'motto' => 'Make more of what the District consumes.',
            'about' => [
                'Iron Harbor began as an alliance of the peninsula\'s farmers, fishermen, shipyard mechanics and the freight trades of the old harbour, people whose livelihoods long predate Glasswater Row. It is the Diet\'s party of the closed economy.',
                'It wants the District to depend less on the mainland and the world: protective tariffs for its own workshops and farms, strict limits on foreign labour, and a legal veto over foreign buyouts of local docks and yards. It is no friend of the banks either, but its quarrel is with the open door rather than with the size of the state.',
            ],
            'agenda' => [
                ['title' => 'Tariffs on imports', 'text' => 'A protective tariff for the District\'s farms, fisheries, shipyards and workshops.'],
                ['title' => 'Controlled immigration', 'text' => 'Admit workers at the pace the District can house and support them.'],
                ['title' => 'Local ownership', 'text' => 'Limit foreign purchases of land, maritime berths and strategic firms.'],
            ],
        ],
        AerieDiet::EXCHANGE => [
            'slug' => 'exchange',
            'family' => 'Liberal',
            'motto' => 'An open District: free trade, free capital, free movement.',
            'about' => [
                'The Exchange Party speaks for the District as a global free port and meeting place: its trading houses, shipping lines, exporters, and the international talent who arrived to build them. It is the Diet\'s party of the open economy.',
                'It holds that the District\'s fortune was made by openness and would be lost by closing the door to goods, capital or people. On the size of the state it sits in the middle, defending the zero-tariff free-port charter while maintaining room to govern with either bloc, though it usually campaigns beside the Vanguard.',
            ],
            'agenda' => [
                ['title' => 'Free trade and free port status', 'text' => 'No tariffs, and none in return from the District\'s international partners.'],
                ['title' => 'Open migration', 'text' => 'A work permit for anyone with a job offer.'],
                ['title' => 'Open capital markets', 'text' => 'Keep the District open to foreign investors, listings and global liquidity.'],
            ],
        ],
        AerieDiet::CHARTISTS => [
            'slug' => 'chartists',
            'family' => 'Constitutionalist',
            'motto' => 'Defend the Charter. Trust the Council.',
            'about' => [
                'The Chartists take their name from the District\'s founding Charter and defend it as written: a Council of thirteen that elects its own successors and stands above the parties, much as an elected crown would. They are the Council\'s loyalists in the Diet and fierce opponents of any power grabs by the Diet.',
                'Their voters are the District\'s senior officials, financiers and academics, who see in the Council a guarantee of sound money and long horizons. They would leave competition policy to the experts and let the District\'s firms grow to world scale.',
            ],
            'agenda' => [
                ['title' => 'The Charter as written', 'text' => 'No vote to remove a councillor, and no new powers for the Diet over the Council.'],
                ['title' => 'Light merger review', 'text' => 'Let the District\'s firms consolidate and compete abroad.'],
                ['title' => 'Sound finances', 'text' => 'Keep the debt brake and the reserve fund out of party hands.'],
            ],
        ],
        AerieDiet::COMMON_LOT => [
            'slug' => 'common-lot',
            'family' => 'Radical left, populist',
            'motto' => 'Break up Glasswater Row.',
            'about' => [
                'The Common Lot is the party of the small saver and the retail shareholder, against the banks and funds that founded the District and, it says, still run it. It began as a shareholders\' revolt after a wave of mergers left a handful of firms in charge of whole industries.',
                'It wants the cartels broken up, the strictest merger review the law allows, and the Council\'s unelected technocrats made answerable to the Diet. The other parties see it as too radical to govern with, so it sits outside cabinets and lends its votes to the left when the price is right.',
            ],
            'agenda' => [
                ['title' => 'Strict merger review', 'text' => 'Block any deal that concentrates a market further.'],
                ['title' => 'An answerable Council', 'text' => 'Make the Council\'s appointments and vetoes subject to the Diet.'],
                ['title' => 'Small investors first', 'text' => 'Protect retail shareholders against takeovers and dilution.'],
            ],
        ],
        AerieDiet::TIDELINE => [
            'slug' => 'tideline-accord',
            'family' => 'Conservationist, localist',
            'motto' => 'Keep the foreshores, parks, and headlands open to the sky.',
            'about' => [
                'The Tideline Accord was founded by the peninsula\'s natural conservatists and coastal residents to protect the District\'s shoreline from the march of Glasswater Row. As luxury resorts walled off beaches and industrial dredging scarred the tidal bays, the Accord rallied citizens around a simple principle: the peninsula\'s natural beauty belongs to all who live here.',
                'It rejects both the extraction monopolies of the offshore shelf and the unbridled real estate sprawl that would pave the remaining salt marshes and headland walks. While it sits with the Civic Front on public regulation and scenic stewardship, it keeps its distance from the old industrial unions, insisting that genuine prosperity cannot come at the expense of a poisoned coast.',
            ],
            'agenda' => [
                ['title' => 'Public foreshores and beaches', 'text' => 'Guarantee unimpeded public access to every shore, and prohibit private coastal enclosures.'],
                ['title' => 'Protected headlands and parkland', 'text' => 'Designate permanent green belts and conservation corridors across the peninsula.'],
                ['title' => 'Clean bays and estuaries', 'text' => 'Halt seabed dredging and enforce strict environmental liability on offshore extraction.'],
            ],
        ],
        AerieDiet::NEW_HORIZON => [
            'slug' => 'new-horizon',
            'family' => 'Techno-liberal, modernist',
            'motto' => 'Disrupt the cartels. Clear the runway for the next generation of capital.',
            'about' => [
                'New Horizon is the political home of the District\'s FinTech founders, venture capital and new money. Impatient with the century-old cartels and oligopolies, it views the District\'s economy as calcified by protected monopolies and archaic Council deference.',
                'It advocates for ruthless competition, total digital transparency and frictionless capital mobility. While it usually caucuses with the Vanguard on low taxes and open markets, it has no love for the establishment\'s cartel covenants, pushing aggressively for open-banking mandates and algorithmic deregulation.',
            ],
            'agenda' => [
                ['title' => 'Break legacy banking monopolies', 'text' => 'Enforce open APIs and dismantle statutory credit moats protecting the founding lenders.'],
                ['title' => 'High-velocity capital markets', 'text' => 'Modernise securities laws for quantitative trading, venture equity and private credit.'],
                ['title' => 'Frictionless global talent', 'text' => 'Uncapped work visas and streamlined listings for international technologists and founders.'],
            ],
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
