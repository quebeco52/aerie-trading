<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\MathUtility;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\AdvertisingAgencyBusinessModel;
use App\Service\Model\Sector\InternetRetailBusinessModel;
use App\Service\Model\Sector\RestaurantBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The consumer sentiment index already carries 3.85x the output gap (sentEma = 88 + 385 x gap). A model that
 * prices the gap beside confidence must read the residual, so when confidence sits exactly where the gap puts
 * it, demand moves through the gap channel alone.
 */
class SentimentResidualTest extends TestCase
{
    private const GAP = -0.03;

    private static function onTheFit(float $gap): MacroStateDTO
    {
        return new MacroStateDTO(
            outputGapEma: $gap,
            consumerSentimentIndexEma: MacroEngine::SENTIMENT_TREND_LEVEL + (MacroEngine::SENTIMENT_GAP_LOADING * $gap),
            exchangeRateIndexEma: 100.0,
        );
    }

    private static function stock(string $ticker): Stock
    {
        $stock = new Stock();
        $stock->setTicker($ticker);
        $stock->setBeta('1.0');

        return $stock;
    }

    /** @return iterable<string, array{BusinessModelInterface, string}> */
    public static function rootShiftModels(): iterable
    {
        yield 'restaurant BREW' => [new RestaurantBusinessModel(), 'BREW'];
    }

    #[DataProvider('rootShiftModels')]
    public function testConfidenceTheGapExplainsAddsNothingToTheRootShift(BusinessModelInterface $model, string $ticker): void
    {
        // The parent's output-gap shift, evaluated with this model's own constants and pricing power.
        $parentShift = (new \ReflectionMethod(StandardCorporateBusinessModel::class, 'getMacroPhysics'))
            ->invoke($model, self::stock($ticker), self::onTheFit(self::GAP))['macro_demand_shift'];

        $shift = $model->getMacroPhysics(self::stock($ticker), self::onTheFit(self::GAP))['macro_demand_shift'];

        $this->assertEqualsWithDelta($parentShift, $shift, 1e-9);
    }

    public function testFirstPartyRetailMovesOnItsOwnGapCoefficient(): void
    {
        $this->assertStreamElasticity(new InternetRetailBusinessModel(), 'WEAV', 'first_party_retail', 2.0);
    }

    public function testMediaBuyingMovesOnItsOwnGapCoefficient(): void
    {
        $this->assertStreamElasticity(new AdvertisingAgencyBusinessModel(), 'LYRE', 'media_buying_commissions', 1.5);
    }

    private function assertStreamElasticity(BusinessModelInterface $model, string $ticker, string $stream, float $gapCoefficient): void
    {
        $math = $this->createStub(MathUtility::class);
        $math->method('generatePersistentZ')->willReturn(0.0);
        $revenue = fn (float $gap): float => $model->computeActualFinancials(self::stock($ticker), 100.0, 0.30, 10.0, 0.10, self::onTheFit($gap), $math)->streamRevenue[$stream];

        $elasticity = (($revenue(self::GAP) / $revenue(0.0)) - 1.0) / self::GAP;

        $this->assertEqualsWithDelta($gapCoefficient * $model->getOperatingCyclicality(self::stock($ticker)), $elasticity, 1e-6);
    }
}
