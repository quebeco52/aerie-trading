<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
}