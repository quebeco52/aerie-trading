<?php

namespace App\Controller\Admin;

use App\Service\Market\Ticker\HistoryPruner;
use App\Data\Macro\OutputGapChannels;
use App\Service\Macro\Recorder\MacroDiagnosticsProbe;
use App\Service\Macro\Recorder\OutputGapProbe;
use Doctrine\DBAL\Connection;
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
        $empty = ['enabled' => false, 'current' => null, 'previous' => null];

        return new JsonResponse([
            'families' => OutputGapChannels::families(),
            'residualColour' => OutputGapChannels::RESIDUAL_COLOUR,
            'palette' => OutputGapChannels::palette(),
            'live' => $this->readProbe($redis, OutputGapProbe::REDIS_KEY) ?? $empty,
            'diagnostics' => $this->readProbe($redis, MacroDiagnosticsProbe::REDIS_KEY) ?? $empty,
        ]);
    }

    /**
     * Closed quarters, oldest first, so the charts read left to right.
     *
     * Read from macro_report rather than from the ticker's process or a Redis mirror of it. The quarters
     * the panel draws are the same rows App\Command\MacroGapDumpCommand dumps, so a shape read off the
     * chart and a number measured off the file cannot disagree — and the history survives a ticker
     * restart, which the Redis copy it replaces did not: that container has no volume.
     *
     * Three columns, not the row: the chart needs the level, the time and the decomposition, and this
     * runs on every dashboard poll.
     */
    #[Route('/admin/macro/gap-history', name: 'admin_macro_gap_history', methods: ['GET'])]
    public function gapHistory(Connection $conn): JsonResponse
    {
        // Newest first with a LIMIT, then reversed: the range buttons read back from the present, and the
        // rows past the horizon are the ones the panel would never draw.
        $rows = $conn->fetchAllAssociative(
            'SELECT total_time, gap_channels, quarter_diagnostics FROM macro_report
             WHERE gap_channels IS NOT NULL
             ORDER BY id DESC LIMIT ' . HistoryPruner::MACRO_QUARTERS_KEPT
        );

        $quarters = [];
        foreach (array_reverse($rows) as $row) {
            $decoded = json_decode((string) $row['gap_channels'], true);
            if (!is_array($decoded)) {
                continue;
            }

            // The row's own simulated time stamps the decomposition, which carries none of its own.
            $decoded['time'] = (float) $row['total_time'];
            // The inflation and policy accounts of the same quarter, or null on a row recorded before they existed.
            $diagnostics = is_string($row['quarter_diagnostics']) ? json_decode($row['quarter_diagnostics'], true) : null;
            $decoded['diagnostics'] = is_array($diagnostics) ? $diagnostics : null;
            $quarters[] = $decoded;
        }

        return new JsonResponse(['quarters' => $quarters]);
    }

    /**
     * A probe's published window, or null when no ticker has published one.
     *
     * @return array<string, mixed>|null
     */
    private function readProbe(\Redis $redis, string $key): ?array
    {
        $raw = $redis->get($key);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }
}
