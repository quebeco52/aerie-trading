<?php
// What the market's forecast does to prices: the politics alone on an economy at trend, one tick a month, priced as
// MarketEngine prices through PolicyCapitalization for four representative firms: an operating firm (k - g 4%, the
// statutory rate as its effective rate), a bank whose levy base is five times its value (k - g 5%), a miner whose
// extraction cost base is 26% of its fair value at k - g 9% (CNDR 0.29 at 9.7%, SINK 0.22 at 8.5%) and a broker whose
// turnover base is 25% of its fair value at k - g 10.6% (ROOK), as the real MarketEngine values them on a seeded board
// after eight quarters (ExtractionMarginTest).
// The macro's lags are stepped as CreditFiscalSubsystem steps them. Reports the moves in log value on poll months,
// on the vote, when the government takes office, and each firm's level against no policy pricing.
// php pricing_probe.php <seeds> <years>
// Per-seed variant (pricing_probe_seeds.php): php pricing_probe_seeds.php <seed> <years> <out.json>; writes the raw moves
// and levels of one seed for pricing_probe_agg.php. The loop body is pricing_probe.php's, unchanged.
require dirname(__DIR__, 3) . '/tests/bootstrap.php';

use App\DTO\MacroStateDTO;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Market\PolicyCapitalization as Policy;
use App\Service\Math\FirmEconomics;
use App\Service\Math\MacroTransmission;
use App\Service\Math\MathUtility;
use App\Service\Politics\PoliticsEngine;
use App\Service\Politics\PoliticsState;

[$script, $onlySeed, $years, $outFile] = $argv;
$started = microtime(true);
$redis = new class extends \Redis {
    private array $store = [];
    public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
    public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
};
$economy = static fn(float $t): MacroStateDTO => new MacroStateDTO(
    totalTime: $t,
    potentialGdpIndex: exp((MacroEngine::STRUCTURAL_LABOR_GROWTH_RATE + MacroEngine::TFP_DRIFT) * $t),
    gdpDeflator: exp(MacroEngine::TARGET_INFLATION * $t),
    sovereignDebtToGdp: 0.6,
);
$value = static function (MacroStateDTO $m, bool $previous, float $capRate, array $bases): float {
    $tax = MacroEngine::TARGET_CORPORATE_TAX_RATE + $m->corporateTaxShiftRealized;
    $expected = $tax + Policy::corporateTaxShiftGap($m, $capRate, $previous);

    return log(Policy::reprice(1.0, $tax, $expected, Policy::earningsGap($m, $bases, $expected, $capRate, $previous), $capRate));
};
$firms = [
    'firm' => [0.04, []],
    'bank' => [0.05, ['bankLevyRate' => 5.0]],
    'miner' => [0.09, ['extractionStringency' => 0.26]],
    'broker' => [0.106, ['stampDutyRate' => 0.25]],
];

$moves = ['poll' => [], 'vote' => [], 'office' => []];
$levels = array_fill_keys(array_keys($firms), []);
$dt = 1.0 / 12.0;
for ($seed = (int) $onlySeed; $seed <= (int) $onlySeed; ++$seed) {
    $engine = new PoliticsEngine(MathUtility::ownStream($seed), $redis);
    $state = new PoliticsState();
    $realized = 0.0;
    $embodied = 0.0;
    $levyEmbodied = 0.0;
    $extractionEmbodied = 1.0;
    $dutyEmbodied = 1.0;
    $levers = [[], -1.0, [], -1.0];
    for ($month = 1; $month <= 12 * (int) $years; ++$month) {
        $engine->advance($state, $economy($month * $dt), $dt);
        $policy = \App\DTO\PoliticsStateDTO::fromState($state)->policy();
        $previous = $levers;
        $levers = [$policy->expectedLevers ?? [], $policy->expectedLevers === null ? -1.0 : $policy->expectedPolicyFrom, $policy->sittingLevers ?? [], $policy->sittingLevers === null ? -1.0 : $policy->sittingPolicyFrom];
        $realized += MacroEngine::FISCAL_ADJUSTMENT_SPEED * ($policy->corporateTaxPolicyShift - $realized) * $dt;
        $embodied += ($realized - $embodied) * $dt / CreditFiscalSubsystem::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        $levyEmbodied += ($policy->bankLevyRate - $levyEmbodied) * $dt / CreditFiscalSubsystem::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        $extractionEmbodied += (FirmEconomics::calculateExtractionCostFactor($policy->extractionStringency) - $extractionEmbodied) * $dt / CreditFiscalSubsystem::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        $dutyEmbodied += (MacroTransmission::calculateStampDutyVolumeFactor($policy->stampDutyRate) - $dutyEmbodied) * $dt / CreditFiscalSubsystem::TRAILING_EARNINGS_MEAN_LAG_YEARS;
        $m = new MacroStateDTO(
            totalTime: $state->totalTime,
            corporateTaxPolicyShift: $policy->corporateTaxPolicyShift,
            bankLevyRate: $policy->bankLevyRate,
            extractionStringency: $policy->extractionStringency,
            stampDutyRate: $policy->stampDutyRate,
            corporateTaxShiftRealized: $realized,
            corporateTaxShiftEmbodied: $embodied,
            bankLevyEmbodied: $levyEmbodied,
            extractionCostFactorEmbodied: $extractionEmbodied,
            stampDutyVolumeFactorEmbodied: $dutyEmbodied,
            expectedLevers: $levers[0],
            expectedPolicyFrom: $levers[1],
            previousExpectedLevers: $previous[0],
            previousExpectedPolicyFrom: $previous[1],
            sittingLevers: $levers[2],
            sittingPolicyFrom: $levers[3],
            previousSittingLevers: $previous[2],
            previousSittingPolicyFrom: $previous[3],
        );
        if ($month <= 48) {
            continue;
        }
        foreach ($firms as $name => [$capRate, $bases]) {
            // The operating firm's level is the tax's; the others' is their own law's, beyond the tax.
            $levels[$name][] = $name === 'firm' ? $value($m, false, $capRate, []) : $value($m, false, $capRate, $bases) - $value($m, false, $capRate, []);
        }
        if ($levers === $previous) {
            continue;
        }
        $kind = match (true) {
            $state->lastElectionAt === $state->totalTime => 'vote',
            $state->lastGovernmentFormedAt === $state->totalTime => 'office',
            default => 'poll',
        };
        $jump = [];
        foreach ($firms as $name => [$capRate, $bases]) {
            $jump[$name] = $value($m, false, $capRate, $bases) - $value($m, true, $capRate, $bases);
            if ($bases !== []) {
                // The firm's own law alone, the tax's part taken out.
                $jump[$name . ' law'] = $jump[$name] - ($value($m, false, $capRate, []) - $value($m, true, $capRate, []));
            }
        }
        $moves[$kind][] = $jump;
        if (getenv('TRACE') && $kind === 'poll' && abs($jump['broker law']) > 0.05) {
            fprintf(STDERR, "seed %d t %.3f since vote %.2f broker law %+.3f duty in force %.5f sitting %s from %.2f | expected %.5f -> %.5f from %.2f -> %.2f\n",
                $seed, $state->totalTime, $state->totalTime - $state->lastElectionAt, $jump['broker law'], $policy->stampDutyRate,
                json_encode($levers[2]['stampDutyRate'] ?? null), $levers[3],
                $previous[0]['stampDutyRate'] ?? -1, $levers[0]['stampDutyRate'] ?? -1, $previous[1], $levers[1]);
            fprintf(STDERR, "   previous sitting %s from %.2f; embodied D %.4f; formed %.3f; forecastFor %.2f\n", json_encode($previous[2]['stampDutyRate'] ?? null), $previous[3], $dutyEmbodied, $state->lastGovernmentFormedAt, $state->forecastFor);
            fprintf(STDERR, "   talks started %.3f takes office %.3f cabinet fell %.3f falls at %.3f coalition %s\n", $state->talksStartedAt, $state->coalitionTakesOfficeAt, $state->lastCabinetFellAt, $state->cabinetFallsAt, json_encode($state->governingCoalition));
        }
    }
}

file_put_contents($outFile, json_encode(['seed' => (int) $onlySeed, 'years' => (int) $years, 'seconds' => microtime(true) - $started, 'moves' => $moves, 'levels' => array_map(static fn(array $xs): array => ['mean' => array_sum($xs) / count($xs), 'n' => count($xs)], $levels)], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
printf("seed %d done in %.1f s\n", (int) $onlySeed, microtime(true) - $started);
