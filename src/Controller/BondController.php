<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\MacroStateDTO;
use App\Entity\Bond;
use App\Entity\User;
use App\Repository\BondRepository;
use App\Service\Macro\MacroStateProvider;
use App\Repository\HoldingRepository;
use App\Repository\TradeOrderRepository;
use App\Service\Market\BondPricingEngine;
use App\Service\Market\PriceChangeFeed;
use App\Service\Math\FinancialConstants;
use App\Service\User\CostBasisCalculator;
use App\Service\User\CouponIncomeCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The sovereign bond desk: the outstanding ladder, and the page for one issue.
 *
 * Separate from StockController rather than a set of branches inside it. An equity page is built around
 * earnings, margins and a business model; a bond page is built around a maturity date, a coupon and two
 * risk measures, and the only thing the two genuinely share is a price chart and an order ticket.
 */
class BondController extends AbstractController
{
    /**
     * The outstanding ladder, grouped by original tenor, alongside the live curve.
     */
    #[Route('/bonds', name: 'app_bond_ladder')]
    public function ladder(
        BondRepository $bondRepository,
        HoldingRepository $holdingRepository,
        MacroStateProvider $macroStateProvider,
        BondPricingEngine $pricingEngine,
        PriceChangeFeed $priceChangeFeed
    ): Response {
        $macro = $macroStateProvider->liveState();

        $bonds = $bondRepository->findActiveAlongTheCurve();

        $rows = [];
        foreach ($bonds as $bond) {
            $rows[(string) (float) $bond->getTenorYears()][] = [
                'bond' => $bond,
                'yearsToMaturity' => $bond->yearsToMaturity($macro->totalTime),
                'change' => $priceChangeFeed->changeForTicker($bond->getTicker(), (float) $bond->getCleanPrice()),
            ];
        }

        // The corporate market, read one borrower at a time. Grouped by issuer rather than by tenor: a
        // company's own ladder is what a credit reader compares, and its rating and spread belong to the
        // borrower rather than to any one of its issues.
        $corporate = [];
        foreach ($bondRepository->findActiveCorporate() as $bond) {
            $issuer = $bond->getIssuer();

            if ($issuer === null) {
                continue;
            }

            $ticker = $issuer->getTicker();

            if (!isset($corporate[$ticker])) {
                $corporate[$ticker] = [
                    'issuer' => $issuer,
                    'rating' => $issuer->getCreditRating(),
                    'spread' => (float) $issuer->getDynamicCreditSpread(),
                    'issues' => [],
                ];
            }

            $corporate[$ticker]['issues'][] = [
                'bond' => $bond,
                'yearsToMaturity' => $bond->yearsToMaturity($macro->totalTime),
                'change' => $priceChangeFeed->changeForTicker($bond->getTicker(), (float) $bond->getCleanPrice()),
            ];
        }

        // Every position the signed-in user holds, so the ladder can mark their own line items.
        $holdings = [];
        $user = $this->getUser();
        if ($user instanceof User) {
            foreach ($holdingRepository->findBondHoldings($user) as $holding) {
                $holdings[$holding->getBond()->getTicker()] = (int) $holding->getQuantity();
            }
        }

        return $this->render('bond/ladder.html.twig', [
            'rowsByTenor' => $rows,
            'corporateByIssuer' => $corporate,
            'holdings' => $holdings,
            'curve' => $this->sampleCurve($pricingEngine, $macro),
            'macro' => $macro,
            'benchmarks' => $this->benchmarkPoints($macro),
            'simulationTime' => $macro->totalTime,
        ]);
    }

    /**
     * One issue: its chart, its risk measures, its remaining cash flows, and the order ticket.
     */
    #[Route('/bond/{ticker}', name: 'app_bond_view')]
    public function view(
        string $ticker,
        BondRepository $bondRepository,
        HoldingRepository $holdingRepository,
        TradeOrderRepository $orders,
        MacroStateProvider $macroStateProvider,
        BondPricingEngine $pricingEngine,
        PriceChangeFeed $priceChangeFeed,
        CostBasisCalculator $costBasis,
        CouponIncomeCalculator $couponIncome
    ): Response {
        $bond = $bondRepository->findOneByTicker($ticker);
        if (!$bond instanceof Bond) {
            throw $this->createNotFoundException('Issue not found');
        }

        $macro = $macroStateProvider->liveState();
        $currentTime = $macro->totalTime;

        $userQuantity = 0;
        $userAvgCost = 0.0;
        $userCouponIncome = 0.0;
        $openOrders = [];

        $user = $this->getUser();
        if ($user instanceof User) {
            $holding = $holdingRepository->findBondHolding($user, $bond);
            $userQuantity = $holding ? (int) $holding->getQuantity() : 0;

            $userAvgCost = $costBasis->calculate($orders->findFilledForUser($user))[$ticker] ?? 0.0;
            $userCouponIncome = $couponIncome->totalsByTicker($user)[$ticker] ?? 0.0;

            $openOrders = $orders->findOpenForUserAndTicker($user, $ticker);
        }

        // Remaining flows are shown as dates and amounts rather than as a present value: the point of the
        // schedule is that a bond's return is contractual, and a discounted total hides exactly that.
        $cashFlows = [];
        foreach ($pricingEngine->remainingCashFlows($bond, $currentTime) as $flow) {
            $cashFlows[] = [
                'yearsAway' => $flow['time'],
                'simulationTime' => $currentTime + $flow['time'],
                'amount' => $flow['amount'],
                'isRedemption' => $flow['amount'] > $bond->couponAmount(),
            ];
        }

        return $this->render('bond/view.html.twig', [
            'bond' => $bond,
            'macro' => $macro,
            'simulationTime' => $currentTime,
            'yearsToMaturity' => $bond->yearsToMaturity($currentTime),
            'changePercent' => $bond->getStatus() === Bond::STATUS_ACTIVE
                ? $priceChangeFeed->changeForTicker($ticker, (float) $bond->getCleanPrice())
                : null,
            'cashFlows' => $cashFlows,
            'annualCouponIncome' => $bond->couponAmount() * FinancialConstants::BOND_COUPON_FREQUENCY,
            'userQuantity' => $userQuantity,
            'userAvgCost' => $userAvgCost,
            'userCouponIncome' => $userCouponIncome,
            'openOrders' => $openOrders,
            'curve' => $this->sampleCurve($pricingEngine, $macro),
            'ticksPerYear' => (int) ($_ENV['SIM_TICKS_PER_YEAR'] ?? 14400),
        ]);
    }

    /**
     * The fitted term structure sampled across tenors, for the curve chart.
     */
    #[Route('/api/bond-curve', name: 'api_bond_curve')]
    public function curve(MacroStateProvider $macroStateProvider, BondPricingEngine $pricingEngine): JsonResponse
    {
        return $this->json($this->sampleCurve($pricingEngine, $macroStateProvider->liveState()));
    }

    /**
     * Samples the live curve at the display tenors.
     *
     * Read through BondPricingEngine rather than interpolated between the four published benchmark points,
     * so the drawn curve is the same function the desk discounts with. A chart fitted through the
     * benchmarks would be a picture of a different curve than the one pricing the bonds beneath it.
     *
     * @return list<array{tenor: float, yield: float}>
     */
    private function sampleCurve(BondPricingEngine $pricingEngine, MacroStateDTO $macro): array
    {
        $curve = $macro->sovereignCurve();

        $points = [];
        foreach (FinancialConstants::BOND_CURVE_SAMPLE_TENORS as $tenor) {
            $points[] = ['tenor' => (float) $tenor, 'yield' => $pricingEngine->zeroYield($curve, (float) $tenor)];
        }

        return $points;
    }

    /**
     * The benchmark points as the macro engine publishes them, for the readout beside the curve.
     *
     * @return list<array{label: string, tenor: float, yield: float}>
     */
    private function benchmarkPoints(MacroStateDTO $macro): array
    {
        return [
            ['label' => '2Y', 'tenor' => 2.0, 'yield' => $macro->yield2y],
            ['label' => '5Y', 'tenor' => 5.0, 'yield' => $macro->yield5y],
            ['label' => '10Y', 'tenor' => 10.0, 'yield' => $macro->yield10y],
            ['label' => '30Y', 'tenor' => 30.0, 'yield' => $macro->yield30y],
        ];
    }
}
