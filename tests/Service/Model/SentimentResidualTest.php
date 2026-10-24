<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MacroConstants;
use App\Service\Model\BusinessModelInterface;
use App\Service\Model\Sector\AdvertisingAgencyBusinessModel;
use App\Service\Model\Sector\InternetRetailBusinessModel;
use App\Service\Model\Sector\LuxuryBusinessModel;
use App\Service\Model\Sector\RestaurantBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The consumer sentiment index already carries the cycle through its fundamentals. A model that prices the gap beside
 * confidence must read the residual, the mood, so when confidence sits exactly where the gap puts it with no mood in
 * it, demand moves through the gap channel alone.
 */
#[Group('unit')]
class SentimentResidualTest extends TestCase
{
    private const GAP = -0.03;

    private static function onTheFit(float $gap): MacroStateDTO
    {
        return new MacroStateDTO(
            outputGapEma: $gap,
            consumerSentimentIndexEma: MacroConstants::SENTIMENT_TREND_LEVEL + (\App\Tests\Support\SentimentOnTheCycle::POINTS_PER_GAP * $gap),
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

    /** Luxury prices the gap beside confidence, so on the fit the client book moves on its gap weight alone. */
    public function testLuxuryReadsConfidenceAsItsMoodBesideTheGap(): void
    {
        $model = new LuxuryBusinessModel();
        $shift = $model->getMacroPhysics(self::stock('PEAC'), self::onTheFit(self::GAP))['macro_demand_shift'];
        $beta = $model->getOperatingCyclicality(self::stock('PEAC'));

        $this->assertEqualsWithDelta(self::GAP * LuxuryBusinessModel::OUTPUT_GAP_DEMAND_WEIGHT * $beta * LuxuryBusinessModel::MACRO_DEMAND_SCALAR, $shift, 1e-9);
    }

    /** Ten points of mood move dining covers by its sensitivity, not one for one as if the mood were the output gap. */
    public function testDiningMovesOnTheMoodAtItsSensitivity(): void
    {
        $model = new RestaurantBusinessModel();
        $calm = $model->getMacroPhysics(self::stock('BREW'), new MacroStateDTO(exchangeRateIndexEma: 100.0))['macro_demand_shift'];
        $buoyant = $model->getMacroPhysics(self::stock('BREW'), new MacroStateDTO(exchangeRateIndexEma: 100.0, consumerSentimentMood: 10.0))['macro_demand_shift'];

        $this->assertEqualsWithDelta(0.10 * RestaurantBusinessModel::DINING_MOOD_SENSITIVITY * $model->getOperatingCyclicality(self::stock('BREW')), $buoyant - $calm, 1e-9);
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
        $math = new ScriptedDraws(generatePersistentZ: 0.0);
        $revenue = fn(float $gap): float => $model->computeActualFinancials(self::stock($ticker), 100.0, 0.30, 10.0, 0.10, self::onTheFit($gap), $math)->streamRevenue[$stream];

        $elasticity = (($revenue(self::GAP) / $revenue(0.0)) - 1.0) / self::GAP;

        $this->assertEqualsWithDelta($gapCoefficient * $model->getOperatingCyclicality(self::stock($ticker)), $elasticity, 1e-6);
    }
}
