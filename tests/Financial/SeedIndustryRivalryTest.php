<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\Data\InitialMarket;
use App\Data\Sectors;
use App\Data\StockModelTuning;
use App\Service\Math\CorporateMetrics;
use App\Service\Math\FinancialConstants;
use PHPUnit\Framework\TestCase;

/**
 * What a firm added to the seed owes the board it joins.
 *
 * Two properties, and both are about the moment a name is written into InitialMarket rather than about
 * anything that happens afterwards. A seed row is edited by hand against a balance sheet, and neither of
 * these failures announces itself: the firm prices fine on tick one and then behaves wrongly for the rest
 * of the run.
 *
 * The first is SCALE. A firm's saturation physics reads its invested capital against the serviceable market
 * its sam_ratio declares, so a balance sheet enlarged without the market to sell it into does not make a
 * bigger company — it makes one that opens already inside the Penrose band, paying convex administrative
 * bloat and a diminished marginal return on the first dollar it ever deploys. Capital is the size dial and
 * sam_ratio is the market it spans; they move together or not at all.
 *
 * The second is RIVALRY. Firms sharing an industry are priced against each other through the industry share
 * ledger, which spreads one firm's gain across the addressable market the others sell into. That machinery
 * only means something if the firms are actually different: two names drawing the same streams at the same
 * weights are one firm listed twice, and the ledger then moves a share back and forth between two identical
 * businesses for no reason a reader could name.
 */
final class SeedIndustryRivalryTest extends TestCase
{
    /**
     * A permanent-capital holding sphere is priced on the net asset value of what it owns, not on the
     * return its own capital earns in a market it competes for, so the market-share physics below is not
     * the law it lives under and its capital legitimately spans more than a sector.
     */
    private const SCALE_EXEMPT_MODELS = ['investment_company'];

    public function testEverySeededOperatingFirmOpensWithSaturationHeadroom(): void
    {
        $metrics = new CorporateMetrics();
        $threshold = FinancialConstants::DISECONOMY_OPTIMAL_SHARE_THRESHOLD;
        $checked = 0;

        foreach (InitialMarket::STOCKS as $row) {
            $model = Sectors::INDUSTRY_METRICS[$row['industry']]['business_model'] ?? 'none';

            // A lender's balance sheet IS its earning assets; it has no plant competing for a product
            // market, and the saturation band is not what limits it.
            if (Sectors::isFinancial($model) || in_array($model, self::SCALE_EXEMPT_MODELS, true)) {
                continue;
            }

            $investedCapital = $metrics->calculateLiveInvestedCapital(
                (float) ($row['total_equity'] ?? 0.0),
                (float) ($row['wholesale_debt'] ?? 0.0),
                (float) ($row['corporate_treasury'] ?? 0.0)
            );
            $share = $metrics->calculateScaleRatio($investedCapital, 1.0, (float) ($row['sam_ratio'] ?? 1.00));
            $checked++;

            $this->assertLessThan(
                $threshold,
                $share,
                sprintf(
                    '%s opens spanning %.1f%% of its serviceable market, at or past the %.0f%% Penrose band, so it '
                    . 'pays bloat and a decayed marginal return from its first quarter. Raise its sam_ratio with '
                    . 'its balance sheet, or cut the balance sheet.',
                    $row['ticker'],
                    100 * $share,
                    100 * $threshold
                )
            );
        }

        $this->assertGreaterThan(50, $checked, 'The seed should carry a broad operating roster to check.');
    }

    public function testFirmsSharingAnIndustryAreTunedApart(): void
    {
        $byIndustry = [];
        foreach (InitialMarket::STOCKS as $row) {
            $byIndustry[$row['industry']][] = $row['ticker'];
        }

        $rivalries = 0;
        foreach ($byIndustry as $industry => $tickers) {
            if (count($tickers) < 2) {
                continue;
            }

            $rivalries++;
            $seen = [];
            foreach ($tickers as $ticker) {
                $overrides = StockModelTuning::OVERRIDES[$ticker] ?? [];
                ksort($overrides);
                $signature = json_encode($overrides);

                $this->assertArrayNotHasKey(
                    $signature,
                    $seen,
                    sprintf(
                        "%s and %s both list in %s and carry identical business model tuning, so the industry ledger "
                        . 'is trading share between two copies of one firm. Give the newer name its own stream mix.',
                        $ticker,
                        $seen[$signature] ?? '',
                        $industry
                    )
                );

                $seen[$signature] = $ticker;
            }
        }

        $this->assertGreaterThan(5, $rivalries, 'The board should carry several contested industries.');
    }
}
