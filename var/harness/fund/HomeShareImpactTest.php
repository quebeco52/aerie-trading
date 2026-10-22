<?php

declare(strict_types=1);

use App\Entity\CorporateReport;
use App\Entity\Stock;
use App\Service\Corporate\FailureSweep;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\MacroState;
use App\Service\Market\Pricing\StockTracker;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The Diet's home-equity share for the reserve fund, scripted: live macro + failure sweep + StockTracker on the
 * seeded board, fed every board input MarketTickerCommand feeds (one tick behind), no politics.
 * At SWITCH_YEAR the macro state's reserveHomeEquityShare is set (Redis state edit, nothing else touched).
 *
 * Env: YEARS TPY SEED OUT=<jsonl> ARM=base|closed|open SWITCH_YEAR (3.0) HOME_CLOSED (0.5) HOME_OPEN (0.0)
 * Output: one JSON line: per-tick board return, trade/float and ownership; monthly fund snapshot.
 */
final class HomeShareImpactTest extends KernelTestCase
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
        $years = (float) (getenv('YEARS') ?: 8);
        $tpy = (int) (getenv('TPY') ?: 360);
        $seed = (int) (getenv('SEED') ?: 1);
        $out = getenv('OUT') ?: null;
        $arm = getenv('ARM') ?: 'base';
        $switchTick = (int) round((float) (getenv('SWITCH_YEAR') ?: 3.0) * $tpy);
        $newShare = match ($arm) {
            'closed' => (float) (getenv('HOME_CLOSED') ?: 0.5),
            'open' => (float) (getenv('HOME_OPEN') ?: 0.0),
            default => null,
        };

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
        $sweep = $c->get(FailureSweep::class);
        $liquidity = $c->get(\App\Service\Market\Pricing\LiquidityEngine::class);
        $floatCaps = [];
        $push = [];
        $redis = (new \ReflectionProperty(MacroEngine::class, 'redis'))->getValue($macro);

        $dt = 1.0 / $tpy;
        $ticks = (int) round($years * $tpy);
        $operatorInterval = (int) max(1, $tpy / 24);
        $monthEvery = max(1, intdiv($tpy, 12));
        $lastCap = $lastFloat = $lastReturn = $lastDividends = $lastIssuance = $lastDuty = $lastStake = $lastLevy = null;
        $ret = [];
        $tradeShare = [];
        $months = [];
        $deaths = 0;
        $switched = null;
        $t0 = microtime(true);

        for ($tick = 1; $tick <= $ticks; $tick++) {
            if ($newShare !== null && $tick === $switchTick) {
                $state = MacroState::fromArray(json_decode((string) $redis->get(MacroEngine::REDIS_MACRO_STATE), true, 512, JSON_THROW_ON_ERROR));
                $switched = ['from' => $state->reserveHomeEquityShare, 'to' => $newShare, 't' => $state->totalTime];
                $state->reserveHomeEquityShare = $newShare;
                $redis->set(MacroEngine::REDIS_MACRO_STATE, json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            }
            $m = $macro->updateMacroState($dt, new \App\DTO\BoardReadingDTO($lastCap, $lastFloat, $lastReturn ?? 0.0, $lastDividends ?? 0.0, $lastIssuance ?? 0.0, $lastDuty ?? 0.0, $lastStake ?? 0.0, $lastLevy));

            if ($tick % $operatorInterval === 0) {
                foreach ($sweep->sweep($stocks, $m) as $ev) {
                    if (($ev['type'] ?? '') === 'BANKRUPTCY') {
                        ++$deaths;
                    }
                }
            }

            // Gross push: the permanent share of the fund slice's peak impact (LiquidityEngine::peakImpact, as
            // StockTracker applies it), float-weighted over the board, before anything leans against it.
            $gross = 0.0;
            if ($m->sovereignFundTrade !== 0.0 && $m->boardFloatCap > 0.0 && $floatCaps !== []) {
                $w = array_sum($floatCaps);
                foreach ($stocks as $st) {
                    $fc = $floatCaps[$st->getTicker()] ?? 0.0;
                    $px = (float) $st->getPrice();
                    if ($fc > 0.0 && $px > 0.0 && !$st->isBankrupt()) {
                        $gross += ($fc / $w) * \App\Service\Math\FinancialConstants::PERMANENT_IMPACT_SHARE
                            * $liquidity->peakImpact($st, $m->sovereignFundTrade * ($fc / $m->boardFloatCap) / $px);
                    }
                }
            }
            $push[] = round($gross, 9);

            $result = $tracker->updateStocks($stocks, $dt, false, $m, $tick, $tpy);
            $lastCap = (float) $result['total_cap'] > 0.0 ? (float) $result['total_cap'] : $lastCap;
            $lastFloat = (float) $result['board_float_cap'] > 0.0 ? (float) $result['board_float_cap'] : $lastFloat;
            $lastReturn = (float) $result['board_price_return'];
            $lastDividends = (float) $result['board_dividend_cash'];
            $lastIssuance = (float) $result['board_net_issuance'];
            $lastDuty = (float) $result['board_stamp_duty'];
            $lastStake = (float) $result['strategic_stake_cash'];
            $lastLevy = (float) $result['board_bank_levy'];
            $floatCaps = $result['float_caps'];

            $ret[] = round($lastReturn, 8);
            $tradeShare[] = $m->boardFloatCap > 0.0 ? round($m->sovereignFundTrade / $m->boardFloatCap, 10) : 0.0;

            if ($tick % $monthEvery === 0) {
                $months[] = [
                    't' => round($m->totalTime, 4),
                    'own' => $m->sovereignFundOwnershipShare,
                    'w' => $m->sovereignFundDomesticWeight,
                    'tw' => $m->sovereignFundTargetWeight,
                    'home' => $m->reserveHomeEquityShare,
                    'fhome' => $m->sovereignFundHomeEquityShare,
                    'left' => $m->sovereignFundRebalanceMonthsLeft,
                    'backlog' => $m->sovereignFundRebalanceShare,
                    'fund_gdp' => $m->sovereignFundToGdp,
                    'float' => $m->boardFloatCap,
                    'cap' => $lastCap,
                    'gap' => $m->outputGap,
                    'pol' => $m->policyRate,
                    'fx' => $m->exchangeRateIndex,
                    'erp' => $m->equityRiskPremium,
                ];
            }
        }

        $record = [
            'arm' => $arm, 'seed' => $seed, 'years' => $years, 'tpy' => $tpy, 'switch_tick' => $switchTick,
            'switched' => $switched, 'secs' => round(microtime(true) - $t0, 1), 'deaths' => $deaths,
            'ret' => $ret, 'trade' => $tradeShare, 'push' => $push, 'months' => $months,
        ];
        if ($out !== null) {
            file_put_contents($out, json_encode($record) . "\n");
        }
        fwrite(STDERR, sprintf("%s seed %d: %d ticks in %.1fs, %d deaths\n", $arm, $seed, $ticks, microtime(true) - $t0, $deaths));
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
