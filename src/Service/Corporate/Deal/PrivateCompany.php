<?php

declare(strict_types=1);

namespace App\Service\Corporate\Deal;

use App\Service\Math\MathUtility;

/** The private firm on the far side of a deal, the target bought or the buyer of a division: named, not modelled. */
final class PrivateCompany
{
    // --- Names ---
    /** First words: the marshes, sounds and shoals of the District's coast. Listed firms keep the birds. */
    private const PREFIXES = [
        'Saltmarsh', 'Tidewater', 'Sandbar', 'Shoalwater', 'Pocosin', 'Oxbow', 'Inlet', 'Millpond',
        'Driftwood', 'Tideline', 'Marshgrass', 'Backwater', 'Seawall', 'Eelgrass', 'Cypress Sound', 'Blackwater',
    ];
    /**
     * Second words by the industry the firm trades in, first match on its name wins; a firm in none of them takes the
     * fallback.
     */
    private const SUFFIXES_BY_INDUSTRY = [
        '/REIT|Real Estate/' => ['Properties', 'Realty', 'Land Company'],
        '/Insurance/' => ['Mutual', 'Assurance', 'Underwriters'],
        '/Bank/' => ['Bancorp', 'Savings & Loan', 'Trust Company'],
        '/Credit|Mortgage/' => ['Finance', 'Lending', 'Acceptance'],
        '/Asset Management|Private Equity|Hedge Fund|Investment|Distressed|Merchant/' => ['Capital', 'Partners', 'Investors'],
        '/Brokerage|Exchange|Clearing|Financial Data/' => ['Securities', 'Markets', 'Data'],
        '/Oil|Gas/' => ['Petroleum', 'Energy', 'Oil Company'],
        '/Gold|Copper|Steel|Aluminum|Mining/' => ['Minerals', 'Mining', 'Metals'],
        '/Chemical|Agricultural Inputs/' => ['Chemical Works', 'Chemicals', 'Fertilizer'],
        '/Water/' => ['Water Works', 'Water Company'],
        '/Utilities|Power/' => ['Power', 'Electric', 'Generating'],
        '/Software|Internet|Information Technology|Semiconductor|Computer|Communication Equipment/' => ['Systems', 'Software', 'Technologies'],
        '/Telecom|Broadcasting|Advertising/' => ['Media', 'Communications', 'Broadcasting'],
        '/Drug|Medical|Health/' => ['Health', 'Medical', 'Therapeutics'],
        '/Restaurant/' => ['Kitchens', 'Grill', 'Hospitality'],
        '/Resort/' => ['Resorts', 'Hospitality', 'Inns'],
        '/Food|Beverage|Farm|Grocery|Tobacco/' => ['Foods', 'Provisions', 'Growers'],
        '/Retail|Luxury|Apparel|Household/' => ['Outfitters', 'Stores', 'Trading Company'],
        '/Airline|Shipping|Freight|Railroad|Transit|Ports|Logistics/' => ['Lines', 'Transport', 'Freight'],
        '/Construction|Engineering|Building/' => ['Builders', 'Construction', 'Engineering'],
        '/Aerospace|Space|Defense|Nuclear/' => ['Aerospace', 'Systems', 'Engineering'],
        '/Services|Consulting|Legal|Education|Waste|Correctional/' => ['Services', 'Associates', 'Group'],
    ];
    /** Second words for an industry no row names. */
    private const FALLBACK_SUFFIXES = ['Industries', 'Works', 'Manufacturing', 'Holdings'];
    /** Slots the second word is drawn over: twelve divides evenly among two, three or four words. */
    private const SUFFIX_SLOTS = 12;

    /**
     * The two draws a name is built on, taken where the deal is struck so the stream does not depend on the industry
     * chosen after it.
     *
     * @return array{int, int}
     */
    public static function drawName(MathUtility $random): array
    {
        return [$random->generateIndex(count(self::PREFIXES)), $random->generateIndex(self::SUFFIX_SLOTS)];
    }

    /**
     * A private firm's name: a coastal word and a noun its industry's firms go by (Tidewater Properties, Oxbow
     * Petroleum).
     *
     * @param array{int, int} $draws From drawName()
     */
    public static function name(array $draws, ?string $industry): string
    {
        $suffixes = self::FALLBACK_SUFFIXES;
        foreach (self::SUFFIXES_BY_INDUSTRY as $pattern => $candidates) {
            if ($industry !== null && preg_match($pattern, $industry) === 1) {
                $suffixes = $candidates;
                break;
            }
        }
        $prefix = self::PREFIXES[max(0, min(count(self::PREFIXES) - 1, $draws[0]))];
        $suffix = $suffixes[max(0, min(count($suffixes) - 1, intdiv($draws[1] * count($suffixes), self::SUFFIX_SLOTS)))];

        return $prefix . ' ' . $suffix;
    }
}
