<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;
use PHPUnit\Framework\TestCase;

/**
 * Every seeded insurer opens holding the float its book settles at: its reserve ratio times the premium it opens
 * writing, fully backed by cash. Seeded off it, the float ran off or built for years after the opening, and its
 * investment income with it: SAFE opened 38% above its book's float.
 */
final class InsurerOpeningFloatTest extends TestCase
{
    public function testEverySeededInsurerOpensAtItsSteadyFloat(): void
    {
        mt_srand(20261020);
        $math = new MathUtility();
        $builder = new OpeningBoardBuilder($math, new DebtEngine($math, CorporateMetrics::getInstance()), new MarketEngine($math), new AnchorStakeLedger());
        $insurers = 0;

        foreach (InitialMarket::STOCKS as $row) {
            $model = Sectors::getBusinessModelStrategy(Sectors::businessModelFor($row['industry'] ?? null));
            if (!$model instanceof InsuranceBusinessModel) {
                continue;
            }
            $stock = (new Stock())->setTicker($row['ticker']);
            $builder->open($stock, $row, new MacroStateDTO());
            $steady = $model->resolveReserveToPremiumRatio($stock) * (float) $stock->getTotalRevenue();

            $this->assertEqualsWithDelta(1.0, (float) $stock->getCustomerDeposits() / $steady, 0.03, sprintf('%s opens at %.0fB of float against a steady %.0fB.', $row['ticker'], (float) $stock->getCustomerDeposits() / 1e9, $steady / 1e9));
            $this->assertGreaterThanOrEqual((float) $stock->getCustomerDeposits(), (float) $stock->getCorporateTreasury(), "{$row['ticker']}: the float is backed by cash.");
            ++$insurers;
        }

        $this->assertGreaterThanOrEqual(2, $insurers);
    }
}
