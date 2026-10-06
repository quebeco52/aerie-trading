<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\View\NewsFeedBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The newswire: every district story, company announcement and fund notice in one feed, by section, and the latest
 * headline for the strip under the navigation.
 */
class NewsController extends AbstractController
{
    #[Route('/news', name: 'app_news', methods: ['GET'])]
    public function index(Request $request, NewsFeedBuilder $feed): Response
    {
        return $this->render('news/index.html.twig', $feed->build((string) $request->query->get('section', 'all')));
    }

    /** The newest headline as the strip renders it, its time printed as the page prints it. */
    #[Route('/api/news/headline', name: 'api_news_headline', methods: ['GET'])]
    public function headline(NewsFeedBuilder $feed): JsonResponse
    {
        $item = $feed->latestHeadline();
        if ($item === null) {
            return new JsonResponse(null);
        }

        $card = $item['card'];
        $card['recordedAt'] = $card['recordedAt'] instanceof \DateTimeInterface ? $card['recordedAt']->format('Y-m-d H:i') : (string) $card['recordedAt'];

        return new JsonResponse([
            'ticker' => $item['ticker'],
            'scope' => $item['scope'],
            'headline' => true,
            'presented' => $card,
        ]);
    }
}
