<?php

declare(strict_types=1);

use App\Data\Company\InitialMarket;
use App\Data\Company\Sectors;
use App\DTO\MacroStateDTO;
use App\DTO\MarketPricingContext;
use App\Entity\Stock;
use App\Service\Corporate\CorporateMetrics;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\Holdings\AnchorStakeLedger;
use App\Service\Corporate\Industry\InMemoryIndustryShareStore;
use App\Service\Corporate\Industry\IndustryShareLedger;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Market\Pricing\MarketEngine;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Math\MacroConstants;
use App\Service\Math\MathUtility;
use App\Tests\Support\CorporateEngineFactory;
use App\Tests\Support\ScriptedDraws;
use PHPUnit\Framework\TestCase;

/**
 * Opening settle: does each firm, opened on its seed row, stay where it opened?
 *
 * The board opens as the seed does. The economy is held at the opening (or runs its own noiseless path). Each firm then
 * reports through its EarningsEngine in time order on one industry ledger, with the market pricing it at fair value
 * after each report: no deals, no outlook changes, no order flow, no failure sweep. What still moves is each firm's own
 * model, and its rivals' supply, pulling its seed toward where the model wants it.
 *
 * Env: YEARS (10), TPY (360), SEED (1), MACRO (frozen | path), DRAWS (none = noiseless firm draws | seeded = the
 * shared stream from SEED), TICKERS (comma list, default all), OUT (jsonl, one row per report). See RUN.md.
 */
final class SettleHarnessTest extends TestCase
{
    use CorporateEngineFactory;

    public function testSettle(): void
    {
        $years = (int) (getenv('YEARS') ?: 10);
        $tpy = (int) (getenv('TPY') ?: 360);
        $seed = (int) (getenv('SEED') ?: 1);
        $draws = getenv('DRAWS') ?: 'none';
        $macroMode = getenv('MACRO') ?: 'frozen';
        $out = getenv('OUT') ?: __DIR__ . '/runs/settle.jsonl';
        $only = getenv('TICKERS') ? array_flip(explode(',', (string) getenv('TICKERS'))) : null;
        @mkdir(dirname($out), 0777, true);

        // The board opened as app:market-seed opens it, on the shared stream at SEED.
        mt_srand($seed);
        $openMath = new MathUtility();
        $metrics = CorporateMetrics::getInstance();
        $debtEngine = new DebtEngine($openMath, $metrics);
        $marketEngine = new MarketEngine($openMath);
        $anchorStakes = new AnchorStakeLedger();
        $builder = new OpeningBoardBuilder($openMath, $debtEngine, $marketEngine, $anchorStakes);
        $openingMacro = new MacroStateDTO();

        $board = [];
        $spheres = [];
        foreach (InitialMarket::STOCKS as $row) {
            $stock = (new Stock())->setTicker($row['ticker']);
            $sphere = $builder->open($stock, $row, $openingMacro);
            if ($sphere !== null) {
                $spheres[] = $sphere;
            }
            $board[$row['ticker']] = ['stock' => $stock, 'row' => $row];
        }
        $builder->openSpheres($spheres, array_map(static fn(array $e): Stock => $e['stock'], $board), $openingMacro);
        $sphereTickers = array_flip(array_map(static fn(array $s): string => $s['stock']->getTicker(), $spheres));

        // The economy's noiseless path, kept at every tick some firm reports on.
        $ticks = $years * $tpy;
        $reportTicks = [];
        foreach ($board as $ticker => $_) {
            $offset = EarningsEngine::resolveReportingTick($ticker, $tpy);
            $tpq = intdiv($tpy, 4);
            for ($t = $offset; $t <= $ticks; $t += $tpq) {
                $reportTicks[$t] = true;
            }
        }
        // MACRO=path: the engine's own noiseless path, which runs a little hot (policy rate 3.4% -> 4.5% in two years).
        // MACRO=frozen: the opening economy held, with only output and prices grown at trend.
        $macroAt = [0 => $openingMacro];
        if ($macroMode === 'frozen') {
            foreach (array_keys($reportTicks) as $t) {
                $elapsed = $t / $tpy;
                $real = exp(MacroConstants::TREND_REAL_GROWTH * $elapsed);
                $prices = exp(MacroConstants::TARGET_INFLATION * $elapsed);
                $macroAt[$t] = $openingMacro->with([
                    'totalTime' => $elapsed,
                    'potentialGdpIndex' => $openingMacro->potentialGdpIndex * $real,
                    'nominalGdpIndex' => $openingMacro->nominalGdpIndex * $real * $prices,
                    'gdpDeflator' => $openingMacro->gdpDeflator * $prices,
                    'consumerPriceLevel' => $openingMacro->consumerPriceLevel * $prices,
                ]);
            }
        } else {
            $macroEngine = MacroEngine::headless(self::noiseless());
            $economy = new MacroState();
            for ($t = 1; $t <= $ticks; $t++) {
                $m = $macroEngine->step($economy, 1.0 / $tpy);
                if (isset($reportTicks[$t])) {
                    $macroAt[$t] = $m;
                }
            }
        }

        // One engine over every firm, on one industry ledger, the firms stepped in time order as the ticker steps them:
        // rivals meet in the ledger's supply and price as they do live.
        $math = $draws === 'seeded' ? (static function () use ($seed): MathUtility {
            mt_srand($seed);

            return new MathUtility();
        })() : self::noiseless();
        $store = new InMemoryIndustryShareStore();
        // BoardRun's salt, so keyed draws (an entry's outcome) replay.
        $store->writeOutlooks(['salt' => 1000 + $seed, 'quarter' => -1, 'industries' => []]);
        $firmDebt = new DebtEngine($math, $metrics);
        $engine = self::earningsEngine($math, debtEngine: $firmDebt, industryShareLedger: new IndustryShareLedger($store));

        $fh = fopen($out, 'w');
        $firms = [];
        foreach ($board as $ticker => ['stock' => $stock, 'row' => $row]) {
            if ($only !== null && !isset($only[$ticker])) {
                continue;
            }
            $strategy = Sectors::strategyFor($stock->getIndustry());
            $firms[$ticker] = [
                'stock' => $stock, 'row' => $row, 'strategy' => $strategy, 'fin' => $strategy->isFinancial(),
                'sphere' => isset($sphereTickers[$ticker]), 'offset' => EarningsEngine::resolveReportingTick($ticker, $tpy), 'q' => 0,
            ];
            fwrite($fh, json_encode(self::row($stock, $row, $strategy, $firmDebt->analyzeDebtHealth($stock, $openingMacro), $openingMacro, 0, $strategy->isFinancial(), isset($sphereTickers[$ticker]), $draws, $seed)) . "\n");
        }

        $tpq = intdiv($tpy, 4);
        for ($t = 1; $t <= $ticks; $t++) {
            if (!isset($macroAt[$t])) {
                continue;
            }
            $macro = $macroAt[$t];
            foreach ($firms as $ticker => &$firm) {
                $stock = $firm['stock'];
                if ($t < $firm['offset'] || ($t - $firm['offset']) % $tpq !== 0 || $stock->isBankrupt()) {
                    continue;
                }
                // StockTracker::assessCredit(): the beta and spread the tick trades on.
                $health = $firmDebt->analyzeDebtHealth($stock, $macro);
                $stock->setLeveredBeta($health->leveredBeta);
                $stock->setDynamicCreditSpread(sprintf('%.6f', $health->rawMetrics->dynamicSpread));

                if ($engine->calculate($stock, $macro, $t, $tpy) === null) {
                    continue;
                }
                $firm['q']++;
                // The market prices the report at fair value (StockTracker::fileReports / settleShareCount).
                $health = $firmDebt->analyzeDebtHealth($stock, $macro);
                $pricing = $marketEngine->strikeFairValue(MarketPricingContext::forStock($stock, $macro, $health, $anchorStakes));
                $fair = (float) $pricing['perceived_fair_value'];
                if ($fair > 0.0 && is_finite($fair)) {
                    $stock->setPrice((string) $fair);
                    $stock->setFairEquityValue($fair / $pricing['market_repricing'] * (float) $stock->getSharesOutstanding());
                }
                fwrite($fh, json_encode(self::row($stock, $firm['row'], $firm['strategy'], $health, $macro, $firm['q'], $firm['fin'], $firm['sphere'], $draws, $seed)) . "\n");
            }
            unset($firm);
        }
        fclose($fh);
        $this->addToAssertionCount(1);
    }

    /** No shocks and no events: medians for uniforms, zero normals, every probability false. */
    private static function noiseless(): ScriptedDraws
    {
        return new ScriptedDraws(
            generateUniform: 0.5,
            generateUniformBetween: static fn(float $min, float $max): float => ($min + $max) / 2.0,
        );
    }

    /**
     * @param array<string, mixed> $seedRow
     * @return array<string, mixed>
     */
    private static function row(Stock $s, array $seedRow, object $strategy, \App\DTO\DebtHealthDTO $health, MacroStateDTO $m, int $q, bool $fin, bool $sphere, string $draws, int $seed): array
    {
        $ngdp = max(0.01, $m->nominalGdpIndex);
        $equity = (float) $s->getTotalEquity();
        $shares = max(1.0, (float) $s->getSharesOutstanding());
        $netIncome = (float) $s->getTotalNetIncome();
        $assets = (float) $s->getTotalAssets();
        $required = $fin ? $health->costOfEquity : $health->wacc;
        $moat = method_exists($strategy, 'getMoatSpread') ? $strategy->getMoatSpread() : null;

        return [
            'ticker' => $s->getTicker(),
            'industry' => $s->getIndustry(),
            'model' => (new \ReflectionClass($strategy))->getShortName(),
            'fin' => $fin,
            'sphere' => $sphere,
            'draws' => $draws,
            'seed' => $seed,
            'q' => $q,
            't' => $m->totalTime,
            // Returns: the seed's, the state the engine carries, and the quarter as filed.
            'seed_ret' => (float) ($fin ? ($seedRow['baseline_roe'] ?? $seedRow['baseline_roic'] ?? 0.10) : ($seedRow['baseline_roic'] ?? 0.10)),
            'ret_ttm' => (float) ($fin ? $s->getRoeTtm() : $s->getRoicTtm()),
            'ret_filed' => (float) ($fin ? $s->getCurrentRoe() : $s->getCurrentRoic()),
            'roe_book' => $equity > 0.0 ? $netIncome / $equity : null,
            'required' => $required,
            'moat' => $moat,
            // Size against the economy (nominal GDP index 1 at the opening).
            'rev_gdp' => (float) $s->getTotalRevenue() / $ngdp,
            'eq_gdp' => $equity / $ngdp,
            'assets_gdp' => $assets / $ngdp,
            'ni_gdp' => $netIncome / $ngdp,
            'margin' => (float) ($s->getReportedOperatingMargin() ?? $s->getOperatingMargin()),
            'de' => $health->debtToEquity,
            'de_target' => $health->leverageTarget,
            'nd_ebitda' => $health->netDebtToEbitda,
            'cash_assets' => $assets > 0.0 ? (float) $s->getCorporateTreasury() / $assets : null,
            'debt' => (float) $s->getTotalDebt(),
            'cash' => (float) $s->getCorporateTreasury(),
            'equity' => $equity,
            'pb' => $s->getBookValuePerShare() > 0.0 ? (float) $s->getPrice() / $s->getBookValuePerShare() : null,
            'pe' => $netIncome > 0.0 ? (float) $s->getPrice() * $shares / $netIncome : null,
            'shares' => $shares,
            'payout' => $netIncome > 0.0 ? (float) $s->getLastDividend() * 4.0 * $shares / $netIncome : null,
            'bankrupt' => $s->isBankrupt(),
        ];
    }
}
