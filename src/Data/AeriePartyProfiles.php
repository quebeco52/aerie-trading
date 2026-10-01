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
                'The Civic Front is the District\'s largest party of the left and the party of its public sector. It was built by the teachers\', nurses\' and ferry workers\' associations that kept the peninsula\'s schools, clinics and crossings running in the founding years, when the banks that created the District had little use for them.',
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
                'The Vanguard is the party of the District\'s founding bargain: light regulation, low taxes and a small state, the terms on which the banks and funds came to the peninsula. Its voters are the District\'s professionals, business owners and homeowners.',
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
                'Iron Harbor began as an alliance of the peninsula\'s farmers, fishermen and the freight trades of the old harbour, people whose livelihoods long predate Glasswater Row. It is the Diet\'s party of the closed economy.',
                'It wants the District to depend less on the mainland and the world: tariffs that give its own producers a fair price, limits on immigration, and a say over who buys the District\'s land and firms. It is no friend of the banks either, but its quarrel is with the open door rather than with the size of the state.',
            ],
            'agenda' => [
                ['title' => 'Tariffs on imports', 'text' => 'A protective tariff for the District\'s farms, fisheries and workshops.'],
                ['title' => 'Controlled immigration', 'text' => 'Admit workers at the pace the District can house them.'],
                ['title' => 'Local ownership', 'text' => 'Limit foreign purchases of land and strategic firms.'],
            ],
        ],
        AerieDiet::EXCHANGE => [
            'slug' => 'exchange',
            'family' => 'Liberal',
            'motto' => 'An open District: free trade, free capital, free movement.',
            'about' => [
                'The Exchange Party speaks for the District as a meeting place: its traders, its exporters, and the newcomers who came to work for them. It is the Diet\'s party of the open economy.',
                'It holds that the District\'s fortune was made by openness and would be lost by closing the door, to goods, to capital or to people. On the size of the state it sits in the middle, which lets it govern with either side, though it usually campaigns with the Vanguard.',
            ],
            'agenda' => [
                ['title' => 'Free trade', 'text' => 'No tariffs, and none in return from the District\'s partners.'],
                ['title' => 'Open migration', 'text' => 'A work permit for anyone with a job offer.'],
                ['title' => 'Open capital markets', 'text' => 'Keep the District open to foreign investors and listings.'],
            ],
        ],
        AerieDiet::CHARTISTS => [
            'slug' => 'chartists',
            'family' => 'Constitutionalist',
            'motto' => 'Defend the Charter. Trust the Council.',
            'about' => [
                'The Chartists take their name from the District\'s founding Charter and defend it as written: a Council of thirteen that elects its own successors and stands above the parties, much as an elected crown would. They are the Council\'s loyalists in the Diet and will never vote to remove a councillor.',
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
        AerieDiet::FREE_PORT => [
            'slug' => 'free-port',
            'family' => 'Neoliberal',
            'motto' => 'The freest port in the world.',
            'about' => [
                'The Free Port Compact was founded by the shipping lines, trading houses and fund managers who see the District as what its Charter made it: a free port. It wants the lowest taxes and the freest trade of any economy in the world.',
                'It pairs the Vanguard\'s small state with the Exchange Party\'s open door and usually campaigns with the Vanguard. Its vote is concentrated in Glasswater Row and the port wards.',
            ],
            'agenda' => [
                ['title' => 'The lowest corporate tax', 'text' => 'Cut the rate on profits, and keep cutting it.'],
                ['title' => 'No tariffs, ever', 'text' => 'Keep the District a free port.'],
                ['title' => 'Open borders for talent', 'text' => 'Let firms hire from anywhere in the world.'],
            ],
        ],
        AerieDiet::BASTION_GUILDS => [
            'slug' => 'bastion-guilds',
            'family' => 'Labour, protectionist',
            'motto' => 'The District\'s own trades, protected.',
            'about' => [
                'The Bastion Guilds are the District\'s craft and trade unions in politics: shipwrights, electricians, builders and dockers, the trades that worked the peninsula before the banks came. They want a strong state that stands behind the District\'s own industries.',
                'They pair the Civic Front\'s big state with Iron Harbor\'s closed door: higher taxes on the profits of finance, tariffs to protect local work, and limits on labour brought in to undercut it. They usually campaign with the Civic Front.',
            ],
            'agenda' => [
                ['title' => 'Tax finance, fund the trades', 'text' => 'A higher corporate tax to pay for training and public works.'],
                ['title' => 'Tariffs for the trades', 'text' => 'Protect the District\'s yards and workshops from cheaper imports.'],
                ['title' => 'Managed migration', 'text' => 'Admit only the labour the trades cannot train at home.'],
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
