<?php

declare(strict_types=1);

namespace App\Controller;

use App\DTO\MacroStateDTO;
use App\Entity\Bond;
use App\Entity\TradeOrder;
use App\Entity\User;
use App\Entity\UserBond;
use App\Service\Market\BondPricingEngine;
use App\Service\Market\PriceChangeFeed;
use App\Service\Math\FinancialConstants;
use App\Service\User\CostBasisCalculator;
use App\Service\User\CouponIncomeCalculator;
use Doctrine\ORM\EntityManagerInterface;
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
        EntityManagerInterface $entityManager,
        \Redis $redis,
        BondPricingEngine $pricingEngine,
        PriceChangeFeed $priceChangeFeed
    ): Response {
        $macro = $this->readMacroState($redis);

        /** @var list<Bond> $bonds */
        $bonds = $entityManager->getRepository(Bond::class)->findBy(
            ['status' => Bond::STATUS_ACTIVE],
            ['tenorYears' => 'ASC', 'maturesAtTime' => 'ASC']
        );

        $rows = [];
        foreach ($bonds as $bond) {
            $rows[(string) (float) $bond->getTenorYears()][] = [
                'bond' => $bond,
                'yearsToMaturity' => $bond->yearsToMaturity($macro->totalTime),
                'change' => $priceChangeFeed->changeForTicker($bond->getTicker(), (float) $bond->getCleanPrice()),
            ];
        }

        // Every position the signed-in user holds, so the ladder can mark their own line items.
        $holdings = [];
        $user = $this->getUser();
        if ($user instanceof User) {
            foreach ($entityManager->getRepository(UserBond::class)->findBy(['user' => $user]) as $holding) {
                $holdings[$holding->getBond()->getTicker()] = (int) $holding->getQuantity();
            }
        }

        return $this->render('bond/ladder.html.twig', [
            'rowsByTenor' => $rows,
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
        EntityManagerInterface $entityManager,
        \Redis $redis,
        BondPricingEngine $pricingEngine,
        PriceChangeFeed $priceChangeFeed,
        CostBasisCalculator $costBasis,
        CouponIncomeCalculator $couponIncome
    ): Response {
        $bond = $entityManager->getRepository(Bond::class)->findOneBy(['ticker' => $ticker]);
        if (!$bond instanceof Bond) {
            throw $this->createNotFoundException('Issue not found');
        }

        $macro = $this->readMacroState($redis);
        $currentTime = $macro->totalTime;

        $userQuantity = 0;
        $userAvgCost = 0.0;
        $userCouponIncome = 0.0;
        $openOrders = [];

        $user = $this->getUser();
        if ($user instanceof User) {
            $holding = $entityManager->getRepository(UserBond::class)->findOneBy(['user' => $user, 'bond' => $bond]);
            $userQuantity = $holding ? (int) $holding->getQuantity() : 0;

            $filledOrders = $entityManager->createQuery(
                'SELECT o FROM App\Entity\TradeOrder o WHERE o.user = :user AND o.status = :status ORDER BY o.createdAt ASC'
            )->setParameter('user', $user)->setParameter('status', 'FILLED')->getResult();

            $userAvgCost = $costBasis->calculate($filledOrders)[$ticker] ?? 0.0;
            $userCouponIncome = $couponIncome->totalsByTicker($user)[$ticker] ?? 0.0;

            $openOrders = $entityManager->getRepository(TradeOrder::class)->findBy(
                ['user' => $user, 'ticker' => $ticker, 'status' => 'OPEN'],
                ['createdAt' => 'DESC']
            );
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
    public function curve(\Redis $redis, BondPricingEngine $pricingEngine): JsonResponse
    {
        return $this->json($this->sampleCurve($pricingEngine, $this->readMacroState($redis)));
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

    /**
     * The live macro state, or engine defaults when the ticker has never run.
     *
     * A missing key is a fresh database rather than an error: the page still has to render, with a curve
     * built from the engine's own starting factors instead of a flat zero line.
     */
    private function readMacroState(\Redis $redis): MacroStateDTO
    {
        $json = $redis->get('macroeconomic_state');
        $raw = $json ? json_decode($json, true) : null;

        return is_array($raw)
            ? MacroStateDTO::fromArray($raw)
            : MacroStateDTO::fromMacroState(new \App\Service\Macro\MacroState());
    }
}
