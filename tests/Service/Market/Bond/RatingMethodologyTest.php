<?php

declare(strict_types=1);

namespace App\Tests\Service\Market\Bond;

use App\Data\Company\Sectors;
use App\Service\Market\Bond\RatingMethodology;
use App\Service\Model\Sector\BaseFinancialBusinessModel;
use App\Service\Model\Sector\ReitBusinessModel;
use PHPUnit\Framework\TestCase;

/**
 * Each business model declares the methodology the agency rates it on, so a new model is rated the way its own
 * constant says rather than the way its parent class happens to fall.
 */
class RatingMethodologyTest extends TestCase
{
    /** Every model not rated on the corporate methodology, by its identifier. */
    private const NOT_CORPORATE = [
        'asset_manager' => RatingMethodology::AssetManager,
        'brokerage' => RatingMethodology::CapitalRated,
        'clearing_house' => RatingMethodology::CapitalRated,
        'commercial_bank' => RatingMethodology::CapitalRated,
        'credit_services' => RatingMethodology::CapitalRated,
        'custody_bank' => RatingMethodology::CapitalRated,
        'distressed_debt' => RatingMethodology::BalanceSheetInvestor,
        'hedge_fund' => RatingMethodology::BalanceSheetInvestor,
        'insurance' => RatingMethodology::CapitalRated,
        'investment_bank' => RatingMethodology::CapitalRated,
        'private_bank' => RatingMethodology::CapitalRated,
        'private_equity' => RatingMethodology::BalanceSheetInvestor,
        'reinsurance' => RatingMethodology::CapitalRated,
        'reit' => RatingMethodology::RealEstate,
        'retail_insurance' => RatingMethodology::CapitalRated,
        'shadow_bank' => RatingMethodology::CapitalRated,
        'utility' => RatingMethodology::RegulatedUtility,
    ];

    public function testEveryBusinessModelIsRatedOnItsDeclaredMethodology(): void
    {
        foreach (self::businessModels() as $id) {
            $this->assertSame(
                self::NOT_CORPORATE[$id] ?? RatingMethodology::Corporate,
                Sectors::getBusinessModelStrategy($id)->getRatingMethodology(),
                $id,
            );
        }
        $this->assertSame([], array_diff(array_keys(self::NOT_CORPORATE), self::businessModels()), 'Every pinned model exists.');
    }

    /** Altman's samples held no financial and no REIT: those are scored on their capital, everything else on Z''. */
    public function testOnlyFinancialsAndRealEstateAreScoredOnTheirCapital(): void
    {
        foreach (self::businessModels() as $id) {
            $strategy = Sectors::getBusinessModelStrategy($id);

            $this->assertSame(
                $strategy instanceof BaseFinancialBusinessModel || $strategy instanceof ReitBusinessModel,
                $strategy->getRatingMethodology()->scoresSolvencyOnCapital(),
                $id,
            );
        }
    }

    /** @return list<string> */
    private static function businessModels(): array
    {
        return array_values(array_unique(array_column(Sectors::INDUSTRY_METRICS, 'business_model')));
    }
}
