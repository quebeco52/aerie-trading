<?php

declare(strict_types=1);

use App\Data\Company\Sectors;
use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\FailureSweep;
use App\Service\Macro\MacroEngine;
use App\Service\Market\Pricing\StockTracker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Opening betas and fair multiples, then realised monthly betas against the board, on the production graph.
 * Same loop as var/harness/FullMarketHarnessTest.php (macro fed total cap/float/flows, failure sweep every tpy/24).
 *
 * Env: YEARS TPY SEED OUT=<json>.
 */
final class BetaHarnessTest extends KernelTestCase
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
        $years = (float) (getenv('YEARS') ?: 5);
        $tpy = (int) (getenv('TPY') ?: 360);
        $seed = (int) (getenv('SEED') ?: 1);
        $out = (string) getenv('OUT');

        mt_srand($seed);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
        $_SERVER['KERNEL_CLASS'] = 'App\Kernel';
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $c = static::getContainer();
        $c->set('doctrine.orm.default_entity_manager', $this->buildEntityManager());

        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        ob_start();
        $seedTester = new CommandTester($app->find('app:market-seed'));
        $this->assertSame(0, $seedTester->execute([]));
        ob_end_clean();

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

        $macro = $c->get(MacroEngine::class);
        $debt = $c->get(DebtEngine::class);
        $tracker = $c->get(StockTracker::class);
        $sweep = $c->get(FailureSweep::class);
        $list = array_values($stocks);
        $dt = 1.0 / $tpy;
        $ticks = (int) round($years * $tpy);
        $month = (int) max(1, round($tpy / 12));
        $operatorInterval = (int) max(1, $tpy / 24);
        $lastCap = $lastFloat = $lastReturn = $lastDividends = $lastIssuance = $lastDuty = $lastStake = $lastLevy = $lastCredit = null;

        $open = [];
        $snap = [];
        $lev = [];
        $dead = [];
        for ($tick = 1; $tick <= $ticks; $tick++) {
            $m = $macro->updateMacroState($dt, $lastCap, $lastFloat, $lastReturn, $lastDividends, $lastIssuance, $lastDuty, $lastStake, null, boardBankLevy: $lastLevy, boardBankCredit: $lastCredit);
            if ($tick === 1) {
                foreach ($stocks as $t => $s) {
                    $h = $debt->analyzeDebtHealth($s, $m);
                    $strategy = Sectors::strategyFor($s->getIndustry());
                    $cap = (float) $s->getPrice() * (float) $s->getSharesOutstanding();
                    $nd = $strategy->getNetDebtCapital((float) $s->getTotalDebt(), (float) $s->getWholesaleDebt(), (float) $s->getCorporateTreasury());
                    $eps = (float) $s->getEarningsPerShare();
                    $open[$t] = ['ind' => $s->getIndustry(), 'fin' => $strategy->isFinancial(), 'beta' => (float) $s->getBeta(),
                        'blev' => $h->leveredBeta, 'nde' => $cap > 0 ? $nd / $cap : null, 'coe' => $h->costOfEquity, 'wacc' => $h->wacc,
                        'pe' => $eps > 0 ? (float) $s->getPrice() / $eps : null, 'cap' => $cap, 'vol' => (float) $s->getVolatility(),
                        'rf' => $m->yield10y, 'erp' => $m->equityRiskPremium, 'tax' => $m->corporateTaxRate];
                }
                $snap[] = $this->snapshot($stocks);
            }
            if ($tick % $operatorInterval === 0) {
                foreach ($sweep->sweep($list, $m) as $ev) {
                    if (($ev['type'] ?? '') === 'BANKRUPTCY') {
                        $dead[(string) $ev['ticker']] = round($tick / $tpy, 2);
                    }
                }
            }
            $result = $tracker->updateStocks($list, $dt, false, $m, $tick, $tpy);
            $lastCap = (float) $result['total_cap'] > 0.0 ? (float) $result['total_cap'] : $lastCap;
            $lastFloat = (float) $result['board_float_cap'] > 0.0 ? (float) $result['board_float_cap'] : $lastFloat;
            $lastReturn = (float) $result['board_price_return'];
            $lastDividends = (float) $result['board_dividend_cash'];
            $lastIssuance = (float) $result['board_net_issuance'];
            $lastDuty = (float) $result['board_stamp_duty'];
            $lastStake = (float) $result['strategic_stake_cash'];
            $lastLevy = (float) $result['board_bank_levy'];
            $lastCredit = (float) $result['board_bank_credit'];
            if ($tick % $month === 0) {
                $snap[] = $this->snapshot($stocks);
                foreach ($stocks as $t => $s) {
                    if (!$s->isBankrupt()) {
                        $lev[$t][] = $debt->analyzeDebtHealth($s, $m)->leveredBeta;
                    }
                }
            }
        }

        file_put_contents($out, json_encode(['seed' => $seed, 'years' => $years, 'tpy' => $tpy, 'open' => $open, 'snap' => $snap, 'lev' => $lev, 'dead' => $dead]));
        fwrite(STDERR, sprintf("seed %d done, %d snaps, dead %s\n", $seed, count($snap), json_encode($dead)));
        $this->assertTrue(true);
    }

    /** @param array<string, Stock> $stocks @return array<string, array{0: float, 1: float, 2: bool}> */
    private function snapshot(array $stocks): array
    {
        $row = [];
        foreach ($stocks as $t => $s) {
            $row[$t] = [(float) $s->getPrice(), (float) $s->getSharesOutstanding(), $s->isBankrupt()];
        }

        return $row;
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
