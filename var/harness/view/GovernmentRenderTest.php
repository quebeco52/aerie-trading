<?php

declare(strict_types=1);

// Renders the government pages from a headless run of the economy and the politics, for screenshots.
// php vendor/bin/phpunit var/harness/view/GovernmentRenderTest.php   (YEARS, SEED, OUT env vars; writes $OUT/*.html)

namespace App\Tests\Harness;

use App\Data\Politics\AerieDiet;
use App\DTO\MacroStateDTO;
use App\DTO\PoliticsStateDTO;
use App\Entity\DietElection;
use App\Repository\DietElectionRepository;
use App\Service\Macro\MacroEngine;
use App\Service\Macro\Recorder\MacroSnapshotRecorder;
use App\Service\Macro\Subsystem\{AssetMarketSubsystem, CommodityLogisticsSubsystem, CreditFiscalSubsystem, LaborMarketSubsystem, MacroAggregateSubsystem, MonetaryPolicySubsystem};
use App\Service\Math\MathUtility;
use App\Service\Politics\ElectionRecorder;
use App\Service\Politics\PoliticsEngine;
use App\Service\View\GovernmentPageBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class InMemoryDietElections extends DietElectionRepository
{
    /** @var list<DietElection> */
    public array $rows = [];

    public function __construct() {}

    public function findChronological(): array
    {
        return $this->rows;
    }

    public function findLatest(): ?DietElection
    {
        return $this->rows === [] ? null : $this->rows[array_key_last($this->rows)];
    }
}

final class GovernmentRenderTest extends KernelTestCase
{
    public function testRender(): void
    {
        $years = (float) (getenv('YEARS') ?: 41.3);
        $seed = (int) (getenv('SEED') ?: 7);
        $out = getenv('OUT') ?: sys_get_temp_dir();

        mt_srand($seed);
        $math = new MathUtility();
        $redis = new class extends \Redis {
            /** @var array<string, string> */
            private array $store = [];
            public function get(mixed $key): mixed { return $this->store[(string) $key] ?? false; }
            public function set(string $key, mixed $value, mixed $options = null): \Redis|string|bool { $this->store[$key] = (string) $value; return true; }
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

        $tpy = 52;
        $policy = $engine->liveState()->policy();
        $macro = new MacroStateDTO();
        $politics = new PoliticsStateDTO();
        for ($tick = 0; $tick < (int) round($years * $tpy); ++$tick) {
            $macro = $economy->updateMacroState(1.0 / $tpy, policy: $policy);
            $politics = $engine->updatePolitics($macro, 1.0 / $tpy);
            $policy = $politics->policy();
            $recorder->record($politics);
        }

        if (getenv('FORCE_PRESSURE')) {
            // A cabinet leaning on the Authority, which is giving ground, for the panel's look.
            $politics = new PoliticsStateDTO(...(['pressureSince' => $politics->totalTime - 0.25, 'pressureGivingIn' => 1.0, 'pressureCabinet' => $politics->coalitionFormedAt] + get_object_vars($politics)));
        }

        self::bootKernel();
        $container = static::getContainer();
        /** @var RequestStack $stack */
        $stack = $container->get('request_stack');
        $twig = $container->get('twig');
        $builder = new GovernmentPageBuilder($elections);
        $css = (string) file_get_contents(__DIR__ . '/../../tailwind/app.built.css');

        $pages = ['government' => ['/government', 'government/index.html.twig', $builder->build($macro, $politics)]];
        if (method_exists($builder, 'buildParty')) {
            foreach (AerieDiet::PARTIES as $party) {
                $data = $builder->buildParty($macro, $politics, $party);
                $pages['party-' . $party] = ['/government/parties/' . $data['party']['slug'], 'government/party.html.twig', $data];
            }
        }
        foreach ($pages as $name => [$path, $template, $data]) {
            $stack->push(Request::create($path));
            $html = $twig->render($template, $data);
            $stack->pop();
            // The page's own stylesheet, inlined so the file renders without a server; scripts and web fonts dropped.
            $html = (string) preg_replace('#<link rel="stylesheet" href="[^"]*app[^"]*\.css">#', '<style>' . $css . '</style>', $html);
            $html = (string) preg_replace('#<script type="importmap".*?</script>|<script type="module".*?</script>|<link rel="modulepreload"[^>]*>#s', '', $html);
            file_put_contents("{$out}/{$name}.html", $html);
        }

        fwrite(STDERR, sprintf("t=%.2f, %d elections, cabinet %s, support %s, talks %s\n", $politics->totalTime, count($elections->rows),
            implode('+', AerieDiet::governingParties($politics->governingCoalition)), implode('+', AerieDiet::governingParties($politics->supportParties)),
            $politics->coalitionTakesOfficeAt > $politics->totalTime ? 'under way' : 'none'));
        $this->assertFileExists("{$out}/government.html");
    }
}
