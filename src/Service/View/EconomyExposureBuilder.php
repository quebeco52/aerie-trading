<?php

declare(strict_types=1);

namespace App\Service\View;

use App\Data\Company\Sectors;
use App\Entity\Stock;
use App\Repository\StockRepository;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\CommercialBankBusinessModel;
use App\Service\Model\Sector\MiningBusinessModel;
use App\Service\Model\Sector\OilGasProducerBusinessModel;
use App\Service\Model\Sector\RefiningBusinessModel;
use App\Service\Model\Sector\ReitBusinessModel;
use App\Service\Model\Sector\UtilityBusinessModel;

/**
 * The economy page's "who it moves" tables: for each macro driver the page charts, the listed firms with the most at
 * stake, each figure read from the same per-firm quantity the firm's earnings or financing path uses.
 *
 * - Input costs: the model's INPUT_COST_EXPOSURES basket (shares of the variable cost base), read by
 *   StandardOperatingPhysicsTrait::resolveInputCostDrag. Only models that declare a basket are listed, less refining.
 * - Sales: the producer's own revenue mix (crude/gas split, mine product mix, merchant power share).
 * - Currency: FX_REVENUE_EXPOSURE, read by resolveFxDemandShift.
 * - Rates: the floating share of debt, which calculateInterestExpenseAndWholesaleRate prices at the floating rate, against market value.
 * - Property: a lender's mortgage and commercial property loans, which set its collateral losses; and the REITs.
 * - The cycle: an operating firm's OPERATING_CYCLICALITY (1.0 = a firm with no sector constant); financials excluded, as not all read it.
 */
final class EconomyExposureBuilder
{
    // --- Table Size ---
    /** Firms listed under each heading. */
    public const FIRMS_PER_LIST = 5;

    /** Figure formats the template knows: a share of a whole, a multiple of the average firm, a dollar amount. */
    public const FORMAT_SHARE = 'share';
    public const FORMAT_MULTIPLE = 'multiple';
    public const FORMAT_MONEY = 'money';

    public function __construct(private readonly StockRepository $stocks) {}

    /**
     * @return list<array{key: string, title: string, intro: string, lists: list<array{label: string, unit: string, format: string, detail_unit: ?string, rows: list<array{ticker: string, name: string, value: float, detail: ?float}>}>}>
     */
    public function build(): array
    {
        return $this->buildFromStocks($this->stocks->findAll());
    }

    /**
     * @param iterable<Stock> $stocks
     *
     * @return list<array{key: string, title: string, intro: string, lists: list<array{label: string, unit: string, format: string, detail_unit: ?string, rows: list<array{ticker: string, name: string, value: float, detail: ?float}>}>}>
     */
    public function buildFromStocks(iterable $stocks): array
    {
        $collect = [
            'sells_oil' => [], 'buys_energy' => [],
            'sells_gas' => [], 'buys_gas' => [],
            'sells_power' => [], 'buys_power' => [],
            'sells_metals' => [], 'buys_metals' => [],
            'sells_gold' => [],
            'sells_farm' => [], 'buys_farm' => [],
            'floating_debt' => [],
            'currency' => [],
            'landlords' => [], 'property_lenders' => [],
            'cycle' => [],
        ];

        foreach ($stocks as $stock) {
            if ($stock->isBankrupt()) {
                continue;
            }
            $model = Sectors::strategyFor($stock->getIndustry());
            $row = static fn (float $value, ?float $detail = null): array => [
                'ticker' => $stock->getTicker(),
                'name' => $stock->getName(),
                'value' => $value,
                'detail' => $detail,
                'size' => self::marketValue($stock),
            ];

            // A refiner's basket is on its costs other than crude, so its shares do not compare with the others'.
            if (self::declares($model, 'INPUT_COST_EXPOSURES') && method_exists($model, 'getInputCostExposures') && !$model instanceof RefiningBusinessModel) {
                $basket = $model->getInputCostExposures();
                foreach (['energy' => 'buys_energy', 'gas' => 'buys_gas', 'electricity' => 'buys_power', 'metals' => 'buys_metals', 'agri' => 'buys_farm'] as $channel => $key) {
                    $share = (float) ($basket[$channel] ?? 0.0);
                    if ($share > 0.0) {
                        $collect[$key][] = $row($share);
                    }
                }
            }

            // A lender's debt is its funding, priced against what it lends: its rate exposure is its margin, not its borrowing.
            if (!$model->isFinancial()) {
                $debt = (float) $stock->getTotalDebt();
                $floating = max(0.0, min(1.0, (float) ($stock->getFloatingDebtRatio() ?? 0.0)));
                $size = self::marketValue($stock);
                if ($debt > 0.0 && $floating > 0.0 && $size > 0.0) {
                    $collect['floating_debt'][] = $row(($debt * $floating) / $size, $floating);
                }
            }

            if ($model instanceof OilGasProducerBusinessModel) {
                $liquids = $model->resolveProductionProfile($stock)['liquids_share'];
                if ($liquids > 0.0) {
                    $collect['sells_oil'][] = $row($liquids);
                }
                if ($liquids < 1.0) {
                    $collect['sells_gas'][] = $row(1.0 - $liquids);
                }
            }

            if ($model instanceof MiningBusinessModel) {
                $mix = $model->resolveProductMix($stock);
                foreach (['base_metals' => 'sells_metals', 'precious_metals' => 'sells_gold', 'energy_minerals' => 'sells_gas', 'fertilizer_minerals' => 'sells_farm'] as $stream => $key) {
                    if (($mix[$stream] ?? 0.0) > 0.0) {
                        $collect[$key][] = $row($mix[$stream]);
                    }
                }
            }

            if ($model instanceof UtilityBusinessModel) {
                $merchant = $model->resolveWholesalePowerRevenueShare($stock);
                if ($merchant > 0.0) {
                    $collect['sells_power'][] = $row($merchant);
                }
            }

            if (self::declares($model, 'FX_REVENUE_EXPOSURE')) {
                $collect['currency'][] = $row($model->getFxRevenueExposure());
            }

            if ($model instanceof ReitBusinessModel) {
                $collect['landlords'][] = $row($stock->getTotalAssets());
            }

            // A lender whose loss path is the commercial bank's own: its collateral losses follow the property indices.
            if ($model instanceof CommercialBankBusinessModel
                && (new \ReflectionMethod($model, 'resolveConditionalCreditLossRate'))->getDeclaringClass()->getName() === CommercialBankBusinessModel::class) {
                $collect['property_lenders'][] = $row($model->resolvePropertyLoanShare($stock));
            }

            // Every model inherits a cyclicality; operating firms' demand reads it, a financial's revenue lines need not.
            if (!$model->isFinancial()) {
                $collect['cycle'][] = $row($model->getOperatingCyclicality($stock));
            }
        }

        $list = static fn (string $key, string $label, string $unit, string $format = self::FORMAT_SHARE, ?string $detailUnit = null): array => [
            'label' => $label,
            'unit' => $unit,
            'format' => $format,
            'detail_unit' => $detailUnit,
            'rows' => self::top($collect[$key]),
        ];

        $cards = [
            ['key' => 'oil', 'title' => 'Oil and fuel', 'intro' => 'Producers sell crude at the world price; transport and heavy industry burn its products.', 'lists' => [
                $list('sells_oil', 'Sell crude and liquids', 'of sales'),
                $list('buys_energy', 'Buy fuel', 'of costs'),
            ]],
            ['key' => 'gas', 'title' => 'Natural gas', 'intro' => 'Gas fields and coal mines sell at gas-linked prices; power stations, refineries and chemical plants buy it.', 'lists' => [
                $list('sells_gas', 'Sell gas or gas-priced coal', 'of sales'),
                $list('buys_gas', 'Buy gas', 'of costs'),
            ]],
            ['key' => 'power', 'title' => 'Wholesale power', 'intro' => 'Generators sell part of their output into the wholesale market; the rest is sold at regulated tariffs.', 'lists' => [
                $list('sells_power', 'Sell power at market prices', 'of sales'),
                $list('buys_power', 'Buy power', 'of costs'),
            ]],
            ['key' => 'metals', 'title' => 'Industrial metals', 'intro' => 'Miners sell ore and concentrate; mills, carmakers and machinery makers buy metal.', 'lists' => [
                $list('sells_metals', 'Sell metals', 'of sales'),
                $list('buys_metals', 'Buy metals', 'of costs'),
            ]],
            ['key' => 'gold', 'title' => 'Gold', 'intro' => 'Precious-metal mines sell at the gold price.', 'lists' => [
                $list('sells_gold', 'Sell gold', 'of sales'),
            ]],
            ['key' => 'farm', 'title' => 'Farm prices', 'intro' => 'Potash and phosphate mines sell into farming; food and chemical makers buy crops.', 'lists' => [
                $list('sells_farm', 'Sell farm minerals', 'of sales'),
                $list('buys_farm', 'Buy crops and livestock', 'of costs'),
            ]],
            ['key' => 'rates', 'title' => 'Interest rates', 'intro' => 'Floating-rate debt reprices with the policy rate. Ranked by floating-rate debt against market value; banks and other lenders earn on rates and are left out.', 'lists' => [
                $list('floating_debt', 'Most floating-rate debt', 'of market value', self::FORMAT_SHARE, 'of its debt floats'),
            ]],
            ['key' => 'currency', 'title' => "The District's currency", 'intro' => 'Exporters and firms that compete with imports lose sales when the currency strengthens and gain when it weakens.', 'lists' => [
                $list('currency', 'Sales that compete on the exchange rate', 'of sales'),
            ]],
            ['key' => 'property', 'title' => 'Property prices', 'intro' => 'Landlords reset rents toward market as leases roll; lenders lose more on defaults when the collateral is worth less.', 'lists' => [
                $list('landlords', 'Largest landlords', 'total assets', self::FORMAT_MONEY),
                $list('property_lenders', 'Lenders with most property loans', 'of loans'),
            ]],
            ['key' => 'cycle', 'title' => 'The business cycle', 'intro' => 'How far sales swing with the economy, where 1.0× is a typical company. Financial firms are left out.', 'lists' => [
                $list('cycle', 'Most cyclical sales', 'typical swing', self::FORMAT_MULTIPLE),
            ]],
        ];

        $built = [];
        foreach ($cards as $card) {
            $card['lists'] = array_values(array_filter($card['lists'], static fn (array $l): bool => $l['rows'] !== []));
            if ($card['lists'] !== []) {
                $built[] = $card;
            }
        }

        return $built;
    }

    /**
     * Largest figure first; equal figures (firms on one sector basket) by market value.
     *
     * @param list<array{ticker: string, name: string, value: float, detail: ?float, size: float}> $rows
     *
     * @return list<array{ticker: string, name: string, value: float, detail: ?float}>
     */
    private static function top(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [$b['value'], $b['size']] <=> [$a['value'], $a['size']]);

        return array_map(
            static fn (array $r): array => ['ticker' => $r['ticker'], 'name' => $r['name'], 'value' => $r['value'], 'detail' => $r['detail']],
            array_slice($rows, 0, self::FIRMS_PER_LIST)
        );
    }

    private static function declares(BusinessModelInterface $model, string $constant): bool
    {
        return defined($model::class . '::' . $constant);
    }

    private static function marketValue(Stock $stock): float
    {
        return (float) $stock->getPrice() * (float) $stock->getSharesOutstanding();
    }
}
