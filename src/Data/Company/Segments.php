<?php

declare(strict_types=1);

namespace App\Data\Company;

/**
 * The companies whose revenue lines sell into more than one market, and the segments those lines form, as an annual
 * report's segment note groups them. A segment other than the main one sells to its own customers against its own
 * rivals, in the industry named. A line not listed stays in the company's main segment, in its own industry: financial
 * books (captive loans, cash float, trade credit) and lines sold at world commodity prices stay there too.
 *
 * @phpstan-type Segment array{label: string, industry: string, streams: list<string>}
 */
final class Segments
{
    /** Key of the segment every line not listed belongs to. */
    public const MAIN = 'main';

    /**
     * @var array<string, array{main: string, segments: array<string, Segment>}> Ticker => its main segment's name and its other segments.
     */
    private const BY_TICKER = [
        'SKYL' => ['main' => 'Launches and missions', 'segments' => [
            'satellite_internet' => ['label' => 'Satellite internet', 'industry' => 'Telecom Services', 'streams' => ['satellite_broadband']],
        ]],
        'TERN' => ['main' => 'Passenger flights', 'segments' => [
            'air_cargo' => ['label' => 'Air cargo', 'industry' => 'Integrated Freight & Logistics', 'streams' => ['air_cargo']],
        ]],
        'ERNE' => ['main' => 'Carrier networks', 'segments' => [
            'consumer_devices' => ['label' => 'Consumer devices', 'industry' => 'Computer Hardware', 'streams' => ['consumer_terminals']],
        ]],
        'WEAV' => ['main' => 'Online retail', 'segments' => [
            'ads_and_cloud' => ['label' => 'Advertising and cloud', 'industry' => 'Internet Content & Information', 'streams' => ['digital_ads_cloud']],
        ]],
        'JDAW' => ['main' => 'Online retail', 'segments' => [
            'ads_and_cloud' => ['label' => 'Advertising and cloud', 'industry' => 'Internet Content & Information', 'streams' => ['digital_ads_cloud']],
        ]],
        'BIRD' => ['main' => 'Regulated power', 'segments' => [
            'merchant_power' => ['label' => 'Merchant generation', 'industry' => 'Utilities - Independent Power Producers', 'streams' => ['unregulated_merchant']],
        ]],
        // The merchant house's terminal line carries the conglomerate parent's key.
        'RAVN' => ['main' => 'Trading and trade credit', 'segments' => [
            'terminals' => ['label' => 'Terminals and storage', 'industry' => 'Marine Ports & Services', 'streams' => ['defensive_staples']],
        ]],
        'OWLS' => ['main' => 'Cash and investments', 'segments' => [
            'industrial' => ['label' => 'Rail and manufacturing', 'industry' => 'Specialty Industrial Machinery', 'streams' => ['industrial_manufacturing']],
            'consumer' => ['label' => 'Utility infrastructure and consumer goods', 'industry' => 'Household & Personal Products', 'streams' => ['defensive_staples']],
        ]],
        'TRIV' => ['main' => 'Cash and investments', 'segments' => [
            'industrial' => ['label' => 'Industrial products', 'industry' => 'Specialty Industrial Machinery', 'streams' => ['industrial_manufacturing']],
            'consumer' => ['label' => 'Household and medical products', 'industry' => 'Household & Personal Products', 'streams' => ['defensive_staples']],
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

    /** The segment a revenue line belongs to: the listed one, or the main segment. */
    public static function segmentOf(string $ticker, string $stream): string
    {
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
