<?php

declare(strict_types=1);

use App\DTO\MacroStateDTO;
use App\Entity\Stock;
use App\Service\Corporate\EarningsEngine;
use App\Service\Math\FirmEconomics;
use App\Service\Model\BusinessModelRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The seeded miner and producer (CNDR, SINK) through the real earnings engine for eight quarters on a calm economy,
 * under the founding rules on extraction and under the strictest, each arm on a freshly seeded board and the same
 * random stream: reported operating margin, EPS, and the market's extraction cost base over revenue.
 *
 * Run from the project root:
 * php -d memory_limit=2G vendor/bin/phpunit --bootstrap var/harness/bootstrap.php --no-configuration var/harness/polls/ExtractionMarginTest.php
 */
final class ExtractionMarginTest extends KernelTestCase
{
    private const TICKERS = ['CNDR', 'SINK'];
    private const QUARTERS = 8;

    /** @var list<object> */
    private array $persisted = [];
    /** @var array<string, Stock> */
    private array $stocks = [];
    private bool $capturing = true;

    protected static function getKernelClass(): string
    {
        return \HarnessKernel::class;
    }

    public function testMargins(): void
    {
        $seeds = (int) (getenv('SEEDS') ?: 30);
        $factor = FirmEconomics::calculateExtractionCostFactor(1.0);
        $rows = [];
        for ($seed = 1; $seed <= $seeds; ++$seed) {
            $founding = $this->arm(0.0, $seed);
            $strict = $this->arm(1.0, $seed);
            foreach (self::TICKERS as $ticker) {
                $rows[$ticker][] = [
                    'dm' => $strict[$ticker]['margin'] - $founding[$ticker]['margin'],
                    'de' => log(max(1e-6, $strict[$ticker]['eps']) / max(1e-6, $founding[$ticker]['eps'])),
                    'cash' => $founding[$ticker]['cashCost'],
                    'base' => $founding[$ticker]['base'],
                    'margin' => $founding[$ticker]['margin'],
                    'baseValue' => $founding[$ticker]['baseValue'],
                ];
            }
        }

        $mean = static fn (array $xs): float => array_sum($xs) / count($xs);
        $se = static fn (array $xs): float => sqrt(array_sum(array_map(static fn (float $x): float => ($x - $mean($xs)) ** 2, $xs)) / (count($xs) - 1) / count($xs));
        // The broker's turnover base against its value, on a founding board after the same eight quarters, and what the
        // real MarketEngine makes of a law certain from the next budget: the strictest rules for the miners, the big
        // state's duty for the broker, against no forecast.
        $rook = $this->arm(0.0, 1, 'ROOK');
        fwrite(STDERR, sprintf("ROOK  stamp-duty turnover base / market value %.3f, / revenue %.3f\n", $rook['ROOK']['dutyValue'], $rook['ROOK']['duty']));
        foreach (['CNDR' => ['extractionStringency' => 1.0], 'SINK' => ['extractionStringency' => 1.0], 'ROOK' => ['stampDutyRate' => \App\Service\Politics\PoliticsEngine::POLICY_BIG_STATE_STAMP_DUTY]] as $ticker => $law) {
            $this->arm(0.0, 1, $ticker);
            $stock = $this->stocks[$ticker];
            $c = static::getContainer();
            $fairValue = function (MacroStateDTO $macro) use ($c, $stock): float {
                $health = $c->get(\App\Service\Corporate\DebtEngine::class)->analyzeDebtHealth($stock, $macro);
                $ctx = \App\DTO\MarketPricingContext::forStock($stock, $macro, $health, $c->get(\App\Service\Corporate\Holdings\AnchorStakeLedger::class));

                return $c->get(\App\Service\Market\MarketEngine::class)->calculateNextPrice($ctx)['perceived_fair_value'];
            };
            $noneMacro = new MacroStateDTO();
            $certainMacro = new MacroStateDTO(expectedLevers: $law, expectedPolicyFrom: 0.5, previousExpectedLevers: $law, previousExpectedPolicyFrom: 0.5);
            $none = $fairValue($noneMacro);
            $certain = $fairValue($certainMacro);
            // The discount rate less growth the engine spread the law at: the one at which the two earnings gaps' present
            // values differ by what the fair values do (the tax law is the same in both, so the tax ratio cancels).
            $bases = \App\DTO\MarketPricingContext::forStock($stock, $noneMacro, $c->get(\App\Service\Corporate\DebtEngine::class)->analyzeDebtHealth($stock, $noneMacro), $c->get(\App\Service\Corporate\Holdings\AnchorStakeLedger::class))->policyBasesPerShare;
            $tax = $stock->getIndustry() !== null ? \App\Data\Company\Sectors::strategyFor($stock->getIndustry())->getEffectiveTaxRate($noneMacro->corporateTaxRate) : 0.21;
            $diff = static fn (float $cap): float => (\App\Service\Market\PolicyCapitalization::earningsGap($certainMacro, $bases, $tax, $cap) - \App\Service\Market\PolicyCapitalization::earningsGap($noneMacro, $bases, $tax, $cap)) / $cap;
            $lo = 0.005;
            $hi = 1.0;
            for ($i = 0; $i < 80; ++$i) {
                $mid = ($lo + $hi) / 2.0;
                if ($diff($mid) < $certain - $none) {
                    $lo = $mid;
                } else {
                    $hi = $mid;
                }
            }
            fwrite(STDERR, sprintf(
                "%s  fair value with %s certain from the next budget: %+.1f%%; base / fair value %.3f, price / fair value %.2f, k - g %.3f\n",
                $ticker,
                json_encode($law),
                100 * ($certain / $none - 1.0),
                array_sum($bases) / $none,
                (float) $stock->getPrice() / $none,
                $mid
            ));
        }

        foreach ($rows as $ticker => $r) {
            $dm = array_column($r, 'dm');
            $de = array_column($r, 'de');
            fwrite(STDERR, sprintf(
                "%s  %d seeds: founding margin %.4f; strictest rules %+.2f pp (se %.2f), EPS %+.1f%% (se %.1f); cash cost / revenue %.3f, so the evidence's %+.2f pp; market base / revenue %.3f, / market value %.3f\n",
                $ticker,
                count($r),
                $mean(array_column($r, 'margin')),
                100 * $mean($dm),
                100 * $se($dm),
                100 * $mean($de),
                100 * $se($de),
                $mean(array_column($r, 'cash')),
                -100 * ($factor - 1.0) * $mean(array_column($r, 'cash')),
                $mean(array_column($r, 'base')),
                $mean(array_column($r, 'baseValue')),
            ));
        }
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{margin: float, eps: float, cashCost: float, base: float, baseValue: float, duty: float, dutyValue: float}>
     */
    private function arm(float $stringency, int $seed, ?string $only = null): array
    {
        self::ensureKernelShutdown();
        $this->persisted = [];
        $this->capturing = true;
        mt_srand($seed);
        $_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
        $_SERVER['KERNEL_CLASS'] = 'App\Kernel';
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $c = static::getContainer();
        $c->set('doctrine.orm.default_entity_manager', $this->buildEntityManager());

        $app = new \Symfony\Bundle\FrameworkBundle\Console\Application(static::$kernel);
        ob_start();
        $status = (new CommandTester($app->find('app:market-seed')))->execute([]);
        ob_end_clean();
        $this->assertSame(0, $status);

        $stocks = [];
        $id = 1;
        foreach ($this->persisted as $e) {
            if ($e instanceof Stock) {
                (new \ReflectionProperty(Stock::class, 'id'))->setValue($e, $id++);
                $stocks[$e->getTicker()] = $e;
            }
        }
        $this->capturing = false;
        $this->stocks = $stocks;

        $engine = $c->get(EarningsEngine::class);
        $macro = new MacroStateDTO(extractionStringency: $stringency);
        $out = [];
        foreach ($only === null ? self::TICKERS : [$only] as $ticker) {
            $stock = $stocks[$ticker];
            $margins = [];
            $eps = [];
            for ($q = 0; $q < self::QUARTERS; ++$q) {
                $engine->calculate($stock, $macro, ($q * 63) + EarningsEngine::resolveReportingTick($ticker, 252), 252);
                $margins[] = (float) $stock->getReportedOperatingMargin();
                $eps[] = (float) $stock->getEarningsPerShare();
            }
            $strategy = \App\Data\Company\Sectors::strategyFor($stock->getIndustry());
            $revenue = (float) $stock->getTotalRevenue();
            // Cash costs: revenue less operating earnings less depreciation, the part a productivity loss scales.
            $depreciation = $strategy->getDepreciableBase($stock) * (float) ($stock->getDepreciationRate() ?? 0.0);
            $out[$ticker] = [
                'margin' => array_sum(array_slice($margins, 1)) / (self::QUARTERS - 1),
                'eps' => array_sum(array_slice($eps, 1)) / (self::QUARTERS - 1),
                'cashCost' => 1.0 - (float) $stock->getReportedOperatingMargin() - ($revenue > 0.0 ? $depreciation / $revenue : 0.0),
                'base' => $revenue > 0.0 ? $strategy->annualExtractionCostBase($stock) / $revenue : 0.0,
                'baseValue' => $strategy->annualExtractionCostBase($stock) / max(1.0, (float) $stock->getPrice() * (float) $stock->getSharesOutstanding()),
                'duty' => $revenue > 0.0 ? $strategy->annualStampDutyTurnoverBase($stock) / $revenue : 0.0,
                'dutyValue' => $strategy->annualStampDutyTurnoverBase($stock) / max(1.0, (float) $stock->getPrice() * (float) $stock->getSharesOutstanding()),
            ];
        }

        return $out;
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
