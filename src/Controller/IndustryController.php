<?php

declare(strict_types=1);

namespace App\Controller;

use App\Data\Company\Sectors;
use App\Service\View\IndustryPageBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The industry pages: every industry with a listed company, and one industry's prices, plant, sellers and news over
 * the years. Composition lives in App\Service\View\IndustryPageBuilder.
 */
class IndustryController extends AbstractController
{
    #[Route('/industries', name: 'app_industries', methods: ['GET'])]
    public function index(IndustryPageBuilder $builder): Response
    {
        return $this->render('industry/index.html.twig', ['industries' => $builder->index()]);
    }

    #[Route('/industry/{slug}', name: 'app_industry', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function view(string $slug, IndustryPageBuilder $builder): Response
    {
        $industry = Sectors::industryForSlug($slug);
        $page = $industry === null ? null : $builder->build($industry);
        if ($page === null) {
            throw $this->createNotFoundException('No listed company in this industry');
        }

        return $this->render('industry/view.html.twig', ['industry' => $page]);
    }
}
