<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * The companies whose revenue lines sell into more than one market, and the segments those lines form, as an annual
 * report's segment note groups them. A segment other than the main one sells to its own customers against its own
 * rivals, in the industry named. A line not listed stays in the company's main segment, in its own industry: financial
 * books (captive loans, cash float, trade credit) and lines sold at world commodity prices stay there too.
 *
 * A segment's economics are relative to its company's main business (1 when not listed), through the cycle: its
 * operating margin, and its capital turnover (revenue per unit of operating capital), as segment disclosures report
 * them. The company's own margin and turnover stay its seeded blend of them.
 *
 * @phpstan-type Segment array{label: string, industry: string, streams: list<string>, margin?: float, turnover?: float}
 */
final class Segments
{
    /** Key of the segment every line not listed belongs to. */
    public const MAIN = 'main';
    /**
     * Prefix of the key of a segment a company entered in play, followed by its industry's name; the segment's one
     * revenue line carries the same key.
     */
    public const ENTERED_PREFIX = 'x:';

    /**
     * @var array<string, array{main: string, segments: array<string, Segment>}> Ticker => its main segment's name and its other segments.
     */
    private const BY_TICKER = [
        'SKYL' => ['main' => 'Launches and missions', 'segments' => [
            // The model's own line costs make a subscriber cheaper than a launch, so the segment adds no margin of its
            // own.
            'satellite_internet' => ['label' => 'Satellite internet', 'industry' => 'Telecom Services', 'streams' => ['satellite_broadband']],
        ]],
        'TERN' => ['main' => 'Passenger flights', 'segments' => [
            // Belly space is the passenger fleet's, so it turns the fleet's capital as the cabins do.
            'air_cargo' => ['label' => 'Air cargo', 'industry' => 'Integrated Freight & Logistics', 'streams' => ['air_cargo'], 'margin' => 0.8, 'turnover' => 1.0],
        ]],
        'ERNE' => ['main' => 'Carrier networks', 'segments' => [
            // Commodity terminals and modules: thin margins on assembled hardware that turns over fast.
            'consumer_devices' => ['label' => 'Consumer devices', 'industry' => 'Computer Hardware', 'streams' => ['consumer_terminals'], 'margin' => 0.4, 'turnover' => 1.5],
        ]],
        'WEAV' => ['main' => 'Online retail', 'segments' => [
            // A first-party retailer earns thinly; its data centres and ad book earn several times as much on heavier
            // plant.
            'ads_and_cloud' => ['label' => 'Advertising and cloud', 'industry' => 'Internet Content & Information', 'streams' => ['digital_ads_cloud'], 'margin' => 6.0, 'turnover' => 0.5],
        ]],
        'JDAW' => ['main' => 'Online retail', 'segments' => [
            // A marketplace already earns well on its fees, so its cloud adds less over it.
            'ads_and_cloud' => ['label' => 'Advertising and cloud', 'industry' => 'Internet Content & Information', 'streams' => ['digital_ads_cloud'], 'margin' => 1.5, 'turnover' => 0.5],
        ]],
        'BIRD' => ['main' => 'Regulated power', 'segments' => [
            // Merchant plant earns the power-price spread, not an allowed return on a rate base, and turns faster.
            'merchant_power' => ['label' => 'Merchant generation', 'industry' => 'Utilities - Independent Power Producers', 'streams' => ['unregulated_merchant'], 'margin' => 0.4, 'turnover' => 1.8],
        ]],
        // The merchant house's terminal line carries the conglomerate parent's key.
        'RAVN' => ['main' => 'Trading and trade credit', 'segments' => [
            // Tank farms and wharves earn tariffs on heavy plant; the trading desk earns thin margins on little.
            'terminals' => ['label' => 'Terminals and storage', 'industry' => 'Marine Ports & Services', 'streams' => ['defensive_staples'], 'margin' => 2.5, 'turnover' => 0.25],
        ]],
        // A conglomerate's main business is its cash, which earns on the treasury and holds no plant, so its operating
        // segments' economics are struck against the industrial one.
        'OWLS' => ['main' => 'Cash and investments', 'segments' => [
            'industrial' => ['label' => 'Rail and manufacturing', 'industry' => 'Specialty Industrial Machinery', 'streams' => ['industrial_manufacturing']],
            // Utility plant and consumer brands turn slower than rail and earn a little less on it.
            'consumer' => ['label' => 'Utility infrastructure and consumer goods', 'industry' => 'Household & Personal Products', 'streams' => ['defensive_staples'], 'margin' => 0.8, 'turnover' => 0.75],
        ]],
        'TRIV' => ['main' => 'Cash and investments', 'segments' => [
            'industrial' => ['label' => 'Industrial products', 'industry' => 'Specialty Industrial Machinery', 'streams' => ['industrial_manufacturing']],
            // Household goods turn faster at a thinner margin, so the head office earns about the same on each.
            'consumer' => ['label' => 'Household and medical products', 'industry' => 'Household & Personal Products', 'streams' => ['defensive_staples'], 'margin' => 0.9, 'turnover' => 1.2],
        ]],
        'GDWT' => ['main' => 'Strategy and risk advisory', 'segments' => [
            // Programme teams bill as the advisers do on as little plant: the line's market is IT services, its
            // economics the firm's.
            'technology' => ['label' => 'Technology programmes', 'industry' => 'Information Technology Services', 'streams' => ['technology_programmes']],
        ]],
        'TSTN' => ['main' => 'Restructuring and investigations', 'segments' => [
            // Systems work earns a little more than crisis advisory on the same light capital.
            'technology' => ['label' => 'Technology programmes', 'industry' => 'Information Technology Services', 'streams' => ['technology_programmes'], 'margin' => 1.2, 'turnover' => 1.0],
        ]],
        'AVCT' => ['main' => 'Audit, tax and advisory', 'segments' => [
            // Systems work earns a little more than audit and advisory on the same light capital.
            'technology' => ['label' => 'Technology programmes', 'industry' => 'Information Technology Services', 'streams' => ['technology_programmes'], 'margin' => 1.2, 'turnover' => 1.0],
        ]],
        'HUMM' => ['main' => 'Search and advertising', 'segments' => [
            // Data centres: a thinner margin than the ad platform on more capital per dollar of sales.
            'cloud' => ['label' => 'Cloud computing', 'industry' => 'Software - Infrastructure', 'streams' => ['cloud_infrastructure'], 'margin' => 0.75, 'turnover' => 0.7],
        ]],
        'STAR' => ['main' => 'University and executive courses', 'segments' => [
            // Licensed software earns more than a campus on less capital.
            'learning_software' => ['label' => 'Learning software', 'industry' => 'Software - Application', 'streams' => ['digital_lms_licensing'], 'margin' => 1.5, 'turnover' => 1.5],
        ]],
        'FULM' => ['main' => 'Base petrochemicals', 'segments' => [
            // Patented catalysts and fine materials: the group's best margins, on lighter plant than its crackers.
            'specialty' => ['label' => 'Specialty chemicals', 'industry' => 'Specialty Chemicals', 'streams' => ['specialty_chemicals'], 'margin' => 1.6, 'turnover' => 0.8],
            // Fertiliser and crop protection plants: better margins than bulk olefins on heavier capital.
            'agrochemicals' => ['label' => 'Agrochemicals', 'industry' => 'Agricultural Inputs', 'streams' => ['agrochemicals'], 'margin' => 1.3, 'turnover' => 0.7],
        ]],
        'KSTL' => ['main' => 'Freight', 'segments' => [
            // Commuter trains and buses run on rolling stock and depots of their own and pay for the shared track as
            // they wear it; both lines hold a franchise, so neither earns more on its capital.
            'transit' => ['label' => 'Passenger transit', 'industry' => 'Passenger Transit', 'streams' => ['transit_subscriptions']],
        ]],
        'GULL' => ['main' => 'Casino resort', 'segments' => [
            // Shoreline rents: a landlord's margin on land that turns over slowly.
            'property' => ['label' => 'Shoreline property', 'industry' => 'REIT - Retail', 'streams' => ['cre'], 'margin' => 2.2, 'turnover' => 0.30],
        ]],
    ];

    /**
     * The company's segments, or null for one that sells into a single market.
     *
     * @return array{main: string, segments: array<string, Segment>}|null
     */
    public static function forTicker(string $ticker): ?array
    {
        return self::BY_TICKER[$ticker] ?? null;
    }

    /**
     * Each segment's operating margin and capital turnover against its company's main business, main segment
     * included at one.
     *
     * @return array<string, array{margin: float, turnover: float}>
     */
    public static function economicsOf(string $ticker): array
    {
        $economics = [self::MAIN => ['margin' => 1.0, 'turnover' => 1.0]];
        foreach (self::BY_TICKER[$ticker]['segments'] ?? [] as $key => $segment) {
            $economics[$key] = ['margin' => $segment['margin'] ?? 1.0, 'turnover' => $segment['turnover'] ?? 1.0];
        }

        return $economics;
    }

    /** The key of the segment a company enters an industry with. */
    public static function enteredKey(string $industry): string
    {
        return self::ENTERED_PREFIX . $industry;
    }

    /** The industry of a segment a company entered in play; null for a segment of its seeded books. */
    public static function enteredIndustry(string $segment): ?string
    {
        return str_starts_with($segment, self::ENTERED_PREFIX) ? substr($segment, strlen(self::ENTERED_PREFIX)) : null;
    }

    /** The segment a revenue line belongs to: an entered segment's own, the listed one, or the main segment. */
    public static function segmentOf(string $ticker, string $stream): string
    {
        if (self::enteredIndustry($stream) !== null) {
            return $stream;
        }
        foreach (self::BY_TICKER[$ticker]['segments'] ?? [] as $key => $segment) {
            if (in_array($stream, $segment['streams'], true)) {
                return $key;
            }
        }

        return self::MAIN;
    }

    /** @return list<string> */
    public static function tickers(): array
    {
        return array_keys(self::BY_TICKER);
    }
}
