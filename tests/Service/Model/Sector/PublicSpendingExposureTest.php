<?php

declare(strict_types=1);

namespace App\Tests\Service\Model\Sector;

use App\DTO\ActualFinancialsDTO;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\FinancialConstants;
use App\Service\Math\MacroConstants;
use App\Service\Model\Sector\ConstructionBusinessModel;
use App\Service\Model\Sector\EducationBusinessModel;
use App\Service\Model\Sector\MedicalCareFacilityBusinessModel;
use App\Service\Model\Sector\SecurityProtectionBusinessModel;
use App\Service\Model\Sector\UtilityBusinessModel;
use App\Service\Model\Strategy\OperatingStrategyInterface;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The firms the budget buys from report what a unit of purchases above the founding level adds to their earnings
 * before tax, as a share of revenue, which the market prices a change in public spending against
 * (App\Service\Market\Pricing\PolicyCapitalization::publicSpendingGap()).
 */
#[Group('unit')]
class PublicSpendingExposureTest extends TestCase
{
    /**
     * Where the government stream bills as it is bought, the reported share is what the extra purchases add to
     * operating earnings.
     */
    public function testTheReportedShareIsWhatPurchasesAddToEarnings(): void
    {
        foreach ([new MedicalCareFacilityBusinessModel(), new SecurityProtectionBusinessModel()] as $model) {
            $without = $this->report($model, 0.0);
            $with = $this->report($model, 0.02);
            $share = $without->streamZ[FinancialConstants::STATE_PUBLIC_SPENDING_EARNINGS_SHARE];

            $this->assertGreaterThan(0.0, $share, $model::class);
            $this->assertEqualsWithDelta($share * $without->actualRevenue * 0.02, $with->ebit - $without->ebit, 1e-6 * $without->actualRevenue, $model::class);
        }
    }

    /**
     * Contractors and colleges book public work into a backlog that burns at the rate it is ordered, so they report a
     * share too; a firm the budget does not buy from reports none.
     */
    public function testOnlyTheFirmsTheBudgetBuysFromReportAShare(): void
    {
        foreach ([new ConstructionBusinessModel(), new EducationBusinessModel()] as $model) {
            $this->assertGreaterThan(0.0, $this->report($model, 0.0)->streamZ[FinancialConstants::STATE_PUBLIC_SPENDING_EARNINGS_SHARE] ?? 0.0, $model::class);
        }
        $this->assertArrayNotHasKey(FinancialConstants::STATE_PUBLIC_SPENDING_EARNINGS_SHARE, $this->report(new UtilityBusinessModel(), 0.0)->streamZ);
    }

    private function report(OperatingStrategyInterface $model, float $purchases): ActualFinancialsDTO
    {
        $stock = new Stock();
        $stock->setTicker('TEST');
        $stock->setBeta('0.5');
        $math = new ScriptedDraws(generatePersistentZ: 0.0);
        $macro = new MacroStateDTO(inflationEma: MacroConstants::TARGET_INFLATION, governmentSpendingIndexEma: MacroConstants::GOVT_SPENDING_BASELINE * (1.0 + $purchases));

        return $model->computeActualFinancials($stock, 100_000_000.0, 0.40, 20_000_000.0, 0.10, $macro, $math);
    }
}
