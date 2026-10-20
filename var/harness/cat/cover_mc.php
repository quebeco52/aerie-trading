<?php

declare(strict_types=1);

// Measures the insurers' catastrophe cover on the engine's own loss process: the district burden's incurred flow
// between quarterly reports plus the large-loss layer, against the retention. Prints the gross excess tail, the
// limit at the one-in-two-hundred-year quarter and the expected recovery (InsuranceBusinessModel constants).
// Usage: php var/harness/cat/cover_mc.php [seeds=8] [years=3000]

require __DIR__ . '/../../../vendor/autoload.php';

use App\Service\Macro\MacroState;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MathUtility;
use App\Service\Model\Sector\InsuranceBusinessModel;

$seeds = (int) ($argv[1] ?? 8);
$years = (int) ($argv[2] ?? 3000);
$ticksPerYear = 252;
$ticksPerReport = 63;
$retention = InsuranceBusinessModel::MAX_REINSURED_LOSS_SHOCK;

$gross = [];
for ($seed = 1; $seed <= $seeds; ++$seed) {
    $district = new CommodityLogisticsSubsystem(MathUtility::ownStream(7000 + $seed));
    $claims = MathUtility::ownStream(9000 + $seed);
    $state = new MacroState();
    $dt = 1.0 / $ticksPerYear;
    $lastBurden = $state->catastropheLossIndex;
    $lastTime = 0.0;
    for ($tick = 1; $tick <= $years * $ticksPerYear; ++$tick) {
        $district->calculateCatastropheLosses($state, $dt);
        $state->totalTime += $dt;
        if ($tick % $ticksPerReport !== 0) {
            continue;
        }
        $window = $state->totalTime - $lastTime;
        $ratio = CommodityLogisticsSubsystem::catastropheIncurredSince($state->catastropheLossIndex, $lastBurden, $window)
            / CommodityLogisticsSubsystem::expectedCatastropheIncurred($state->totalTime, $window);
        $lastBurden = $state->catastropheLossIndex;
        $lastTime = $state->totalTime;
        if ($tick < 10 * $ticksPerYear) {
            continue;
        }
        $layer = InsuranceBusinessModel::CATASTROPHE_LOSS_SCALAR * max(0.0, InsuranceBusinessModel::CATASTROPHE_Z_THRESHOLD - $claims->generateStandardNormal());
        $gross[] = $layer + (InsuranceBusinessModel::DISTRICT_CATASTROPHE_LOAD * ($ratio - 1.0));
    }
}

$n = count($gross);
$sorted = $gross;
sort($sorted);
$top = $sorted[(int) floor((1.0 - (1.0 / 800.0)) * ($n - 1))];
$limit = InsuranceBusinessModel::CATASTROPHE_COVER_LIMIT;
$recoveries = array_map(static fn(float $g): float => min($limit, max(0.0, $g - $retention)), $gross);
$mean = array_sum($recoveries) / $n;
$variance = array_sum(array_map(static fn(float $r): float => ($r - $mean) ** 2, $recoveries)) / $n;
$attached = count(array_filter($gross, static fn(float $g): bool => $g > $retention));

printf("quarters %d  one-in-200-year gross %.3f (limit at it %.3f, constant %.3f)\n", $n, $top, $top - $retention, $limit);
printf("expected recovery %.5f/q (se %.5f, constant %.5f)  attaches %.4f/q, one year in %.1f\n", $mean, sqrt($variance / $n), InsuranceBusinessModel::CATASTROPHE_COVER_EXPECTED_RECOVERY, $attached / $n, $n / max(1, $attached) / 4.0);
