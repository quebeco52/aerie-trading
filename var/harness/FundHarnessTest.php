<?php

declare(strict_types=1);

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\Macro\MacroEngine;
use App\Service\Market\MarketOperator;
use App\Service\Market\StockTracker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Sovereign fund validation replay on the PRODUCTION service graph, fed the board exactly as MarketTickerCommand
 * feeds it (capitalisation, float cap, float-weighted price return and dividend cash, one tick behind).
 *
 * Arms are OVR copies of SovereignFundSubsystem (see fund_arms.sh): fund (none), norebal, nofund, exec1.
 * Env: YEARS TPY SEED OUT=<jsonl> [OVR=<dir>]
 * Output: one JSON line per run with quarterly macro rows, the per-tick board index, and the fund's programmes.
 */
final class FundHarnessTest extends KernelTestCase
{
    /** @var list<object> */
    private array $persisted = [];
    private bool $capturing = true;

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

        mt_srand($seed);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
        $_SERVER['KERNEL_CLASS'] = 'App\Kernel';
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $c = static::getContainer();
        $c->set('doctrine.orm.default_entity_manager', $this->buildEntityManager());

        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        ob_start();
        (new CommandTester($app->find('app:market-seed')))->execute([]);
        ob_end_clean();

        $stocks = [];
        $id = 1;
        foreach ($this->persisted as $e) {
            if ($e instanceof Stock) {
                (new \ReflectionProperty(Stock::class, 'id'))->setValue($e, $id++);
                $stocks[] = $e;
            }
        }
        $this->persisted = [];
        $this->capturing = false;
        $this->assertNotEmpty($stocks);

        $macro = $c->get(MacroEngine::class);
        $tracker = $c->get(StockTracker::class);
        $operator = $c->get(MarketOperator::class);

        $dt = 1.0 / $tpy;
        $ticks = (int) round($years * $tpy);
        $operatorInterval = (int) max(1, $tpy / 24);
        $quarterEvery = intdiv($tpy, 4);
        $lastCap = null;
        $lastFloat = null;
        $lastReturn = null;
        $lastDividends = null;
        $lastIssuance = null;
        $lastDuty = null;
        $boardIndex = 1.0;
        $board = [];
        $quarters = [];
        $programmes = [];
        $lastProgramme = -1.0;
        $deaths = 0;
        $t0 = microtime(true);

        for ($tick = 1; $tick <= $ticks; $tick++) {
            $m = $macro->updateMacroState($dt, $lastCap, $lastFloat, $lastReturn, $lastDividends, $lastIssuance, $lastDuty);
            $lastReturn = null;
            $lastDividends = null;
            $lastIssuance = null;
            $lastDuty = null;

            if ($m->lastSovereignRebalanceAt > $lastProgramme) {
                $lastProgramme = $m->lastSovereignRebalanceAt;
                $programmes[] = ['t' => round($m->totalTime, 4), 'share' => $m->sovereignFundRebalanceShare, 'weight' => $m->sovereignFundDomesticWeight, 'target' => $m->sovereignFundTargetWeight];
            }

            if ($tick % $operatorInterval === 0) {
                foreach ($operator->enforceMarketStability($stocks, $m) as $ev) {
                    if (($ev['type'] ?? '') === 'BANKRUPTCY') {
                        ++$deaths;
                    }
                }
            }

            $result = $tracker->updateStocks($stocks, $dt, false, $m, $tick, $tpy);
            $lastCap = (float) $result['total_cap'] > 0.0 ? (float) $result['total_cap'] : $lastCap;
            $lastFloat = (float) $result['board_float_cap'] > 0.0 ? (float) $result['board_float_cap'] : $lastFloat;
            $lastReturn = (float) $result['board_price_return'];
            $lastDividends = (float) $result['board_dividend_cash'];
            $lastIssuance = (float) $result['board_net_issuance'];
            $lastDuty = (float) $result['board_stamp_duty'];

            if ($tick === 3 && getenv('SIZEPROBE')) {
                $gdpDollars = $m->sovereignFundDollarsPerGdp * $m->nominalGdpIndex;
                fwrite(STDERR, sprintf("SIZEPROBE total_cap=%.4e float_cap=%.4e gdp=%.4e fund=%.4e fund/gdp=%.3f fund/cap=%.3f w*=%.4f draw/gdp=%.4f names=%d\n",
                    (float) $result['total_cap'], (float) $result['board_float_cap'], $gdpDollars, $m->sovereignFundToGdp * $gdpDollars,
                    $m->sovereignFundToGdp, $m->sovereignFundToGdp * $gdpDollars / max(1.0, (float) $result['total_cap']), $m->sovereignFundTargetWeight, $m->sovereignFundDrawToGdp, count($stocks)));
                $caps = [];
                foreach ($stocks as $st) { $caps[$st->getTicker()] = (float) $st->getPrice() * (float) $st->getSharesOutstanding(); }
                arsort($caps);
                fwrite(STDERR, 'SIZEPROBE top: ' . json_encode(array_map(static fn ($c) => sprintf('%.3g', $c), array_slice($caps, 0, 8, true))) . "\n");
            }
            $boardIndex *= 1.0 + $lastReturn;
            $board[] = round($boardIndex, 6);

            if ($tick % $quarterEvery === 0) {
                $quarters[] = [
                    't' => round($m->totalTime, 3),
                    'gap' => $m->outputGap,
                    'gap_ema' => $m->outputGapEma,
                    'infl' => $m->inflationEma,
                    'policy' => $m->policyRate,
                    'y2' => $m->yield2y,
                    'y10' => $m->yield10y,
                    'debt' => $m->sovereignDebtToGdp,
                    'deficit' => $m->primaryDeficitToGdp,
                    'stab' => $m->sovereignFundStabilisationToGdp,
                    'tax' => $m->corporateTaxRate,
                    'gov' => $m->governmentSpendingIndex,
                    'erp' => $m->equityRiskPremium,
                    'wealth' => $m->equityWealthRatio,
                    'fx' => $m->exchangeRateIndex,
                    'fund_gdp' => $m->sovereignFundToGdp,
                    'weight' => $m->sovereignFundDomesticWeight,
                    'equity' => $m->sovereignFundEquityShare,
                    'duty' => $m->sovereignFundStampDutyToGdp,
                    'target' => $m->sovereignFundTargetWeight,
                    'own' => $m->sovereignFundOwnershipShare,
                    'draw' => $m->sovereignFundDrawToGdp,
                    'months' => $m->sovereignFundRebalanceMonthsLeft,
                    'fx_eq' => $m->foreignEquityIndex,
                    'ret' => $m->sovereignFundReturnIndex,
                    'ret_real' => $m->sovereignFundRealReturnIndex,
                    'exp_real' => $m->sovereignFundExpectedRealReturn,
                    'net_debt' => $m->sovereignNetDebtToGdp,
                    'spread' => $m->sovereignRiskSpread,
                    'bond_y' => $m->foreignBondYield,
                    'fpol' => $m->foreignPolicyRate,
                    'board_gdp' => ($lastFloat ?? 0.0) / max(1e-9, $m->nominalGdpIndex),
                ];
            }
        }

        $record = [
            'arm' => getenv('ARM') ?: 'fund',
            'seed' => $seed,
            'years' => $years,
            'tpy' => $tpy,
            'secs' => round(microtime(true) - $t0, 1),
            'deaths' => $deaths,
            'quarters' => $quarters,
            'board' => $board,
            'programmes' => $programmes,
        ];
        if ($out !== null) {
            file_put_contents($out, json_encode($record) . "\n", FILE_APPEND);
        }
        fwrite(STDERR, sprintf("%s seed %d: %d ticks in %.1fs, %d programmes, %d deaths\n", $record['arm'], $seed, $ticks, microtime(true) - $t0, count($programmes), $deaths));
        $this->assertTrue(true);
    }

    private function buildEntityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e): void {
            if ($e instanceof CorporateReport) {
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
