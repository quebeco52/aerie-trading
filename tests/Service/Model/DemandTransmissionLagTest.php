<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Model\Sector\ConstructionBusinessModel;
use App\Service\Model\Sector\LuxuryBusinessModel;
use App\Service\Model\Sector\StandardCorporateBusinessModel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Demand does not reach every business at the same speed. A restaurant feels a recession the week it
 * starts; a builder is still working through a backlog ordered into a different economy, and only meets
 * the downturn when the next round of budgets is set.
 */
final class DemandTransmissionLagTest extends TestCase
{
    private function makeStock(): Stock
    {
        $stock = new Stock();
        $stock->setTicker('ZZZZ');
        $stock->setBeta('1.0');

        return $stock;
    }

    /** A spot business reads the cycle as it is; nothing is stored and nothing is delayed. */
    public function testZeroLagSectorTracksTheCycleContemporaneously(): void
    {
        $model = new LuxuryBusinessModel();
        $stock = $this->makeStock();

        $this->assertSame(0.0, $model->getDemandLagYears(), 'Luxury demand is immediate by declaration.');
        $this->assertSame(
            0.04,
            $model->resolveLaggedOutputGap($stock, new MacroStateDTO(outputGapEma: 0.04)),
            'A zero-lag firm must see the macro series untouched.'
        );
        $this->assertNull($stock->getLaggedDemandGap(), 'A zero-lag firm has no cycle position to carry.');
    }

    /** A long-lag firm meets a downturn gradually, and is still above the macro gap while it falls. */
    public function testLongLagSectorTrailsTheCycleIntoADownturn(): void
    {
        $model = new ConstructionBusinessModel();
        $stock = $this->makeStock();

        // The firm starts in a boom, so its first read anchors there rather than jumping from zero.
        $boom = new MacroStateDTO(outputGapEma: 0.04);
        $this->assertEqualsWithDelta(0.04, $model->resolveLaggedOutputGap($stock, $boom), 1e-9);

        // The economy drops into recession and stays there.
        $slump = new MacroStateDTO(outputGapEma: -0.04);
        $first = $model->resolveLaggedOutputGap($stock, $slump);
        $second = $model->resolveLaggedOutputGap($stock, $slump);
        $third = $model->resolveLaggedOutputGap($stock, $slump);

        $this->assertGreaterThan(-0.04, $first, 'The backlog cushions the first quarter of the slump.');
        $this->assertLessThan($first, $second, 'Each quarter carries the firm further into it.');
        $this->assertLessThan($second, $third);
        $this->assertGreaterThan(-0.04, $third, 'Even three quarters in, a long-lag builder has not fully met the macro gap.');
    }

    /** Given enough time at one level, the lag converges: it delays the cycle, it does not dampen it. */
    public function testLagConvergesRatherThanPermanentlyDamping(): void
    {
        $model = new ConstructionBusinessModel();
        $stock = $this->makeStock();
        $slump = new MacroStateDTO(outputGapEma: -0.04);

        $model->resolveLaggedOutputGap($stock, new MacroStateDTO(outputGapEma: 0.04));
        for ($q = 0; $q < 60; $q++) {
            $value = $model->resolveLaggedOutputGap($stock, $slump);
        }

        $this->assertEqualsWithDelta(-0.04, $value, 1e-4, 'A permanent shift must arrive in full eventually.');
    }

    /** The lag is a firm-level state, so two firms in the same economy can sit at different points. */
    public function testEachFirmCarriesItsOwnPositionInTheCycle(): void
    {
        $model = new ConstructionBusinessModel();
        $slump = new MacroStateDTO(outputGapEma: -0.04);

        $veteran = $this->makeStock();
        $model->resolveLaggedOutputGap($veteran, new MacroStateDTO(outputGapEma: 0.04));
        $model->resolveLaggedOutputGap($veteran, $slump);

        // A firm meeting the same economy for the first time anchors on it directly.
        $newcomer = $this->makeStock();
        $newcomerGap = $model->resolveLaggedOutputGap($newcomer, $slump);

        $this->assertGreaterThan(
            $newcomerGap,
            $model->resolveLaggedOutputGap($veteran, $slump),
            'The firm that entered the slump holding a boom-era book is still ahead of one that never had it.'
        );
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function laggedModelProvider(): array
    {
        $models = [];
        foreach (glob(dirname(__DIR__, 3) . '/src/Service/Model/Sector/*BusinessModel.php') ?: [] as $file) {
            /** @var class-string $className */
            $className = 'App\\Service\\Model\\Sector\\' . basename($file, '.php');
            $ref = new ReflectionClass($className);
            if ($ref->isAbstract() || !$ref->hasConstant('DEMAND_LAG_YEARS')) {
                continue;
            }
            $models[basename($file, '.php')] = [$className];
        }

        return $models;
    }

    /**
     * A declared lag has to actually reach the demand path, or it is a constant that documents a
     * behaviour the model does not have.
     */
    #[DataProvider('laggedModelProvider')]
    public function testDeclaredLagChangesTheDemandTheModelSees(string $modelClass): void
    {
        $model = new $modelClass();
        $stock = $this->makeStock();

        $this->assertGreaterThan(0.0, $model->getDemandLagYears(), $modelClass . ' declares a lag of zero, which is the default.');

        $model->resolveLaggedOutputGap($stock, new MacroStateDTO(outputGapEma: 0.04));
        $lagged = $model->resolveLaggedOutputGap($stock, new MacroStateDTO(outputGapEma: -0.04));

        $this->assertGreaterThan(-0.04, $lagged, $modelClass . ' declares a demand lag but tracks the cycle contemporaneously.');
    }

    /** The generic model is contemporaneous, so the fallback stays the neutral case. */
    public function testStandardCorporateIsContemporaneousByDefault(): void
    {
        $this->assertSame(0.0, (new StandardCorporateBusinessModel())->getDemandLagYears());
    }
}
