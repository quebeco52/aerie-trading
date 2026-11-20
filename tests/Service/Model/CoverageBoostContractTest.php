<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Data\Company\Sectors;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Model\AbstractBusinessModel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Larger firms get more analyst coverage from live market cap, whatever model they run on, while models that declare
 * announced-event visibility keep that channel unaffected.
 */
#[Group('unit')]
final class CoverageBoostContractTest extends TestCase
{
    public function testEveryModelGivesLargerFirmsTheCoverageBoost(): void
    {
        $unpriced = (new Stock())->setTicker('ZZZZ');
        $midCap = (new Stock())
            ->setTicker('ZZZZ')
            ->setSharesOutstanding('1000000000')
            ->setPrice((string) (MacroConstants::DISTRICT_GDP_USD * 0.04 / 1_000_000_000.0));
        $megaCap = (new Stock())
            ->setTicker('ZZZZ')
            ->setSharesOutstanding('1000000000')
            ->setPrice((string) (MacroConstants::DISTRICT_GDP_USD * 0.20 / 1_000_000_000.0));

        $midScale = log(0.04 / AbstractBusinessModel::COVERAGE_SIZE_FLOOR_GDP_SHARE)
            / log(AbstractBusinessModel::COVERAGE_SIZE_CEILING_GDP_SHARE / AbstractBusinessModel::COVERAGE_SIZE_FLOOR_GDP_SHARE);

        foreach (array_keys(Sectors::BUSINESS_MODELS) as $identifier) {
            $model = Sectors::getBusinessModelStrategy($identifier);
            $plain = $model->getCoverageProfile($unpriced);

            foreach ([
                'mid' => [$midCap, AbstractBusinessModel::COVERAGE_MAX_VISIBILITY_BOOST * $midScale, AbstractBusinessModel::COVERAGE_MAX_MIN_VISIBILITY_BOOST * $midScale],
                'mega' => [$megaCap, AbstractBusinessModel::COVERAGE_MAX_VISIBILITY_BOOST, AbstractBusinessModel::COVERAGE_MAX_MIN_VISIBILITY_BOOST],
            ] as $label => [$stock, $visibility, $floor]) {
                $boosted = $model->getCoverageProfile($stock);
                $expectedVis = min(1.0, $plain->baseVisibility + $visibility);
                $expectedMin = min($expectedVis, $plain->minVisibility + $floor);
                $this->assertEqualsWithDelta($expectedVis, $boosted->baseVisibility, 1e-12, "{$identifier} {$label}");
                $this->assertEqualsWithDelta($expectedMin, $boosted->minVisibility, 1e-12, "{$identifier} {$label}");
                $this->assertSame($plain->eventBaseVisibility, $boosted->eventBaseVisibility, "{$identifier} {$label}");
            }
        }
    }
}
