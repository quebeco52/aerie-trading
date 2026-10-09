<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Market\Pricing\PolicyCapitalization;
use App\Service\Model\Strategy\OperatingStrategyInterface;

/**
 * The listed firms each law moves most, for the government page: what the law in force adds to or takes from each
 * firm's year after tax, against no such law, and what the law the market expects would change, both struck on the
 * same bases and measures the market prices the laws with (PolicyCapitalization::earningsEffect()).
 */
final class LawExposureBuilder
{
    // --- Display ---
    /** Firms listed under each law other than the bank levy, the ones it moves most against their revenue. */
    public const FIRMS_PER_LAW = 5;
    /** Smallest share of earnings the page prints as a change: half the last place of a percentage to one decimal. */
    public const PRINTED_SHARE = 0.0005;

    /** Each law a firm's own accounts answer to: its name as the budget table prints it, and what it reaches. */
    private const LAWS = [
        'bankLevyRate' => ['Bank levy', 'Charged each year on what a bank funds itself with beyond its equity and insured deposits. Every bank that owes it is listed.'],
        'extractionStringency' => ['Extraction compliance cost', 'Raises the cost of every barrel and tonne the District\'s drillers and miners produce.'],
        'stampDutyRate' => ['Stamp duty on share trades', 'Thins the trading that brokers earn their commissions on.'],
        'carbonPrice' => ['Carbon price', 'Raises the price of power. A generator gains on the power it sells and pays on the gas it burns.'],
    ];

    /**
     * @param iterable<Stock>      $stocks   The listed board.
     * @param array<string, float> $standing The laws in force, keyed as PoliticsEngine::LEVER_FIELDS.
     * @param array<string, float> $expected The laws the market expects from the next budget it forecasts; empty without a forecast.
     * @return array{laws: list<array<string, mixed>>, corporateTax: array{inForce: float, expected: float, earnings: float, moves: bool}|null}
     */
    public static function build(iterable $stocks, MacroStateDTO $macro, array $standing, array $expected): array
    {
        $exposed = array_fill_keys(PolicyCapitalization::PER_FIRM_LEVERS, []);
        foreach ($stocks as $stock) {
            $strategy = Sectors::strategyFor($stock->getIndustry());
            $effectiveTax = $strategy->getEffectiveTaxRate($macro->corporateTaxRate);
            $earnings = (float) $stock->getTotalNetIncome();
            $revenue = (float) $stock->getTotalRevenue();

            foreach (PolicyCapitalization::PER_FIRM_LEVERS as $lever) {
                $base = self::base($strategy, $stock, $lever);
                // The same firms the market prices each law into (PolicyCapitalization::earningsGap()).
                if ($lever === 'carbonPrice' ? $base === 0.0 : $base <= 0.0) {
                    continue;
                }

                $inForce = $standing[$lever];
                $now = PolicyCapitalization::earningsEffect($lever, PolicyCapitalization::measure($lever, $inForce) - PolicyCapitalization::measure($lever, 0.0), $base, $effectiveTax);
                $change = isset($expected[$lever])
                    ? PolicyCapitalization::earningsEffect($lever, PolicyCapitalization::measure($lever, $expected[$lever]) - PolicyCapitalization::measure($lever, $inForce), $base, $effectiveTax)
                    : null;

                $exposed[$lever][] = [
                    'ticker' => $stock->getTicker(),
                    'name' => $stock->getName(),
                    'now' => $now,
                    'nowShare' => $earnings > 0.0 ? $now / $earnings : null,
                    'change' => $change,
                    'changeShare' => $earnings > 0.0 && $change !== null ? $change / $earnings : null,
                    'weight' => $revenue > 0.0 ? max(abs($now), abs($change ?? 0.0)) / $revenue : 0.0,
                ];
            }
        }

        $laws = [];
        foreach (self::LAWS as $lever => [$name, $note]) {
            $rows = $exposed[$lever];
            usort($rows, static fn(array $a, array $b): int => $b['weight'] <=> $a['weight']);
            $shown = $lever === 'bankLevyRate' ? $rows : array_slice($rows, 0, self::FIRMS_PER_LAW);
            $laws[] = [
                'lever' => $lever,
                'name' => $name,
                'note' => $note,
                'firms' => array_map(static fn(array $row): array => array_diff_key($row, ['weight' => true]), $shown),
                'more' => count($rows) - count($shown),
                // A change column only where the expected law moves some listed firm's earnings by a printed amount.
                'expectedMoves' => array_filter($shown, static fn(array $row): bool => abs($row['changeShare'] ?? 0.0) >= self::PRINTED_SHARE) !== [],
                'total' => array_sum(array_column($rows, 'now')),
            ];
        }

        // Every firm taxed at the full rate keeps the same share of its earnings, so the tax gets one figure, not a table.
        $corporateTax = null;
        if (isset($expected['corporateTax'])) {
            $inForce = MacroEngine::TARGET_CORPORATE_TAX_RATE + $standing['corporateTax'];
            $law = MacroEngine::TARGET_CORPORATE_TAX_RATE + $expected['corporateTax'];
            $earnings = PolicyCapitalization::afterTaxScale($inForce, $law) - 1.0;
            $corporateTax = ['inForce' => $inForce, 'expected' => $law, 'earnings' => $earnings, 'moves' => abs($earnings) >= self::PRINTED_SHARE];
        }

        return ['laws' => $laws, 'corporateTax' => $corporateTax];
    }

    /** What a law is charged on or moves for this firm, a year (OperatingStrategyInterface::annual*Base()). */
    private static function base(OperatingStrategyInterface $strategy, Stock $stock, string $lever): float
    {
        return match ($lever) {
            'bankLevyRate' => $strategy->annualBankLevyBase($stock),
            'extractionStringency' => $strategy->annualExtractionCostBase($stock),
            'stampDutyRate' => $strategy->annualStampDutyTurnoverBase($stock),
            'carbonPrice' => $strategy->annualCarbonPowerEarningsBase($stock),
            default => 0.0,
        };
    }
}
