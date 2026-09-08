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

        // Latest quarterly report per stock, in one query: the two leverage and cash-quality columns below
        // read statement lines that only exist on the report, and fetching them per row would be N+1.
        $latestReports = [];
        $rows = $entityManager->getConnection()->fetchAllAssociative(
            'SELECT cr.stock_id, cr.ebitda, cr.free_cash_flow, cr.net_income, cr.operating_cash_flow
             FROM corporate_report cr
             INNER JOIN (
                 SELECT stock_id, MAX(recorded_at) AS latest_at FROM corporate_report GROUP BY stock_id
             ) latest ON latest.stock_id = cr.stock_id AND latest.latest_at = cr.recorded_at'
        );
        foreach ($rows as $row) {
            $latestReports[(int) $row['stock_id']] = $row;
        }

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
            $isFinancial = \App\Data\Sectors::isFinancial($businessModel);
            $effectiveRoic = $isFinancial 
                ? ((float) $stock->getCurrentRoe() ?: (float) $stock->getBaselineRoe()) 
                : ((float) $stock->getCurrentRoic() ?: (float) $stock->getBaselineRoic());

            // Net debt to EBITDA is the leverage ratio lenders actually covenant on. Deposits are a bank's
            // raw material, not leverage in this sense, so the ratio is left blank for balance-sheet businesses.
            $report = $latestReports[(int) $stock->getId()] ?? null;
            $annualEbitda = $report !== null ? (float) $report['ebitda'] * 4.0 : 0.0;
            $netDebt = (float) $stock->getTotalDebt() - (float) $stock->getCorporateTreasury();
            $netDebtToEbitda = (!$isFinancial && $annualEbitda > 0.0) ? $netDebt / $annualEbitda : null;

            // Share of reported profit that arrived as free cash. Below one means earnings are running
            // ahead of cash; well above one usually means heavy non-cash charges or a working capital release.
            $annualFcf = (float) $stock->getFreeCashFlowPerShare() * $shares;
            $trailingNetIncome = (float) $stock->getTotalNetIncome();
            $fcfConversion = $trailingNetIncome > 0.0 ? $annualFcf / $trailingNetIncome : null;

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
                'netDebtToEbitda' => $netDebtToEbitda,
                'fcfConversion' => $fcfConversion,
                'isBankrupt'   => $stock->isBankrupt(),
            ];
        }

        // Sort by active vs bankrupt first, then by Market Cap descending
        usort($screenerData, function ($a, $b) {
            if ($a['isBankrupt'] !== $b['isBankrupt']) {
                return $a['isBankrupt'] ? 1 : -1;
            }
            return $b['marketCap'] <=> $a['marketCap'];
        });

        return $this->render('screener/index.html.twig', [
            'stocks' => $screenerData,
        ]);
    }
}