<?php

declare(strict_types=1);

namespace App\Tests\Financial;

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\BiotechBusinessModel;
use PHPUnit\Framework\TestCase;

/**
 * Long-run invariants of the drug pipeline and cohort ledger, on IBIS's lore over many seeds: launches replace what
 * expiries take at replacement R&D, and the protected share settles where the drug lifecycle puts it instead of
 * draining away.
 */
class BiotechPipelineInvariantTest extends TestCase
{
    private const SEEDS = 16;
    private const YEARS = 30;

    /**
     * Year-end franchise and protected share per seed, run on the physics alone with the engine's forecast fed back.
     *
     * @return array{franchise: list<list<float>>, protected: list<list<float>>, approvals: int, setbacks: int}
     */
    private function simulate(float $rndRatio): array
    {
        $model = new BiotechBusinessModel();
        $franchise = [];
        $protected = [];
        $approvals = 0;
        $setbacks = 0;

        for ($seed = 0; $seed < self::SEEDS; $seed++) {
            $math = MathUtility::ownStream(20261006 + $seed);
            $stock = new Stock();
            $stock->setTicker('IBIS');
            $stock->setBeta('1.0');

            for ($q = 0; $q < self::YEARS * 4; $q++) {
                $state = $stock->getEarningsMomentumZ() ?? [];
                $state[BiotechBusinessModel::STATE_RND_REPLACEMENT_RATIO] = $rndRatio;
                $stock->setEarningsMomentumZ($state);
                $expected = 100.0e6 * $model->getStructuralRevenueMultiplier($stock)
                    * (1.0 + (float) ($state[BiotechBusinessModel::STATE_KNOWN_COMMERCIAL_SHIFT] ?? 0.0));

                $result = $model->computeActualFinancials($stock, $expected, 0.30, 20.0e6, 0.10, new MacroStateDTO(), $math);
                $stock->setEarningsMomentumZ($result->streamZ);
                $approvals += $result->eventType === \App\Service\Event\ShockEvent::BIOTECH_DRUG_APPROVAL ? 1 : 0;
                $setbacks += $result->eventType === \App\Service\Event\ShockEvent::BIOTECH_TRIAL_SETBACK ? 1 : 0;

                if ($q % 4 === 3) {
                    $franchise[$seed][] = $result->streamZ[BiotechBusinessModel::STATE_FRANCHISE_INDEX];
                    $protected[$seed][] = $result->streamZ[BiotechBusinessModel::STATE_PROTECTED_SHARE];
                }
            }
        }

        return ['franchise' => $franchise, 'protected' => $protected, 'approvals' => $approvals, 'setbacks' => $setbacks];
    }

    /** @param list<list<float>> $paths */
    private function meanOverYears(array $paths, int $fromYear, int $toYear): float
    {
        $values = [];
        foreach ($paths as $path) {
            $values = array_merge($values, array_slice($path, $fromYear - 1, $toYear - $fromYear + 1));
        }

        return array_sum($values) / count($values);
    }

    public function testTheBookIsStationaryAndTheProtectedShareHoldsAtReplacementRnd(): void
    {
        $run = $this->simulate(1.0);

        // Launches replace expiries: the book neither compounds nor drains over three decades.
        $late = $this->meanOverYears($run['franchise'], 20, self::YEARS);
        $this->assertGreaterThan(0.85, $late);
        $this->assertLessThan(1.15, $late);

        // The protected share settles where the lifecycle puts it (~0.68 of a book with a mature established line)
        // and never drains toward the off-patent residual the way a fold into the base did (0.32 by year 30).
        $settled = $this->meanOverYears($run['protected'], 10, self::YEARS);
        $this->assertGreaterThan(0.58, $settled);
        $this->assertLessThan(0.80, $settled);
        foreach ($run['protected'] as $seed => $path) {
            $this->assertGreaterThan(0.40, array_sum(array_slice($path, 9)) / (self::YEARS - 9), "Seed {$seed}");
        }

        // Readouts arrive at big-pharma frequency, not once every five years.
        $this->assertGreaterThan(0.3 * self::SEEDS * self::YEARS, $run['approvals']);
        $this->assertGreaterThan(0.2 * self::SEEDS * self::YEARS, $run['setbacks']);
    }

    public function testAStarvedPipelineShrinksTheBook(): void
    {
        $starved = $this->meanOverYears($this->simulate(0.5)['franchise'], 20, self::YEARS);
        $replacement = $this->meanOverYears($this->simulate(1.0)['franchise'], 20, self::YEARS);

        $this->assertLessThan($replacement - 0.15, $starved);
    }
}
