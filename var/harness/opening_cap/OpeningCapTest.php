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
 * The board's total market value over the first quarter, exactly as var/harness/FullMarketHarnessTest.php runs it
 * (production container, in-memory Redis, real seed, live macro fed the board cap, operator every tpy/24).
 * Records StockTracker::updateStocks()['total_cap'] every tick, plus each firm's price x shares at tick 1 and at
 * the end of the quarter.
 *
 * Env: TPY SEED OUT=<json>. OVR=ovr_noplvr seeds the board without PLVR.
 */
final class OpeningCapTest extends KernelTestCase
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
        $tpy = (int) (getenv('TPY') ?: 360);
        $seed = (int) (getenv('SEED') ?: 11);
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

        $seedCap = [];
        foreach ($stocks as $t => $s) {
            $seedCap[$t] = (float) $s->getPrice() * (float) $s->getSharesOutstanding();
        }

        $macro = $c->get(MacroEngine::class);
        $tracker = $c->get(StockTracker::class);
        $operator = $c->get(MarketOperator::class);
        $list = array_values($stocks);
        $dt = 1.0 / $tpy;
        $ticks = (int) round($tpy / 4);
        $operatorInterval = (int) max(1, $tpy / 24);
        $lastCap = null;
        $caps = [];
        $firm1 = [];
        $firmQ = [];

        for ($tick = 1; $tick <= $ticks; $tick++) {
            $m = $macro->updateMacroState($dt, $lastCap);
            if ($tick % $operatorInterval === 0) {
                $operator->enforceMarketStability($list, $m);
            }
            $result = $tracker->updateStocks($list, $dt, false, $m, $tick, $tpy);
            $lastCap = (float) $result['total_cap'];
            $caps[] = $lastCap;
            if ($tick === 1 || $tick === $ticks) {
                foreach ($stocks as $t => $s) {
                    $v = (float) $s->getPrice() * (float) $s->getSharesOutstanding();
                    if ($tick === 1) {
                        $firm1[$t] = $v;
                    } else {
                        $firmQ[$t] = $v;
                    }
                }
            }
        }

        $record = [
            'seed' => $seed, 'tpy' => $tpy, 'ovr' => (string) getenv('OVR'), 'n_stocks' => count($stocks),
            'has_plvr' => isset($stocks['PLVR']), 'seed_cap_sum' => array_sum($seedCap),
            'cap_tick1' => $caps[0], 'cap_q1_end' => $caps[$ticks - 1], 'cap_q1_mean' => array_sum($caps) / count($caps),
            'cap_q1_min' => min($caps), 'cap_q1_max' => max($caps),
            'firm_tick1' => $firm1, 'firm_q1_end' => $firmQ, 'firm_seed' => $seedCap,
        ];
        file_put_contents($out, json_encode($record) . "\n");
        fwrite(STDERR, sprintf("seed %d: %d stocks, Q1 mean cap %.4e\n", $seed, count($stocks), $record['cap_q1_mean']));
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
