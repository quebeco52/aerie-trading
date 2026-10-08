<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Data\AerieDiet;
use App\Data\InitialMarket;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\DietElection;
use App\Entity\Stock;
use App\Repository\ElectionOddsRepository;
use App\Repository\MacroReportHistoryRepository;
use App\Repository\RateDecisionRepository;
use App\Repository\StockRepository;
use App\Service\Corporate\CapExEngine;
use App\Service\Corporate\CapitalAllocationEngine;
use App\Service\Corporate\CorporateLedgerService;
use App\Service\Corporate\DebtEngine;
use App\Service\Corporate\EarningsEngine;
use App\Service\Corporate\TreasuryEngine;
use App\Service\Event\MarketEventPublisher;
use App\Service\Event\NarrativeEngine;
use App\Service\Market\Pricing\MarketConsensusEngine;
use App\Service\Math\CorporateMetrics;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Market\Pricing\OpeningBoardBuilder;
use App\Service\Math\MathUtility;
use App\Service\Politics\ElectionRecorder;
use App\Service\Politics\PoliticsEngine;
use App\Service\View\GovernmentPageBuilder;
use App\Service\Politics\PoliticsHistoryRecorder;
use App\Entity\ElectionOdds;
use App\Entity\RateDecision;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Renders the government pages and every party page from a headless run of the economy and the politics, for a
 * screenshot. Not in any suite: bin/render-pages runs it. YEARS and SEED pick the run; FORCE_PRESSURE shows a cabinet
 * leaning on the Monetary Authority.
 */
final class GovernmentRenderTest extends KernelTestCase
{
    /** Weekly steps: enough for elections and budget rounds to land where they would live. */
    private const TICKS_PER_YEAR = 52;

    public function testRender(): void
    {
        $years = (float) (getenv('YEARS') ?: 41.3);
        $seed = (int) (getenv('SEED') ?: 7);

        mt_srand($seed);
        $math = new MathUtility();
        $redis = new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];

            public function get(mixed $key): mixed
            {
                return $this->store[(string) $key] ?? false;
            }

            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool
            {
                $this->store[$key] = (string) $value;

                return true;
            }
        };
        $economy = new MacroEngine($math, $redis, new MacroSnapshotRecorder(), new MonetaryPolicySubsystem($math), new LaborMarketSubsystem(),
            new MacroAggregateSubsystem($math), new CommodityLogisticsSubsystem($math), new AssetMarketSubsystem($math), new CreditFiscalSubsystem($math));
        $engine = new PoliticsEngine(MathUtility::ownStream($seed), $redis);

        $elections = new InMemoryDietElections();
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use ($elections): void {
            if ($entity instanceof DietElection && !in_array($entity, $elections->rows, true)) {
                $elections->rows[] = $entity;
            }
        });
        $recorder = new ElectionRecorder($entityManager, $elections);
        // Each meeting and each forecast, as the ticker writes them.
        $written = [];
        $historyManager = $this->createStub(EntityManagerInterface::class);
        $historyManager->method('persist')->willReturnCallback(static function (object $entity) use (&$written): void {
            $written[] = $entity;
        });
        $history = new PoliticsHistoryRecorder($historyManager);

        $policy = $engine->liveState()->policy();
        $macro = new MacroStateDTO();
        $politics = new PoliticsStateDTO();
        $laws = [];
        for ($tick = 0; $tick < (int) round($years * self::TICKS_PER_YEAR); ++$tick) {
            $macro = $economy->updateMacroState(1.0 / self::TICKS_PER_YEAR, policy: $policy);
            $politics = $engine->updatePolitics($macro, 1.0 / self::TICKS_PER_YEAR);
            $policy = $politics->policy();
            $recorder->record($politics);
            $history->record($politics);
            // The quarterly record the law charts read, as macro_report keeps it: the last 25 years.
            if (MathUtility::crossedSimulatedBoundary($macro->totalTime, 1.0 / self::TICKS_PER_YEAR, 0.25)) {
                $laws[] = ['t' => $macro->totalTime, 'levers' => PoliticsEngine::standingLevers($politics), 'capitalRequirement' => $politics->bankCapitalRequirement];
                $laws = array_slice($laws, -MacroReportHistoryRepository::QUARTERS);
            }
        }

        if (getenv('FORCE_PRESSURE')) {
            $politics = new PoliticsStateDTO(...(['pressureSince' => $politics->totalTime - 0.25, 'pressureGivingIn' => 1.0, 'pressureCabinet' => $politics->coalitionFormedAt] + get_object_vars($politics)));
        }

        self::bootKernel();
        $container = static::getContainer();
        /** @var RequestStack $stack */
        $stack = $container->get('request_stack');
        $twig = $container->get('twig');
        $reports = $this->createStub(MacroReportHistoryRepository::class);
        $reports->method('laws')->willReturn($laws);
        // The opening board, each firm through one quarter's report so its models record what each law reaches.
        $opening = $container->get(OpeningBoardBuilder::class);
        $metrics = new CorporateMetrics();
        $debt = new DebtEngine($math, $metrics);
        $capex = new CapExEngine();
        $earnings = new EarningsEngine(
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(MarketEventPublisher::class),
            new CapitalAllocationEngine($this->createStub(CorporateLedgerService::class), $metrics, $debt, $math, new TreasuryEngine($metrics, $debt, $capex, $math)),
            $debt,
            $capex,
            $math,
            $metrics,
            $this->createStub(NarrativeEngine::class),
            new MarketConsensusEngine()
        );
        $board = [];
        foreach (InitialMarket::STOCKS as $row) {
            $stock = (new Stock())->setTicker($row['ticker']);
            $opening->applyListing($stock, $row);
            $opening->open($stock, $row, $macro);
            $earnings->calculate($stock, $macro, EarningsEngine::resolveReportingTick($row['ticker'], self::TICKS_PER_YEAR), self::TICKS_PER_YEAR);
            $board[] = $stock;
        }
        $stocks = $this->createStub(StockRepository::class);
        $stocks->method('findAll')->willReturn($board);
        $decisions = $this->createStub(RateDecisionRepository::class);
        $decisions->method('findSince')->willReturnCallback(static fn(float $since): array => array_values(array_filter(
            $written,
            static fn(object $row): bool => $row instanceof RateDecision && $row->getSimTime() >= $since
        )));
        $odds = $this->createStub(ElectionOddsRepository::class);
        $odds->method('findForVote')->willReturnCallback(static fn(float $voteAt, float $tolerance): array => array_values(array_filter(
            $written,
            static fn(object $row): bool => $row instanceof ElectionOdds && abs($row->getVoteAt() - $voteAt) <= $tolerance
        )));
        $builder = new GovernmentPageBuilder($elections, $reports, $stocks, $decisions, $odds);

        $pages = [
            'government' => ['/government', 'government/index.html.twig', $builder->buildHub($macro, $politics)],
            'diet' => ['/government/diet', 'government/diet.html.twig', $builder->buildDiet($macro, $politics)],
            'election' => ['/government/election', 'government/election.html.twig', $builder->buildElection($macro, $politics)],
            'budget' => ['/government/budget', 'government/budget.html.twig', $builder->buildBudget($macro, $politics)],
            'council' => ['/council', 'government/council.html.twig', $builder->buildCouncil($macro, $politics)],
            'authority' => ['/authority', 'government/authority.html.twig', $builder->buildAuthority($macro, $politics)],
        ];
        foreach (AerieDiet::PARTIES as $party) {
            $data = $builder->buildParty($macro, $politics, $party);
            $pages['party-' . $party] = ['/government/parties/' . $data['party']['slug'], 'government/party.html.twig', $data];
        }
        foreach ($pages as $name => [$path, $template, $data]) {
            $stack->push(Request::create($path));
            $html = $twig->render($template, $data);
            $stack->pop();
            $this->assertFileExists(OfflinePage::write($name, $html));
        }
    }
}
