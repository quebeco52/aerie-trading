<?php

declare(strict_types=1);

namespace App\Tests\Service\Model;

use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Math\FinancialConstants;
use App\Service\Model\Sector\FinancialDataBusinessModel;
use App\Service\Model\Sector\OilGasProducerBusinessModel;
use PHPUnit\Framework\TestCase;

/**
 * A sector's secular growth is trend growth plus its measured demand drift at the open, faded toward trend; every
 * consumer outside the models reads the faded rate, so no engine keeps a sector on its opening drift forever.
 */
final class SecularGrowthTest extends TestCase
{
    public function testAFastSectorsExcessHalvesEveryHalfLifeAndSettlesOnTrend(): void
    {
        $model = new FinancialDataBusinessModel();
        $stock = new Stock();
        $opening = $model->getSecularGrowthRate($stock);

        // Data processing and information services went from 0.37% to 1.27% of GDP over 1997-2019.
        self::assertEqualsWithDelta(MacroEngine::TREND_REAL_GROWTH + log(1.27 / 0.37) / 22.0, $opening, 1e-9);
        self::assertSame($opening, $model->getFadedSecularGrowthRate($stock, 0.0));
        self::assertEqualsWithDelta(
            MacroEngine::TREND_REAL_GROWTH + (($opening - MacroEngine::TREND_REAL_GROWTH) / 2.0),
            $model->getFadedSecularGrowthRate($stock, FinancialConstants::SECULAR_EXCESS_HALF_LIFE_YEARS),
            1e-12
        );
        self::assertEqualsWithDelta(MacroEngine::TREND_REAL_GROWTH, $model->getFadedSecularGrowthRate($stock, 500.0), 1e-9);
    }

    /** A commodity producer's share of GDP moves with the price its commodity index already carries. */
    public function testACommodityProducerGrowsWithTheEconomy(): void
    {
        self::assertSame(MacroEngine::TREND_REAL_GROWTH, (new OilGasProducerBusinessModel())->getSecularGrowthRate(new Stock()));
    }

    /** Outside the models, only the industry ledger reads the opening rate, and it integrates the same fade. */
    public function testEveryConsumerReadsTheFadedRate(): void
    {
        $root = \dirname(__DIR__, 3) . '/src';
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = (string) $file;
            if (!str_ends_with($path, '.php') || str_contains($path, '/Service/Model/') || str_ends_with($path, '/IndustryShareLedger.php')) {
                continue;
            }
            if (str_contains((string) file_get_contents($path), '->getSecularGrowthRate(')) {
                $offenders[] = substr($path, \strlen($root) + 1);
            }
        }

        self::assertSame([], $offenders, 'Read getFadedSecularGrowthRate(): the opening rate held forever compounds a sector without bound.');
    }
}
