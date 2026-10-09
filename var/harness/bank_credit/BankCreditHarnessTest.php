<?php

declare(strict_types=1);

use App\DTO\MacroStateDTO;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\News\NarrativeEngine;
use App\Service\Macro\MacroEngine;
use App\Service\Market\MarketOperator;
use App\Service\Market\StockTracker;
use App\Service\Math\TimeSeries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Whole-board headless replay (copy of var/harness/FullMarketHarnessTest.php) that records the commercial banks'
 * credit ledger every quarter: gross book, allowance, provision, charge-offs, TTC rate, CET1, ROE inputs, the
 * sector-physics event type, and the macro default rates / output gap the report was filed under.
 *
 * Env: YEARS TPY SEED OUT=<jsonl> MACRO_RECORD=<gz> | MACRO_REPLAY=<gz> BANKS=LAKE,RIVR
 */
final class BankCreditHarnessTest extends KernelTestCase
{
    /** @var list<object> */
    private array $persisted = [];
    private bool $capturing = true;
    /** @var array<string, list<array<string, mixed>>> */
    private array $quarters = [];
    private ?MacroStateDTO $currentMacro = null;
    private float $currentTime = 0.0;
    /** @var list<string> */
    private array $banks = [];
    private ?object $narrativeTap = null;
    /** @var array<string, float> */
    private array $replayLags = [];

    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testRun(): void
    {
        $years = (float) (getenv('YEARS') ?: 1);
        $tpy = (int) (getenv('TPY') ?: 360);
        $seed = (int) (getenv('SEED') ?: 1);
        $out = getenv('OUT') ?: null;
        $recordPath = getenv('MACRO_RECORD') ?: null;
        $replayPath = getenv('MACRO_REPLAY') ?: null;
        $this->banks = explode(',', (string) (getenv('BANKS') ?: 'LAKE,RIVR,PLVR,TALN,STRK,POOL'));

        mt_srand($seed);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
        $_SERVER['KERNEL_CLASS'] = 'App\Kernel';
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $c = static::getContainer();
        $c->set('doctrine.orm.default_entity_manager', $this->buildEntityManager());

        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        ob_start();
        $seedTester = new CommandTester($app->find('app:market-seed'));
        $seedStatus = $seedTester->execute([]);
        ob_end_clean();
        $this->assertSame(0, $seedStatus, 'the seed command failed: ' . substr($seedTester->getDisplay(), -600));

        $stocks = [];
        $id = 1;
        foreach ($this->persisted as $e) {
            if ($e instanceof Stock) {
                (new \ReflectionProperty(Stock::class, 'id'))->setValue($e, $id++);
                $stocks[$e->getTicker()] = $e;
            }
        }
        $this->persisted = [];
        $this->capturing = false;
        $this->assertNotEmpty($stocks, 'the seed command persisted no stocks');

        // The seeded balance sheet and price of each bank, before the first tick.
        $opening = [];
        foreach ($this->banks as $t) {
            $s = $stocks[$t];
            $strategy = \App\Data\Company\Sectors::strategyFor($s->getIndustry());
            $opening[$t] = [
                'px' => (float) $s->getPrice(), 'sh' => (float) $s->getSharesOutstanding(), 'eq' => (float) $s->getTotalEquity(),
                'cash' => (float) $s->getCorporateTreasury(), 'dep' => (float) $s->getCustomerDeposits(), 'wd' => (float) $s->getWholesaleDebt(),
                'ea' => (float) $s->getEarningAssets(), 'allow' => (float) $s->getCreditLossAllowance(),
                'cet1' => method_exists($strategy, 'calculateCet1Ratio') ? $strategy->calculateCet1Ratio($s) : null,
                'ttc' => $strategy->getThroughTheCycleCreditLossRate($s),
            ];
        }

        $macro = $c->get(MacroEngine::class);
        $tracker = $c->get(StockTracker::class);
        $operator = $c->get(MarketOperator::class);

        // Tap the earnings engine's narrative calls: the sector-physics event type is only visible there.
        $earnings = (new \ReflectionProperty(StockTracker::class, 'earningsEngine'))->getValue($tracker);
        $this->narrativeTap = new class extends NarrativeEngine {
            /** @var list<string> */
            public array $log = [];
            public int $calls = 0;

            public function generateLore(string $eventType, array $context = []): string
            {
                $this->log[] = $eventType;
                ++$this->calls;

                return parent::generateLore($eventType, $context);
            }
        };
        (new \ReflectionProperty($earnings, 'narrativeEngine'))->setValue($earnings, $this->narrativeTap);

        // Round 2c: tap the treasury's DebtEngine (record only; every call goes to the parent unchanged). Each maturity
        // roll of a tracked lender logs the three market-access tests, and each issueDebt() from the emergency paths
        // logs its caller and amount.
        $capAlloc = (new \ReflectionProperty($earnings, 'capitalAllocationEngine'))->getValue($earnings);
        $treasury = (new \ReflectionProperty($capAlloc, 'treasuryEngine'))->getValue($capAlloc);
        $inner = (new \ReflectionProperty($treasury, 'debtEngine'))->getValue($treasury);
        $tap = (new \ReflectionClass(\BankCreditDebtTap::class))->newInstanceWithoutConstructor();
        foreach ((new \ReflectionClass(\App\Service\Corporate\DebtEngine::class))->getProperties() as $p) {
            if (!$p->isStatic() && $p->getDeclaringClass()->getName() === \App\Service\Corporate\DebtEngine::class) {
                $p->setValue($tap, $p->getValue($inner));
            }
        }
        $tap->tracked = array_flip($this->banks);
        $tap->clock = fn (): float => $this->currentTime;
        (new \ReflectionProperty($treasury, 'debtEngine'))->setValue($treasury, $tap);
        $this->debtTap = $tap;

        $bankModelFile = (new \ReflectionClass(\App\Service\Model\Sector\CommercialBankBusinessModel::class))->getFileName();

        $list = array_values($stocks);
        $dt = 1.0 / $tpy;
        $ticks = (int) round($years * $tpy);
        $operatorInterval = (int) max(1, $tpy / 24);
        $replay = $replayPath !== null ? unserialize((string) gzuncompress((string) file_get_contents($replayPath))) : null;
        $recorded = [];
        $lastCap = null;
        $dead = [];
        $reorgs = [];
        $defaults = [];
        $t0 = microtime(true);

        for ($tick = 1; $tick <= $ticks; $tick++) {
            $m = $replay !== null ? $this->withDemandLags($replay[$tick - 1], $dt) : $macro->updateMacroState($dt, $lastCap);
            if ($recordPath !== null) {
                $recorded[] = $m;
            }
            $this->currentMacro = $m;
            $this->currentTime = $tick / $tpy;

            if ($tick % $operatorInterval === 0) {
                foreach ($list as $s) {
                    if (!$s->isBankrupt() && $s->isPaymentDefault()) {
                        $defaults[$s->getTicker()] = ($defaults[$s->getTicker()] ?? 0) + 1;
                    }
                }
                foreach ($operator->enforceMarketStability($list, $m) as $ev) {
                    $t = (string) ($ev['ticker'] ?? '');
                    if (!isset($stocks[$t])) {
                        continue;
                    }
                    if (($ev['type'] ?? '') === 'BANKRUPTCY') {
                        $dead[$t] = round($tick / $tpy, 2);
                    } elseif (($ev['type'] ?? '') === 'REORGANIZATION') {
                        $reorgs[] = ['t' => $t, 'year' => round($tick / $tpy, 2)];
                    }
                }
            }

            $result = $tracker->updateStocks($list, $dt, false, $m, $tick, $tpy);
            $lastCap = (float) $result['total_cap'];
            // Round 2: the macro every quarter, independent of any report, and a board-wide finiteness sweep.
            if ($tick % (int) max(1, $tpy / 4) === 0) {
                $mv = (fn (): array => get_object_vars($this))->call($m);
                $this->macroQ[] = [
                    't' => round($tick / $tpy, 3),
                    'rdr' => $m->retailDefaultRateEma, 'rdr_raw' => $m->retailDefaultRate, 'cdr' => $m->corporateDefaultRateEma,
                    'res' => $m->residentialPropertyIndexEma, 'rwt' => $m->residentialWealthTrend,
                    'u' => $m->unemploymentRateEma, 'nairu' => $m->nairu, 'gap' => $m->outputGapEma,
                    'rcf' => $mv['retailCreditFactor'] ?? null, 'dsg' => $m->householdDebtServiceGap,
                ];
                foreach ($mv as $k => $v) {
                    if (is_float($v) && !is_finite($v)) {
                        $this->nonFinite['macro.' . $k] = ($this->nonFinite['macro.' . $k] ?? 0) + 1;
                    }
                }
                foreach ($list as $s) {
                    foreach (['price' => $s->getPrice(), 'equity' => $s->getTotalEquity(), 'cash' => $s->getCorporateTreasury()] as $k => $v) {
                        if (!is_finite((float) $v)) {
                            $this->nonFinite[$s->getTicker() . '.' . $k] = ($this->nonFinite[$s->getTicker() . '.' . $k] ?? 0) + 1;
                        }
                    }
                }
            }
            if ($tick === 1) {
                foreach ($this->banks as $t) {
                    $opening[$t]['px1'] = (float) $stocks[$t]->getPrice();
                    $opening[$t]['sh1'] = (float) $stocks[$t]->getSharesOutstanding();
                }
                $opening['_board_cap1'] = $lastCap;
            }
        }

        if ($recordPath !== null) {
            file_put_contents($recordPath, gzcompress(serialize($recorded), 6));
        }

        $final = [];
        foreach ($this->banks as $t) {
            $s = $stocks[$t];
            $final[$t] = ['dead' => $s->isBankrupt(), 'industry' => $s->getIndustry(), 'px' => (float) $s->getPrice(),
                'sh' => (float) $s->getSharesOutstanding(), 'eq' => (float) $s->getTotalEquity(), 'q' => $this->quarters[$t] ?? []];
        }

        $record = [
            'seed' => $seed,
            'years' => $years,
            'tpy' => $tpy,
            'replay' => $replayPath !== null,
            'bank_model_file' => $bankModelFile,
            'lore_calls' => $this->narrativeTap->calls,
            'secs' => round(microtime(true) - $t0, 1),
            'dead' => $dead,
            'reorgs' => $reorgs,
            'defaults' => array_intersect_key($defaults, array_flip($this->banks)),
            'defaults_all' => $defaults,
            'board_lines' => $this->boardLines,
            'macro_q' => $this->macroQ,
            'nonfinite' => $this->nonFinite,
            'n_stocks' => count($list),
            'final' => $final,
            'opening' => $opening,
            'events' => $this->events,
            'board_cap_end' => $lastCap,
            'rolls' => $this->debtTap?->rolls ?? [],
            'emerg' => $this->debtTap?->emerg ?? [],
        ];
        if ($out !== null) {
            file_put_contents($out, json_encode($record) . "\n", FILE_APPEND);
        }
        fwrite(STDERR, sprintf("seed %d: %d ticks in %.1fs, deaths %s, model %s\n", $seed, $ticks, microtime(true) - $t0, json_encode($dead), $bankModelFile));
        $this->assertTrue(true);
    }

    /** A recording predating the output gap's published lags gets them filtered off its own gap path, as the macro would. */
    private function withDemandLags(MacroStateDTO $recorded, float $dt): MacroStateDTO
    {
        $vars = (fn (): array => get_object_vars($this))->call($recorded);
        $math = new \App\Service\Math\MathUtility();
        foreach (\App\Service\Macro\Subsystem\MacroAggregateSubsystem::DEMAND_TRANSMISSION_LAGS as $field => $lagYears) {
            $this->replayLags[$field] = TimeSeries::calculateDistributedLag($this->replayLags[$field] ?? \App\Data\Macro\MacroFieldRegistry::defaults()[$field], $vars['outputGapEma'], $dt, $lagYears);
        }

        return new MacroStateDTO(...array_merge($vars, $this->replayLags));
    }

    private function captureReport(CorporateReport $report): void
    {
        $stock = $report->getStock();
        $ticker = $stock->getTicker();
        $log = $this->narrativeTap !== null ? $this->narrativeTap->log : [];
        if ($this->narrativeTap !== null) {
            $this->narrativeTap->log = [];
        }
        if (!in_array($ticker, $this->banks, true)) {
            return;
        }
        $m = $this->currentMacro;
        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        $cet1 = method_exists($strategy, 'calculateCet1Ratio') ? $strategy->calculateCet1Ratio($stock) : null;
        $ttc = $strategy->getThroughTheCycleCreditLossRate($stock);
        $wanted = ['massive_credit_provision', 'elevated_loan_defaults', 'reserve_release', 'bank_seizure'];

        $this->quarters[$ticker][] = [
            't' => round($this->currentTime, 3),
            'rev' => (float) $report->getRevenue(),
            'ni' => (float) $report->getNetIncome(),
            'ebit' => (float) $report->getEbit(),
            'eq' => (float) $report->getEquity(),
            'ta' => (float) $report->getTotalAssets(),
            'ea' => (float) $stock->getEarningAssets(),
            'allow' => (float) $stock->getCreditLossAllowance(),
            'prov' => (float) $report->getCreditLossProvision(),
            'nco' => (float) $report->getNetChargeOffs(),
            'ttc' => $ttc,
            'cet1' => $cet1,
            'ev' => array_values(array_intersect($log, $wanted)),
            'gap' => $m?->outputGapEma ?? 0.0,
            'rp' => $m?->recessionProbabilityEma ?? 0.0,
            'cdr' => $m?->corporateDefaultRateEma ?? 0.0,
            'rdr' => $m?->retailDefaultRateEma ?? 0.0,
            'cre' => $m?->commercialPropertyIndexEma ?? 0.0,
            'res' => $m?->residentialPropertyIndexEma ?? 0.0,
            // PLVR round: price, NIM and the curve the NIM squeeze reads, payouts and liquidity.
            'px' => (float) $stock->getPrice(),
            'sh' => (float) $stock->getSharesOutstanding(),
            'nim' => $report->getNetInterestMargin() === null ? null : (float) $report->getNetInterestMargin(),
            'nii' => (float) (($report->getRevenueStreams() ?? [])['net_interest_income'] ?? 0.0),
            'int' => (float) $report->getInterestExpense(),
            'om' => (float) $report->getOperatingMargin(),
            'div' => (float) $report->getDividendPaid(),
            'bb' => (float) $report->getStockBuybacks(),
            'cash' => (float) $report->getTreasury(),
            'dep' => (float) $stock->getCustomerDeposits(),
            'wd' => (float) $stock->getWholesaleDebt(),
            'rating' => $stock->getCreditRating(),
            'y2' => $m?->yield2yEma ?? 0.0,
            'y10' => $m?->yield10yEma ?? 0.0,
            'ib' => $m?->interbankLiquiditySpreadEma ?? 0.0,
            'slope_spot' => ($m?->yield10y ?? 0.0) - ($m?->yield2y ?? 0.0),
            'pol' => $m?->policyRate ?? 0.0,
            'rwt' => $m?->residentialWealthTrend ?? 0.0,
            'ugap' => ($m?->unemploymentRateEma ?? 0.0) - ($m?->nairu ?? 0.0),
            'rvd' => (float) $stock->getRevolverDrawn(),
            'pdef' => $stock->isPaymentDefault(),
        ];
    }

    /** @var array<string, list<array{t: float, d: string}>> */
    private array $events = [];
    private ?\BankCreditDebtTap $debtTap = null;
    /** @var array<string, array<string, int>> board-wide distress-line counts by ticker and kind */
    private array $boardLines = [];
    /** @var list<array<string, mixed>> */
    private array $macroQ = [];
    /** @var array<string, int> */
    private array $nonFinite = [];

    private function buildEntityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e): void {
            if ($e instanceof CorporateReport) {
                $this->captureReport($e);
                return;
            }
            if ($e instanceof \App\Entity\StockEvent && !$this->capturing) {
                foreach (['shut' => 'Shut out of the bond market', 'chapter' => 'Chapter', 'rescue' => 'rescue', 'default' => 'default'] as $kind => $needle) {
                    if (stripos((string) $e->getDescription(), $needle) !== false) {
                        $this->boardLines[$e->getStock()->getTicker()][$kind] = ($this->boardLines[$e->getStock()->getTicker()][$kind] ?? 0) + 1;
                    }
                }
            }
            if ($e instanceof \App\Entity\StockEvent && !$this->capturing && in_array($e->getStock()->getTicker(), $this->banks, true)) {
                // Treasury distress lines ride inside the earnings description as bullets: keep only those.
                foreach (preg_split('/\n/', (string) $e->getDescription()) ?: [] as $line) {
                    if (preg_match('/borrow|revolv|bond market|stave off|repay|default|record of dividend|suspend|Chapter|liquidat|rescue|covenant|restructur|critical|distress|seiz|capital raise|rights issue/i', $line)) {
                        $this->events[$e->getStock()->getTicker()][] = ['t' => round($this->currentTime, 3), 'd' => substr(ltrim($line, "• "), 0, 160)];
                    }
                }
                return;
            }
            if ($this->capturing) {
                $this->persisted[] = $e;
            }
        });
        $em->method('getConnection')->willReturn($this->createStub(Connection::class));

        $repositories = [];
        $em->method('getRepository')->willReturnCallback(function (string $class) use (&$repositories) {
            if (!isset($repositories[$class])) {
                $repositoryClass = EntityRepository::class;
                foreach ((new \ReflectionClass($class))->getAttributes(\Doctrine\ORM\Mapping\Entity::class) as $attribute) {
                    $repositoryClass = $attribute->newInstance()->repositoryClass ?? EntityRepository::class;
                }
                $repositories[$class] = $this->createStub($repositoryClass);
            }

            return $repositories[$class];
        });

        return $em;
    }
}

/** Round 2c recording subclass of the treasury's DebtEngine: logs, then defers to the parent unchanged. */
final class BankCreditDebtTap extends \App\Service\Corporate\DebtEngine
{
    /** @var array<string, int> */
    public array $tracked = [];
    /** @var \Closure(): float */
    public \Closure $clock;
    /** @var array<string, list<array<string, mixed>>> */
    public array $rolls = [];
    /** @var array<string, list<array<string, mixed>>> */
    public array $emerg = [];

    public function rollMaturities(\App\Entity\Stock $stock, \App\DTO\DebtHealthDTO $health, float $availableCash, float $cashFloor = 0.0): \App\DTO\MaturityRollDTO
    {
        $t = $stock->getTicker();
        $rating = $stock->getCreditRating();
        $spread = $health->rawMetrics->dynamicSpread ?? (float) $stock->getCreditSpread();
        $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
        $roll = parent::rollMaturities($stock, $health, $availableCash, $cashFloor);
        if (isset($this->tracked[$t]) && $roll->maturingPrincipal > 0.0) {
            $ranks = \App\Service\Market\CreditRatingAgency::RATING_RANKS;
            $this->rolls[$t][] = [
                't' => round(($this->clock)(), 3), 'ok' => $roll->refinanced, 'mat' => $roll->maturingPrincipal,
                'rep' => $roll->principalRepaid, 'sf' => $roll->unfundedShortfall, 'icr' => $health->interestCoverage,
                'spr' => $spread, 'rt' => $rating, 'floor' => ($ranks[$rating] ?? $ranks['BBB']) < ($ranks[self::REFINANCING_RATING_FLOOR] ?? 1),
                'capt' => $strategy->getTargetCapitalRatio($stock) !== null, 'cash' => $availableCash, 'cf' => $cashFloor,
                'eq' => (float) $stock->getTotalEquity(),
            ];
        }

        return $roll;
    }

    public function issueDebt(\App\Entity\Stock $stock, float $amountIssued, float $costOfNewDebt): void
    {
        $t = $stock->getTicker();
        if (isset($this->tracked[$t]) && $amountIssued > 0.0) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '?';
            if (in_array($caller, ['processEmergencyBorrowing', 'processRevolverDraw'], true)) {
                $this->emerg[$t][] = ['t' => round(($this->clock)(), 3), 'fn' => $caller, 'amt' => $amountIssued, 'rate' => $costOfNewDebt];
            }
        }
        parent::issueDebt($stock, $amountIssued, $costOfNewDebt);
    }
}
