<?php

namespace App\Controller\Admin;

use App\Data\OutputGapChannels;
use App\Service\Macro\Recorder\OutputGapProbe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class MacroController extends AbstractController
{
    #[Route('/admin/macro', name: 'admin_macro')]
    public function index(\Redis $redis, \App\Service\Macro\MacroStateProvider $macroStateProvider): Response
    {
        $macroState = $macroStateProvider->livePayload();
        $liveSectors = json_decode($redis->get('macro_sectors_live') ?: '{}', true);

        return $this->render('admin/macroDashboard.html.twig', [
            'macro_state' => $macroState,
            'live_sectors' => $liveSectors
        ]);
    }

    /**
     * What is moving the output gap, as the running ticker last published it.
     *
     * The probe lives in the ticker's process and this one cannot see it, so the reading comes off Redis.
     * A missing key is not an error: it means no ticker is running, and the panel says so rather than
     * showing a decomposition of nothing.
     */
    #[Route('/admin/macro/gap-debug', name: 'admin_macro_gap_debug', methods: ['GET'])]
    public function gapDebug(\Redis $redis): JsonResponse
    {
        $raw = $redis->get(OutputGapProbe::REDIS_KEY);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return new JsonResponse([
            'families' => OutputGapChannels::families(),
            'residualColour' => OutputGapChannels::RESIDUAL_COLOUR,
            'live' => is_array($decoded) ? $decoded : ['enabled' => false, 'current' => null, 'previous' => null],
        ]);
    }

    /**
     * Closed quarters, oldest first, so the charts read left to right.
     *
     * The list is stored newest-first because that is the end Redis caps, and reversed here rather than
     * in the browser: the page should not have to know which end of the store is which.
     */
    #[Route('/admin/macro/gap-history', name: 'admin_macro_gap_history', methods: ['GET'])]
    public function gapHistory(\Redis $redis): JsonResponse
    {
        $rows = $redis->lRange(OutputGapProbe::HISTORY_KEY, 0, OutputGapProbe::HISTORY_QUARTERS - 1);
        $quarters = [];

        foreach (is_array($rows) ? array_reverse($rows) : [] as $row) {
            $decoded = json_decode((string) $row, true);
            if (is_array($decoded)) {
                $quarters[] = $decoded;
            }
        }

        return new JsonResponse(['quarters' => $quarters]);
    }
}
