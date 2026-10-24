<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Macro\MacroStateProvider;
use App\Service\Macro\Subsystem\CommodityLogisticsSubsystem;
use App\Service\Math\MacroConstants;
use App\Service\View\EconomyExposureBuilder;
use App\Service\View\MacroHistoryPresenter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The macroeconomic page: the live state of the District's economy and twenty-five years of its history.
 *
 * The series themselves come from /api/macro-reports; this renders the vitals for the first paint, and the live frame
 * takes over from there. Below the history, the listed firms each driver moves most.
 */
class EconomyController extends AbstractController
{
    #[Route('/economy', name: 'app_economy', methods: ['GET'])]
    public function index(MacroStateProvider $macroStateProvider, EconomyExposureBuilder $exposureBuilder): Response
    {
        $macroState = $macroStateProvider->liveState();

        return $this->render('economy/index.html.twig', [
            'macro' => $macroState,
            'economic_cycle' => $macroState->economicCycleLabel(),
            'exposures' => $exposureBuilder->build(),
            'chartConstants' => self::chartConstants(),
        ]);
    }

    /**
     * The fixed parameters the charts draw against (a reference line, a spread the history omits, a lede's figure),
     * from the models that own them, so the page cannot quote a stale copy.
     *
     * @return array<string, float|array<string, string>>
     */
    public static function chartConstants(): array
    {
        return [
            'mortgageSpread' => MacroConstants::RESIDENTIAL_MORTGAGE_SPREAD,
            'gapGroups' => MacroHistoryPresenter::GAP_GROUP_LABELS,
            'structuralLabourGrowth' => MacroConstants::STRUCTURAL_LABOR_GROWTH_RATE,
            'depositBeta' => MacroConstants::SYSTEM_DEPOSIT_BETA_BASE,
            'powerReference' => MacroConstants::REFERENCE_POWER_PRICE,
            'gasReference' => MacroConstants::REFERENCE_GAS_PRICE,
            'sparkHeatRate' => CommodityLogisticsSubsystem::SPARK_SPREAD_BENCHMARK_HEAT_RATE,
            'policyUncertaintyBaseline' => MacroConstants::EPU_BASELINE,
        ];
    }
}
