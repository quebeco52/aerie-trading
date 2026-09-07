<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Sectors;
use PHPUnit\Framework\TestCase;

/**
 * Validates that all sector business model strategies provide mathematically consistent
 * seasonality factors: exactly 4 quarterly factors summing to 4.0 within tolerance.
 */
class SectorSeasonalityInvariantTest extends TestCase
{
    public function testAllBusinessModelStrategiesProvideValidSeasonalityFactors(): void
    {
        $businessModels = [
            'standard',
            'commercial_bank',
            'investment_bank',
            'shadow_bank',
            'reit',
            'insurance',
            'reinsurance',
            'private_equity',
            'hedge_fund',
            'clearing_house',
            'software',
            'semiconductor',
            'biopharma',
            'medical_devices',
            'pharmaceutical',
            'healthcare_plans',
            'healthcare_providers',
            'aerospace_defense',
            'automotive',
            'heavy_manufacturing',
            'industrial_machinery',
            'conglomerate',
            'construction',
            'steel_manufacturing',
            'metals_mining',
            'chemicals',
            'commodities',
            'internet_retail',
            'apparel_manufacturing',
            'consumer_staples',
            'luxury_goods',
            'restaurants',
            'resorts_casinos',
            'railroad',
            'shipping',
            'logistics',
            'telecom',
            'cable_satellite',
            'entertainment',
            'advertising_agency',
            'utility',
            'renewable_energy',
            'oil_gas_ep',
            'oil_gas_integrated',
            'oil_gas_midstream',
            'oil_gas_refining',
            'oil_gas_equipment',
        ];

        foreach ($businessModels as $modelKey) {
            $strategy = Sectors::getBusinessModelStrategy($modelKey);
            $factors = $strategy->getSeasonalityFactors();

            $this->assertCount(
                4,
                $factors,
                sprintf('Business model "%s" must provide exactly 4 quarterly seasonality factors.', $modelKey)
            );

            foreach ($factors as $qIndex => $factor) {
                $this->assertGreaterThan(
                    0.0,
                    $factor,
                    sprintf('Quarter %d factor for business model "%s" must be strictly positive.', $qIndex + 1, $modelKey)
                );
            }

            $sum = array_sum($factors);
            $this->assertEqualsWithDelta(
                4.0,
                $sum,
                1e-4,
                sprintf('Seasonality factors for business model "%s" must sum to 4.00, got %.4f.', $modelKey, $sum)
            );
        }
    }
}
