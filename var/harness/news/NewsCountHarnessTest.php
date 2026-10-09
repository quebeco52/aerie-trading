<?php

declare(strict_types=1);

use App\Entity\Etf;
use App\Entity\Stock;
use App\Service\District\DistrictRoster;
use App\Service\Event\MarketEventPublisher;
use App\Service\News\NewsDesk;
use App\Service\News\SystemicEventReporter;
use App\Service\Macro\MacroEngine;
use App\Service\Market\EtfTracker;
use App\Service\Market\Index\MarketIndex;
use App\Service\Market\IndexCommittee;
use App\Service\Market\IndexFundAccountant;
use App\Service\Market\MarketOperator;
use App\Service\Market\StockTracker;
use App\Service\Math\FinancialConstants;
use App\Service\Politics\PoliticsEngine;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Newswire census: replays the live ticker's per-tick order (macro + politics, operator every 1/24 year, stocks,
 * systemic district story, index reconstitution + fund distributions + fund strike, Glasswater Row roster) on a
 * seeded board, and taps every wire copy MarketEventPublisher pushes (its category and NewsDesk headline verdict).
 *
 * Env: YEARS TPY SEED OUT=<jsonl, one compact row per story>
 */
final class NewsCountHarnessTest extends KernelTestCase
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
        $tpy = (int) (getenv('TPY') ?: 3600);
        $seed = (int) (getenv('SEED') ?: 1);
        $out = (string) (getenv('OUT') ?: sys_get_temp_dir() . "/news-$seed.jsonl");
        @unlink($out);

        mt_srand($seed);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $c = static::getContainer();
        $c->set('doctrine.orm.default_entity_manager', $this->buildEntityManager());

        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        ob_start();
        $seedTester = new CommandTester($app->find('app:market-seed'));
        $seedStatus = $seedTester->execute([]);
        ob_end_clean();
        $this->assertSame(0, $seedStatus, 'seed failed: ' . substr($seedTester->getDisplay(), -600));

        $stocks = [];
        $funds = [];
        $id = 1;
        foreach ($this->persisted as $e) {
            if ($e instanceof Stock) {
                (new \ReflectionProperty(Stock::class, 'id'))->setValue($e, $id++);
                $stocks[$e->getTicker()] = $e;
            } elseif ($e instanceof Etf) {
                $funds[$e->getTicker()] = $e;
            }
        }
        $this->persisted = [];
        $this->capturing = false;
        \Redis::$wire = [];

        $indexFunds = [];
        foreach (MarketIndex::cases() as $index) {
            if (isset($funds[$index->value])) {
                $indexFunds[$index->value] = $funds[$index->value];
            }
        }

        $macro = $c->get(MacroEngine::class);
        $politicsEngine = $c->get(PoliticsEngine::class);
        $tracker = $c->get(StockTracker::class);
        $operator = $c->get(MarketOperator::class);
        $systemic = $c->get(SystemicEventReporter::class);
        $publisher = $c->get(MarketEventPublisher::class);
        $etfTracker = $c->get(EtfTracker::class);
        $committee = $c->get(IndexCommittee::class);
        $accountant = $c->get(IndexFundAccountant::class);
        $roster = $c->get(DistrictRoster::class);

        $list = array_values($stocks);
        $dt = 1.0 / $tpy;
        $ticks = (int) round($years * $tpy);
        $operatorInterval = (int) max(1, $tpy / 24);
        $policy = $politicsEngine->liveState()->policy();
        $last = ['cap' => null, 'float' => null, 'ret' => null, 'div' => null, 'iss' => null, 'duty' => null, 'stake' => null, 'levy' => null];
        $prevCap = [];
        $prevEq = [];
        foreach ($stocks as $t => $s) {
            $prevCap[$t] = (float) $s->getPrice() * (float) $s->getSharesOutstanding();
            $prevEq[$t] = (float) $s->getTotalEquity();
        }
        $fh = fopen($out, 'w');
        $t0 = microtime(true);

        for ($tick = 1; $tick <= $ticks; $tick++) {
            $m = $macro->updateMacroState($dt, $last['cap'], $last['float'], $last['ret'], $last['div'], $last['iss'], $last['duty'], $last['stake'], $policy, boardBankLevy: $last['levy']);
            $politics = $politicsEngine->updatePolitics($m, $dt);
            $policy = $politics->policy();

            if ($tick % $operatorInterval === 0) {
                $operator->enforceMarketStability($list, $m);
            }

            $result = $tracker->updateStocks($list, $dt, false, $m, $tick, $tpy);
            $last = [
                'cap' => (float) $result['total_cap'] > 0.0 ? (float) $result['total_cap'] : $last['cap'],
                'float' => (float) $result['board_float_cap'] > 0.0 ? (float) $result['board_float_cap'] : $last['float'],
                'ret' => $result['board_price_return'], 'div' => $result['board_dividend_cash'], 'iss' => $result['board_net_issuance'],
                'duty' => $result['board_stamp_duty'], 'stake' => $result['strategic_stake_cash'] ?? null, 'levy' => $result['board_bank_levy'],
            ];

            $benchmark = $indexFunds[MarketIndex::benchmark()->value] ?? null;
            if ($benchmark !== null) {
                $systemic->report($m, $politics, $benchmark);
            }

            if (IndexCommittee::isReconstitutionTick($tick, $tpy)) {
                foreach (MarketIndex::cases() as $index) {
                    $fund = $indexFunds[$index->value] ?? null;
                    $rec = $committee->reconstitute($index, $list, $tick, $fund?->getIndexLevel());
                    if ($fund !== null) {
                        $accountant->chargeRebalance($fund, $rec['trading_cost'], $rec['level']);
                        if ($rec['added'] !== [] || $rec['deleted'] !== []) {
                            $publisher->publish($fund, 'INDEX', 'Reconstitution.', 0.0);
                        }
                    }
                }
            }
            $isDistributionTick = $tick % max(1, intdiv($tpy, FinancialConstants::FUND_DISTRIBUTIONS_PER_YEAR)) === 0;
            foreach ($indexFunds as $ticker => $fund) {
                $index = MarketIndex::from($ticker);
                if ($isDistributionTick && $accountant->distribute($fund, new \DateTime()) > 0.0) {
                    $publisher->publish($fund, 'DIVIDEND', 'Distribution.', 0.0);
                }
                $etfTracker->updateIndex(
                    $committee->memberCapitalisation($index, $result['float_caps']), false, $ticker, $fund, $m->totalTime,
                    $committee->memberDividendPoints($index, $result['dividend_points']), $dt, 0.0,
                    $committee->memberWeightedHalfSpread($index, $result['float_caps'], $result['half_spreads'])
                );
            }

            if (DistrictRoster::isReconstitutionTick($tick, $tpy)) {
                $rr = $roster->reconstitute($list, $tick);
                foreach ($rr['promoted'] as $t) {
                    $publisher->publish($stocks[$t], 'DISTRICT', 'Promoted to Glasswater Row.', 0.0);
                }
                foreach ($rr['evicted'] as $t) {
                    $publisher->publish($stocks[$t], 'DISTRICT', 'Lost its frontage on Glasswater Row.', 0.0);
                }
            }

            // Drain the wire: one compact row per story.
            foreach (\Redis::$wire as $json) {
                $w = json_decode($json, true);
                $cat = (string) ($w['presented']['category'] ?? 'general');
                $ticker = $w['ticker'] ?? null;
                $row = ['y' => round($tick / $tpy, 4), 'c' => $cat, 'h' => (bool) $w['headline'], 's' => $w['scope'], 'ty' => $w['type'], 'ch' => $w['change_percent']];
                if ($ticker !== null && isset($stocks[$ticker])) {
                    $vol = (float) $stocks[$ticker]->getVolatility();
                    $row['vol'] = round($vol, 4);
                    if ($w['change_percent'] !== null) {
                        $row['z'] = round(NewsDesk::abnormalReturnZ((float) $w['change_percent'] / 100.0, $vol), 3);
                    }
                    if ($cat === 'mna') {
                        $row['cap'] = $prevCap[$ticker];
                        $row['eq0'] = $prevEq[$ticker];
                        $row['eq1'] = (float) $stocks[$ticker]->getTotalEquity();
                        if (preg_match('/\$([0-9,.]+)B (gain|loss) on sale/', (string) $w['description'], $gm) === 1) {
                            $row['gain'] = ($gm[2] === 'loss' ? -1.0 : 1.0) * (float) str_replace(',', '', $gm[1]) * 1e9;
                        }
                        if (preg_match('/\$([0-9,.]+)B/', (string) $w['description'], $mm) === 1) {
                            $row['deal'] = (float) str_replace(',', '', $mm[1]) * 1e9;
                        }
                    }
                }
                if ($cat === 'debt' || $cat === 'general' || $cat === 'economy' || $cat === 'government') {
                    $row['d'] = substr((string) $w['description'], 0, 90);
                }
                if (isset($w['topic'])) {
                    $row['topic'] = $w['topic'];
                }
                fwrite($fh, json_encode($row) . "\n");
            }
            \Redis::$wire = [];

            foreach ($stocks as $t => $s) {
                $prevCap[$t] = (float) $s->getPrice() * (float) $s->getSharesOutstanding();
                $prevEq[$t] = (float) $s->getTotalEquity();
            }
        }
        fclose($fh);
        fwrite(STDERR, sprintf("seed %d: %d ticks (%d tpy) in %.1fs\n", $seed, $ticks, $tpy, microtime(true) - $t0));
        $this->assertTrue(true);
    }

    private function buildEntityManager(): EntityManagerInterface
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $e): void {
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
