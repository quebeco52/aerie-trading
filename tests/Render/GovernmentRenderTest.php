<?php

declare(strict_types=1);

namespace App\Tests\Render;

use App\Data\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\DietElection;
use App\Repository\MacroReportHistoryRepository;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\AssetMarketSubsystem;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Macro\Subsystem\CreditFiscalSubsystem;
use App\Service\Macro\Subsystem\LaborMarketSubsystem;
use App\Service\Macro\Subsystem\MacroAggregateSubsystem;
use App\Service\Macro\Subsystem\MonetaryPolicySubsystem;
use App\Service\Math\MathUtility;
use App\Service\Politics\ElectionRecorder;
use App\Service\Politics\PoliticsEngine;
use App\Service\View\GovernmentPageBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Renders the government page and every party page from a headless run of the economy and the politics, for a
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

        $policy = $engine->liveState()->policy();
        $macro = new MacroStateDTO();
        $politics = new PoliticsStateDTO();
        $laws = [];
        for ($tick = 0; $tick < (int) round($years * self::TICKS_PER_YEAR); ++$tick) {
            $macro = $economy->updateMacroState(1.0 / self::TICKS_PER_YEAR, policy: $policy);
            $politics = $engine->updatePolitics($macro, 1.0 / self::TICKS_PER_YEAR);
            $policy = $politics->policy();
            $recorder->record($politics);
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
        $builder = new GovernmentPageBuilder($elections, $reports);

        $pages = ['government' => ['/government', 'government/index.html.twig', $builder->build($macro, $politics)]];
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
