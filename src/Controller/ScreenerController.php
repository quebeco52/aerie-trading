<?php

namespace App\Controller;

use App\Entity\Stock;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller responsible for the Stock Screener.
 * Allows users to filter and sort through all available stocks based on fundamental metrics.
 */
class ScreenerController extends AbstractController
{
    #[Route('/screener', name: 'app_screener', methods: ['GET'])]
    public function index(Request $request, EntityManagerInterface $entityManager): Response
    {
        $stocks = $entityManager->getRepository(Stock::class)->findAll();

        $screenerData = [];
        foreach ($stocks as $stock) {
            $price = (float) $stock->getPrice();
            $shares = (float) $stock->getSharesOutstanding();
            $eps = (float) $stock->getEarningsPerShare();
            
            $marketCap = $price * $shares;
            $peRatio = $eps > 0 ? $price / $eps : null;
            $dividendYield = $price > 0 ? (((float) $stock->getLastDividend() * 4) / $price) * 100 : 0.0;
            $equity = (float) $stock->getTotalEquity();
            $debtToEquity = $equity > 0 ? ((float) $stock->getTotalDebt() / $equity) : null;

            $businessModel = \App\Data\Sectors::INDUSTRY_METRICS[$stock->getIndustry() ?? 'General']['business_model'] ?? 'none';
            $isFinancial = in_array($businessModel, ['commercial_bank', 'insurance', 'brokerage', 'asset_manager']);
            $effectiveRoic = $isFinancial 
                ? ((float) $stock->getCurrentRoe() ?: (float) $stock->getBaselineRoe()) 
                : ((float) $stock->getCurrentRoic() ?: (float) $stock->getBaselineRoic());

            $screenerData[] = [
                'ticker'       => $stock->getTicker(),
                'name'         => $stock->getName(),
                'sector'       => $stock->getSector(),
                'price'        => $price,
                'marketCap'    => $marketCap,
                'peRatio'      => $peRatio,
                'eps'          => $eps,
                'dividendYield'=> $dividendYield,
                'currentRoic'  => $effectiveRoic,
                'equity'       => $equity,
                'debt'         => (float) $stock->getTotalDebt(),
                'debtToEquity' => $debtToEquity,
            ];
        }

        // Sort by Market Cap descending by default to show the Titans first
        usort($screenerData, fn($a, $b) => $b['marketCap'] <=> $a['marketCap']);

        return $this->render('screener/index.html.twig', [
            'stocks' => $screenerData,
        ]);
    }
}